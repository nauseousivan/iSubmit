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

$stmt = $pdo->prepare("SELECT * FROM activity_logs WHERE user_id = ? AND status_type = 'info' ORDER BY created_at DESC");
$stmt->execute([$user_id]);
$all_activities = $stmt->fetchAll();

// Calculate Stats
$total = count($all_activities);

function getNotifIcon($status)
{
    if ($status === 'success') {
        return '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="margin-right: 10px;"><polyline points="20 6 9 17 4 12"></polyline></svg>';
    } elseif ($status === 'warning') {
        return '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="margin-right: 10px;"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>';
    } else {
        return '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="margin-right: 10px;"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line></svg>';
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Activity Ledger | MCNP-ISAP</title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap');

        :root {
            --bg-beige: #f9f7f2;
            --bg-white: #ffffff;
            --text-dark: #1f2937;
            --text-muted: #6b7280;
            --mcnp-teal: #0c343d;
            --border-line: #e5e7eb;
            --bubbly-app-edge: 24px;
            --bubbly-ui-edge: 12px;
            --color-approved: #059669;
            --color-revision: #dc2626;
            --color-pending: #9ca3af;
            --ui-sans: 'Inter', system-ui, -apple-system, sans-serif;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Cambria', serif;
            background-color: var(--bg-white);
            color: var(--text-dark);
            height: 100vh;
            display: flex;
            padding: 20px;
            margin: 0;
            overflow: hidden;
        }

        body::-webkit-scrollbar {
            display: none;
        }

        body {
            -ms-overflow-style: none;
            scrollbar-width: none;
        }

        .ledger-frame {
            width: 100%;
            height: 100%;
            display: flex;
            flex-direction: column;
        }

        .header-title {
            font-family: var(--ui-sans);
            font-size: 24px;
            font-weight: 800;
            color: var(--mcnp-teal);
            letter-spacing: -0.025em;
            margin-bottom: 6px;
        }

        .header-desc {
            font-size: 14px;
            color: var(--text-muted);
        }

        .activity-controls {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin: 25px 0;
            gap: 15px;
            flex-wrap: wrap;
        }

        .search-input {
            flex: 1;
            min-width: 250px;
            padding: 12px 18px;
            border-radius: 12px;
            border: 1.5px solid var(--border-line);
            background: var(--bg-beige);
            font-family: var(--ui-sans);
            font-size: 14px;
            outline: none;
            transition: 0.2s;
        }

        .search-input:focus {
            border-color: var(--mcnp-teal);
            background: white;
        }

        .filter-btn-group {
            display: flex;
            gap: 8px;
        }

        .filter-btn {
            font-family: var(--ui-sans);
            padding: 8px 16px;
            border-radius: 20px;
            border: 1px solid var(--border-line);
            background: white;
            cursor: pointer;
            font-size: 12px;
            font-weight: 700;
            transition: 0.2s;
        }

        .filter-btn.active {
            background: var(--mcnp-teal);
            color: white;
            border-color: var(--mcnp-teal);
        }

        .stats-summary-row {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
            margin-bottom: 25px;
        }

        .summary-card {
            padding: 18px;
            border-radius: 16px;
            background: var(--bg-white);
            border: 1px solid var(--border-line);
            text-align: center;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.05);
        }

        .summary-card h4 {
            font-family: var(--ui-sans);
            font-size: 10px;
            text-transform: uppercase;
            font-weight: 800;
            letter-spacing: 0.05em;
            color: var(--text-muted);
            margin-bottom: 5px;
        }

        .summary-card div {
            font-family: var(--ui-sans);
            font-size: 24px;
            font-weight: 800;
            color: var(--mcnp-teal);
        }

        .stream-timeline-container {
            display: flex;
            flex-direction: column;
            gap: 14px;
            flex: 1;
            overflow-y: auto;
            padding-right: 5px;
        }

        .stream-timeline-container::-webkit-scrollbar {
            display: none;
        }

        .activity-card-row {
            padding: 24px;
            border-radius: 16px;
            background-color: var(--bg-white);
            border: 1px solid var(--border-line);
            border-left: 5px solid var(--color-pending);
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .activity-card-row:hover {
            transform: translateX(4px);
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
        }

        .activity-card-row.success {
            border-left-color: var(--color-approved);
            background-color: #f0fdf4;
            border-color: #dcfce7;
        }

        .activity-card-row.warning {
            border-left-color: var(--color-revision);
            background-color: #fff1f2;
            border-color: #fee2e2;
        }

        .activity-card-row h4 {
            font-family: var(--ui-sans);
            font-size: 16px;
            font-weight: 700;
            color: var(--mcnp-teal);
            display: flex;
            align-items: center;
        }

        .activity-card-row p {
            font-size: 14px;
            color: var(--text-dark);
            margin-top: 5px;
            line-height: 1.4;
        }

        .activity-card-row span {
            font-family: var(--ui-sans);
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            color: var(--text-muted);
            display: block;
            margin-top: 12px;
            opacity: 0.7;
        }

        /* THEME CLASSES */
        body.theme-default,
        body.theme-blue {
            --bg-beige: #e8f0ff;
            --bg-white: #ffffff;
            --text-dark: #1c2a44;
            --text-muted: #5f6f8a;
            --border-line: #c6d4e9;
            --mcnp-teal: #4a7c8c;
        }

        body.theme-red {
            --bg-beige: #ffe8e8;
            --bg-white: #ffffff;
            --text-dark: #4c1f20;
            --text-muted: #9d5b5c;
            --border-line: #f2c7c7;
            --mcnp-teal: #d65a5a;
        }

        body.theme-pink,
        body.theme-rose {
            --bg-beige: #fde8f5;
            --bg-white: #ffffff;
            --text-dark: #4c2346;
            --text-muted: #9f628d;
            --border-line: #f3c7dc;
            --mcnp-teal: #c56ba8;
        }

        body.theme-green {
            --bg-beige: #e8f6ea;
            --bg-white: #ffffff;
            --text-dark: #2f4a33;
            --text-muted: #6d8b75;
            --border-line: #c9dec9;
            --mcnp-teal: #4a9e7b;
        }

        body.theme-purple,
        body.theme-lavender {
            --bg-beige: #f5f3ff;
            --bg-white: #ffffff;
            --text-dark: #4c1d95;
            --text-muted: #9c9284;
            --border-line: #ddd6fe;
            --mcnp-teal: #6d28d9;
        }

        body.theme-orange,
        body.theme-amber {
            --bg-beige: #fffbeb;
            --bg-white: #ffffff;
            --text-dark: #78350f;
            --text-muted: #9c9284;
            --border-line: #fde68a;
            --mcnp-teal: #b45309;
        }

        body.theme-dark {
            --bg-beige: #1a1d21;
            --bg-white: #24282d;
            --text-dark: #e0e0e0;
            --text-muted: #b0ada8;
            --border-line: #3a3f45;
            --mcnp-teal: #4e9cae;
        }

        /* MOBILE OPTIMIZATIONS (Only for mobile responsive iframe container) */
        @media (max-width: 640px) {
            body {
                padding: 12px !important;
            }

            .header-title,
            .header-desc {
                display: none !important;
            }

            .activity-controls {
                margin: 10px 0 15px 0 !important;
                gap: 10px !important;
            }

            .search-input {
                min-width: 100% !important;
                padding: 10px 14px !important;
                font-size: 13px !important;
                border-radius: 10px !important;
            }

            .filter-btn-group {
                width: 100% !important;
                display: flex !important;
            }

            .filter-btn {
                flex: 1 !important;
                text-align: center !important;
                padding: 6px 10px !important;
                font-size: 11px !important;
                border-radius: 8px !important;
            }

            .stats-summary-row {
                gap: 10px !important;
                margin-bottom: 15px !important;
            }

            .summary-card {
                padding: 10px !important;
                border-radius: 12px !important;
            }

            .summary-card h4 {
                font-size: 9px !important;
                margin-bottom: 2px !important;
            }

            .summary-card div {
                font-size: 18px !important;
            }

            .activity-card-row {
                padding: 14px 16px !important;
                border-radius: 12px !important;
                margin-bottom: 4px !important;
            }

            .activity-card-row h4 {
                font-size: 14px !important;
            }

            .activity-card-row p {
                font-size: 12px !important;
                line-height: 1.35 !important;
            }

            .activity-card-row span {
                font-size: 9px !important;
                margin-top: 8px !important;
            }
        }
    </style>
</head>

<body>
    <div class="ledger-frame">
        <h2 class="header-title">System Activity Logs</h2>
        <p class="header-desc">Historical trail logs tracking your group account activities at MCNP-ISAP.</p>

        <div class="activity-controls">
            <input type="text" id="actSearch" class="search-input" placeholder="Search" onkeyup="filterActivities()" style="width: 100%;">
        </div>

        <div class="stream-timeline-container">
            <?php if (count($all_activities) > 0): ?>
                <?php foreach ($all_activities as $act): ?>
                    <div class="activity-card-row <?= $act['status_type'] ?>" data-status="<?= $act['status_type'] ?>">
                        <h4><?= getNotifIcon($act['status_type']) . htmlspecialchars($act['title']) ?></h4>
                        <p><?= htmlspecialchars($act['description']) ?></p>
                        <span>Logged at: <?= date('F d, Y - h:i A', strtotime($act['created_at'])) ?></span>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p style="text-align: center; color: var(--text-muted); padding: 40px; font-size:14px;">No system activities logged yet.</p>
            <?php endif; ?>
        </div>
    </div>

    <script>
        function filterActivities() {
            const search = document.getElementById('actSearch').value.toLowerCase();
            const rows = document.querySelectorAll('.activity-card-row');

            rows.forEach(row => {
                const text = row.innerText.toLowerCase();

                const matchesSearch = text.includes(search);

                if (matchesSearch) {
                    row.style.display = 'block';
                } else {
                    row.style.display = 'none';
                }
            });
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