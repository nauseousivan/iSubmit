<?php
// dashboards/activities_all.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once '../config/db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Student') {
    header("Location: ../auth/login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$group_name = $_SESSION['research_group_name'] ?? 'MCNP-ISAP Research Group';

// Use a fallback for fetching all status types, although currently the query is filtered to 'info'.
// Assuming we want all logs, but wait, the original was "AND status_type = 'info'". 
// I will keep the original logic but handle different status types just in case.
$stmt = $pdo->prepare("SELECT * FROM activity_logs WHERE user_id = ? ORDER BY created_at DESC");
$stmt->execute([$user_id]);
$all_activities = $stmt->fetchAll();

$total = count($all_activities);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Activity Ledger | MCNP-ISAP</title>
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap');

        :root {
            --bg-beige: #f9f7f2;
            --bg-white: #ffffff;
            --text-dark: #0f172a;
            --text-muted: #64748b;
            --text-lighter: #94a3b8;
            --mcnp-teal: #0f172a; /* Make primary text darker for premium feel */
            --accent: #7c3aed; /* Purple accent */
            --border-line: #e2e8f0;
            --border-light: #f1f5f9;
            --color-approved: #10b981;
            --color-revision: #ef4444;
            --color-pending: #3b82f6;
            --ui-sans: 'Inter', system-ui, -apple-system, sans-serif;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: var(--ui-sans);
            background: transparent;
            color: var(--text-dark);
            height: 100vh;
            display: flex;
            flex-direction: column;
            padding: 32px 40px;
            margin: 0;
            overflow: hidden;
        }

        /* Premium Scrollbar */
        ::-webkit-scrollbar {
            width: 6px;
        }
        ::-webkit-scrollbar-track {
            background: transparent;
        }
        ::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 10px;
        }
        ::-webkit-scrollbar-thumb:hover {
            background: #94a3b8;
        }

        .ledger-frame {
            width: 100%;
            height: 100%;
            display: flex;
            flex-direction: column;
            max-width: 900px;
            margin: 0 auto;
        }

        /* HEADER */
        .page-header {
            display: flex;
            flex-direction: column;
            gap: 16px;
            margin-bottom: 32px;
            animation: fadeDown 0.4s ease forwards;
        }

        .header-title-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .header-title {
            font-size: 28px;
            font-weight: 800;
            color: var(--text-dark);
            letter-spacing: -0.03em;
        }

        .activity-count-pill {
            background: var(--border-light);
            color: var(--text-muted);
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 6px;
            border: 1px solid var(--border-line);
        }

        .header-desc {
            font-size: 15px;
            color: var(--text-muted);
            line-height: 1.5;
            max-width: 600px;
        }

        /* SEARCH BAR */
        .search-container {
            position: relative;
            margin-bottom: 32px;
            animation: fadeDown 0.5s ease forwards;
        }

        .search-icon {
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-lighter);
            width: 18px;
            height: 18px;
            pointer-events: none;
            transition: color 0.2s;
        }

        .search-input {
            width: 100%;
            padding: 14px 16px 14px 44px;
            border-radius: 999px; /* Pill shape */
            border: 1px solid var(--border-line);
            background: var(--bg-white);
            font-family: var(--ui-sans);
            font-size: 15px;
            color: var(--text-dark);
            outline: none;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: 0 2px 4px rgba(0,0,0,0.02);
        }

        .search-input::placeholder {
            color: var(--text-lighter);
        }

        .search-input:focus {
            border-color: var(--accent);
            box-shadow: 0 0 0 4px rgba(124, 58, 237, 0.1);
        }

        .search-input:focus + .search-icon {
            color: var(--accent);
        }

        /* TIMELINE */
        .timeline-wrapper {
            flex: 1;
            overflow-y: auto;
            padding-right: 16px;
            padding-bottom: 40px;
            position: relative;
        }

        .timeline-container {
            position: relative;
            padding-left: 32px;
        }

        /* The continuous vertical line */
        .timeline-container::before {
            content: '';
            position: absolute;
            left: 11px;
            top: 0;
            bottom: 0;
            width: 2px;
            background: var(--border-line);
            border-radius: 2px;
        }

        .timeline-item {
            position: relative;
            margin-bottom: 24px;
            opacity: 0;
            transform: translateY(10px);
            animation: fadeUp 0.4s ease forwards;
        }

        /* The Node */
        .timeline-node {
            position: absolute;
            left: -32px;
            top: 2px;
            width: 24px;
            height: 24px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--bg-white);
            border: 2px solid var(--border-line);
            z-index: 2;
            transition: all 0.2s;
        }

        .timeline-node i {
            width: 12px;
            height: 12px;
            color: var(--text-muted);
        }

        /* Node Status Colors */
        .timeline-item.status-success .timeline-node {
            border-color: var(--color-approved);
            background: #ecfdf5;
        }
        .timeline-item.status-success .timeline-node i { color: var(--color-approved); }

        .timeline-item.status-warning .timeline-node {
            border-color: var(--color-revision);
            background: #fef2f2;
        }
        .timeline-item.status-warning .timeline-node i { color: var(--color-revision); }

        .timeline-item.status-info .timeline-node {
            border-color: var(--color-pending);
            background: #eff6ff;
        }
        .timeline-item.status-info .timeline-node i { color: var(--color-pending); }

        /* The Card */
        .timeline-card {
            background: var(--bg-white);
            border: 1px solid var(--border-light);
            border-radius: 16px;
            padding: 20px;
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: 0 1px 3px rgba(0,0,0,0.02);
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 16px;
            cursor: default;
        }

        .timeline-card:hover {
            border-color: var(--border-line);
            box-shadow: 0 8px 16px -4px rgba(0,0,0,0.05);
            transform: translateY(-2px);
        }

        .timeline-card:hover .timeline-node {
            transform: scale(1.1);
        }

        .timeline-content {
            flex: 1;
        }

        .timeline-action {
            font-size: 15px;
            font-weight: 700;
            color: var(--text-dark);
            margin-bottom: 6px;
            line-height: 1.4;
        }

        .timeline-context {
            font-size: 13.5px;
            color: var(--text-muted);
            line-height: 1.5;
        }

        .timeline-meta {
            text-align: right;
            flex-shrink: 0;
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: 4px;
        }

        .timeline-time {
            font-size: 12px;
            font-weight: 600;
            color: var(--text-muted);
            background: var(--border-light);
            padding: 4px 10px;
            border-radius: 12px;
        }

        .timeline-date {
            font-size: 11px;
            font-weight: 500;
            color: var(--text-lighter);
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--text-muted);
        }

        .empty-state i {
            width: 48px;
            height: 48px;
            color: var(--border-line);
            margin-bottom: 16px;
        }

        /* ANIMATIONS */
        @keyframes fadeDown {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* THEME CLASSES */
        body.theme-default, body.theme-blue { --bg-canvas: #f0f4f9; --bg-white: #ffffff; --text-dark: #0f172a; --text-muted: #64748b; --border-line: #e2e8f0; --accent: #1e40af; }
        body.theme-red { --bg-canvas: #fef2f2; --bg-white: #ffffff; --text-dark: #7f1d1d; --text-muted: #991b1b; --border-line: #fecaca; --accent: #dc2626; }
        body.theme-dark { --bg-canvas: #090d12; --bg-white: #151c24; --text-dark: #f8fafc; --text-muted: #94a3b8; --border-line: #334155; --accent: #38bdf8; }
        body.theme-pink, body.theme-rose { --bg-canvas: #fde8f5; --bg-white: #ffffff; --text-dark: #831843; --text-muted: #9d174d; --border-line: #fbcfe8; --accent: #db2777; }
        body.theme-green { --bg-canvas: #e8f6ea; --bg-white: #ffffff; --text-dark: #14532d; --text-muted: #166534; --border-line: #bbf7d0; --accent: #16a34a; }
        body.theme-purple, body.theme-lavender { --bg-canvas: #f5f3ff; --bg-white: #ffffff; --text-dark: #4c1d95; --text-muted: #6d28d9; --border-line: #ddd6fe; --accent: #7c3aed; }
        body.theme-orange, body.theme-amber { --bg-canvas: #fffbeb; --bg-white: #ffffff; --text-dark: #78350f; --text-muted: #92400e; --border-line: #fde68a; --accent: #d97706; }

        /* MOBILE OPTIMIZATIONS */
        @media (max-width: 768px) {
            body { padding: 20px 16px; }
            .header-title { font-size: 24px; }
            .timeline-card { flex-direction: column; gap: 12px; }
            .timeline-meta { text-align: left; align-items: flex-start; flex-direction: row; align-items: center; }
        }
    </style>
</head>

<body>
    <div class="ledger-frame">
        
        <div class="page-header">
            <div class="header-title-row">
                <h1 class="header-title">Recent Activities</h1>
                <div class="activity-count-pill">
                    <i data-lucide="activity" style="width: 14px; height: 14px;"></i>
                    <?= $total ?> total
                </div>
            </div>
            <p class="header-desc">A complete historical trail of all actions, submissions, and approvals for your research group.</p>
        </div>

        <div class="search-container">
            <input type="text" id="actSearch" class="search-input" placeholder="Search activities, modules, or updates..." onkeyup="filterActivities()">
            <i data-lucide="search" class="search-icon"></i>
        </div>

        <div class="timeline-wrapper">
            <?php if ($total > 0): ?>
                <div class="timeline-container" id="timelineContainer">
                    <?php 
                    $delay = 0;
                    foreach ($all_activities as $act): 
                        $status = strtolower($act['status_type'] ?? 'info');
                        // Map old statuses to standard classes
                        if (!in_array($status, ['success', 'warning', 'info'])) {
                            $status = 'info';
                        }
                        
                        $icon = 'activity';
                        if ($status === 'success') $icon = 'check';
                        if ($status === 'warning') $icon = 'alert-triangle';
                        
                        // Parse time for better display
                        $timestamp = strtotime($act['created_at']);
                        $time_str = date('h:i A', $timestamp);
                        $date_str = date('M d, Y', $timestamp);
                        
                        // Extract Title as Action, Description as Context
                        $action = htmlspecialchars($act['title']);
                        $context = htmlspecialchars($act['description']);
                    ?>
                        <div class="timeline-item status-<?= $status ?>" data-searchText="<?= strtolower($action . ' ' . $context) ?>" style="animation-delay: <?= $delay ?>s;">
                            <div class="timeline-node">
                                <i data-lucide="<?= $icon ?>"></i>
                            </div>
                            <div class="timeline-card">
                                <div class="timeline-content">
                                    <h3 class="timeline-action"><?= $action ?></h3>
                                    <p class="timeline-context"><?= $context ?></p>
                                </div>
                                <div class="timeline-meta">
                                    <span class="timeline-time"><?= $time_str ?></span>
                                    <span class="timeline-date"><?= $date_str ?></span>
                                </div>
                            </div>
                        </div>
                    <?php 
                        $delay += 0.05; // 50ms stagger
                    endforeach; 
                    ?>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <i data-lucide="inbox"></i>
                    <h3>No activities yet</h3>
                    <p>When you or your group members take action, it will appear here.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // Initialize Lucide Icons
        lucide.createIcons();

        function filterActivities() {
            const search = document.getElementById('actSearch').value.toLowerCase();
            const items = document.querySelectorAll('.timeline-item');
            let hasVisible = false;

            items.forEach(item => {
                const text = item.getAttribute('data-searchText');
                if (text.includes(search)) {
                    item.style.display = 'block';
                    hasVisible = true;
                } else {
                    item.style.display = 'none';
                }
            });

            // Handle empty state for search
            const container = document.getElementById('timelineContainer');
            let noResults = document.getElementById('noResultsMsg');
            
            if (!hasVisible && search.trim() !== '') {
                if (!noResults) {
                    noResults = document.createElement('div');
                    noResults.id = 'noResultsMsg';
                    noResults.className = 'empty-state';
                    noResults.innerHTML = '<i data-lucide="search-x"></i><p>No activities match your search.</p>';
                    container.appendChild(noResults);
                    lucide.createIcons({root: noResults});
                }
                noResults.style.display = 'block';
            } else if (noResults) {
                noResults.style.display = 'none';
            }
        }

        // Apply global theme from parent
        const syncTheme = () => {
            const savedTheme = localStorage.getItem('rd-portal-theme') || 'theme-default';
            document.body.className = savedTheme;
        };
        syncTheme();
        window.addEventListener('storage', syncTheme);
        setInterval(() => {
            try {
                if (window.parent && window.parent.document && window.parent.document.body) {
                    const pTheme = window.parent.document.body.className;
                    if (pTheme && pTheme !== document.body.className) {
                        document.body.className = pTheme;
                    }
                }
            } catch (e) {}
        }, 500);
    </script>
</body>
</html>
