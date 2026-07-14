<?php
// dashboards/activities_all.php — the student's own action log (self-explanatory activity ledger).
// IMPORTANT: this lists the user's OWN actions only (status_type='info' — uploads, submissions,
// removals, account changes). Review verdicts (Approved/Revision/Payment/etc.) are NOT activities —
// those are notifications and live in the bell drawer. Pills categorise by module, not by verdict.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once '../config/db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Student') {
    header("Location: ../auth/login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

// Bucket each log line into the module it belongs to (proposal/final/stats/plag) or "others"
// (account/profile/password changes and generic uploads). Order matters — most specific first.
function classify_module(string $title): string {
    $t = strtolower($title);
    if (strpos($t, 'plagiar') !== false || strpos($t, 'turnitin') !== false) return 'plag';
    if (strpos($t, 'statist') !== false || strpos($t, 'coded data') !== false || strpos($t, 'rdc form') !== false
        || strpos($t, 'form no. 011') !== false || strpos($t, 'form 011') !== false || strpos($t, 'dataset') !== false
        || strpos($t, 'official receipt') !== false || strpos($t, 'sop') !== false || strpos($t, 'statement of the problem') !== false
        || strpos($t, 'research questionnaire') !== false || strpos($t, 'general statistical') !== false
        || strpos($t, 'minutes of the meeting') !== false || strpos($t, 'datagathering') !== false
        || strpos($t, 'data gathering') !== false) return 'stats';
    if (strpos($t, 'capsule') !== false || strpos($t, 'form no. 008') !== false || strpos($t, 'form 008') !== false
        || strpos($t, 'endorsement') !== false || strpos($t, 'adviser') !== false || strpos($t, 'proposal') !== false) return 'proposal';
    if (strpos($t, 'manuscript') !== false || strpos($t, 'final') !== false || strpos($t, 'communication letter') !== false) return 'final';
    return 'others';
}

$stmt = $pdo->prepare("SELECT title, description, created_at FROM activity_logs WHERE user_id = ? AND status_type = 'info' ORDER BY created_at DESC");
$stmt->execute([$user_id]);
$all_activities = $stmt->fetchAll(PDO::FETCH_ASSOC);

$today = date('Y-m-d');
$items = [];
foreach ($all_activities as $a) {
    $ts = strtotime($a['created_at']);
    $items[] = [
        'title'      => $a['title'],
        'desc'       => ($a['description'] !== null && trim($a['description']) !== '') ? $a['description'] : 'Logged on your research group.',
        'cat'        => classify_module($a['title']),
        'day'        => date('j', $ts),
        'mon'        => strtoupper(date('M', $ts)),
        'monthGroup' => date('F Y', $ts),
        'metaDate'   => date('l • M j, Y', $ts),
        'time'       => date('h:i A', $ts),
        'isToday'    => date('Y-m-d', $ts) === $today,
        'search'     => strtolower($a['title'] . ' ' . $a['description']),
    ];
}
$total = count($items);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Activity Log | MCNP-ISAP</title>
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono:wght@600;700;800&display=swap');

        :root {
            --bg-white: #ffffff;
            --text-dark: #0f172a;
            --text-muted: #64748b;
            --text-lighter: #94a3b8;
            --accent: #7c3aed;
            --border-line: #e2e8f0;
            --border-light: #f1f5f9;
            --chip-bg: #f8fafc;
            --c-proposal: #6366f1;
            --c-final: #0ea5e9;
            --c-stats: #f59e0b;
            --c-plag: #f43f5e;
            --c-others: #64748b;
            --ui-sans: 'Inter', system-ui, -apple-system, sans-serif;
            --ui-mono: 'JetBrains Mono', monospace;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: var(--ui-sans);
            background: transparent;
            color: var(--text-dark);
            height: 100vh;
            display: flex;
            flex-direction: column;
            padding: 32px 40px;
            overflow: hidden;
        }

        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
        ::-webkit-scrollbar-thumb:hover { background: #94a3b8; }

        .cal-frame { width: 100%; height: 100%; display: flex; flex-direction: column; max-width: 900px; margin: 0 auto; }

        /* HEADER */
        .page-header { display: flex; flex-direction: column; gap: 14px; margin-bottom: 22px; animation: fadeDown 0.4s ease forwards; }
        .header-title-row { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
        .header-title { font-size: 28px; font-weight: 800; color: var(--text-dark); letter-spacing: -0.03em; display: flex; align-items: center; gap: 12px; }
        .header-title i { width: 26px; height: 26px; color: var(--accent); }
        .count-pill {
            background: var(--chip-bg); color: var(--text-muted); padding: 6px 12px; border-radius: 20px;
            font-size: 13px; font-weight: 700; display: flex; align-items: center; gap: 6px;
            border: 1px solid var(--border-line); white-space: nowrap;
        }
        .header-desc { font-size: 14.5px; color: var(--text-muted); line-height: 1.5; max-width: 620px; }

        /* SEARCH */
        .search-container { position: relative; margin-bottom: 16px; animation: fadeDown 0.45s ease forwards; }
        .search-icon { position: absolute; left: 16px; top: 50%; transform: translateY(-50%); color: var(--text-lighter); width: 18px; height: 18px; pointer-events: none; transition: color 0.2s; }
        .search-input {
            width: 100%; padding: 13px 16px 13px 44px; border-radius: 999px;
            border: 1px solid var(--border-line); background: var(--bg-white);
            font-family: var(--ui-sans); font-size: 15px; color: var(--text-dark);
            outline: none; transition: all 0.3s cubic-bezier(0.4,0,0.2,1); box-shadow: 0 2px 4px rgba(0,0,0,0.02);
        }
        .search-input::placeholder { color: var(--text-lighter); }
        .search-input:focus { border-color: var(--accent); box-shadow: 0 0 0 4px rgba(124,58,237,0.1); }

        /* FILTER PILLS */
        .filter-row { display: flex; gap: 8px; margin-bottom: 22px; overflow-x: auto; padding-bottom: 4px; animation: fadeDown 0.5s ease forwards; scrollbar-width: none; }
        .filter-row::-webkit-scrollbar { display: none; }
        .fpill {
            display: inline-flex; align-items: center; gap: 7px; padding: 9px 15px; border-radius: 999px;
            border: 1px solid var(--border-line); background: var(--bg-white); color: var(--text-muted);
            font-family: var(--ui-sans); font-size: 13.5px; font-weight: 650; cursor: pointer;
            white-space: nowrap; transition: all 0.22s cubic-bezier(0.4,0,0.2,1); flex-shrink: 0;
        }
        .fpill:hover { border-color: #cbd5e1; transform: translateY(-1px); }
        .fpill .dot { width: 8px; height: 8px; border-radius: 50%; background: var(--text-lighter); flex-shrink: 0; }
        .fpill .fcount {
            font-family: var(--ui-mono); font-size: 11px; font-weight: 700; background: var(--chip-bg);
            color: var(--text-muted); padding: 1px 7px; border-radius: 10px; border: 1px solid var(--border-light);
        }
        .fpill[data-filter="all"] .dot { background: var(--text-muted); }
        .fpill[data-filter="proposal"] .dot { background: var(--c-proposal); }
        .fpill[data-filter="final"] .dot { background: var(--c-final); }
        .fpill[data-filter="stats"] .dot { background: var(--c-stats); }
        .fpill[data-filter="plag"] .dot { background: var(--c-plag); }
        .fpill[data-filter="others"] .dot { background: var(--c-others); }

        .fpill.active { color: var(--text-dark); border-color: transparent; box-shadow: 0 4px 12px -4px rgba(15,23,42,0.15); }
        .fpill.active[data-filter="all"] { background: #0f172a; color: #fff; }
        .fpill.active[data-filter="proposal"] { background: var(--c-proposal); color: #fff; }
        .fpill.active[data-filter="final"] { background: var(--c-final); color: #fff; }
        .fpill.active[data-filter="stats"] { background: var(--c-stats); color: #fff; }
        .fpill.active[data-filter="plag"] { background: var(--c-plag); color: #fff; }
        .fpill.active[data-filter="others"] { background: var(--c-others); color: #fff; }
        .fpill.active .dot { background: #fff; }
        .fpill.active .fcount { background: rgba(255,255,255,0.22); color: #fff; border-color: transparent; }

        /* LIST */
        .list-wrapper { flex: 1; overflow-y: auto; padding-right: 12px; padding-bottom: 40px; }
        .month-header {
            font-size: 12px; font-weight: 750; text-transform: uppercase; letter-spacing: 0.06em;
            color: var(--text-lighter); padding: 18px 4px 10px; position: sticky; top: 0;
            background: linear-gradient(to bottom, var(--bg-white) 60%, transparent); z-index: 3;
        }
        .month-header:first-child { padding-top: 2px; }

        .ev-item {
            display: flex; gap: 16px; align-items: stretch; background: var(--bg-white);
            border: 1px solid var(--border-light); border-radius: 16px; padding: 14px 16px 14px 14px;
            margin-bottom: 10px; box-shadow: 0 1px 3px rgba(0,0,0,0.02);
            transition: all 0.2s cubic-bezier(0.4,0,0.2,1); border-left: 4px solid var(--border-line);
            opacity: 0; animation: fadeUp 0.4s ease forwards;
        }
        .ev-item:hover { box-shadow: 0 10px 20px -6px rgba(0,0,0,0.06); transform: translateY(-2px); border-color: var(--border-line); }
        .ev-item.cat-proposal { border-left-color: var(--c-proposal); }
        .ev-item.cat-final { border-left-color: var(--c-final); }
        .ev-item.cat-stats { border-left-color: var(--c-stats); }
        .ev-item.cat-plag { border-left-color: var(--c-plag); }
        .ev-item.cat-others { border-left-color: var(--c-others); }

        .ev-date {
            flex-shrink: 0; width: 56px; display: flex; flex-direction: column; align-items: center;
            justify-content: center; border-radius: 12px; background: var(--chip-bg); border: 1px solid var(--border-light);
        }
        .ev-day { font-family: var(--ui-mono); font-size: 21px; font-weight: 800; color: var(--text-dark); line-height: 1; }
        .ev-mon { font-size: 10px; font-weight: 750; text-transform: uppercase; letter-spacing: 0.06em; color: var(--text-muted); margin-top: 4px; }
        .ev-item.cat-proposal .ev-date { background: #eef2ff; border-color: #c7d2fe; }
        .ev-item.cat-proposal .ev-day { color: var(--c-proposal); }
        .ev-item.cat-final .ev-date { background: #f0f9ff; border-color: #bae6fd; }
        .ev-item.cat-final .ev-day { color: #0284c7; }
        .ev-item.cat-stats .ev-date { background: #fffbeb; border-color: #fde68a; }
        .ev-item.cat-stats .ev-day { color: #d97706; }
        .ev-item.cat-plag .ev-date { background: #fff1f2; border-color: #fecdd3; }
        .ev-item.cat-plag .ev-day { color: var(--c-plag); }
        .ev-item.cat-others .ev-date { background: #f8fafc; border-color: #e2e8f0; }
        .ev-item.cat-others .ev-day { color: var(--c-others); }

        .ev-body { flex: 1; min-width: 0; display: flex; flex-direction: column; justify-content: center; gap: 5px; }
        .ev-top { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
        .ev-title { font-size: 15px; font-weight: 700; color: var(--text-dark); line-height: 1.35; }
        .ev-tag { display: inline-flex; align-items: center; gap: 5px; font-size: 11px; font-weight: 700; padding: 3px 9px; border-radius: 20px; }
        .ev-tag i { width: 12px; height: 12px; }
        .cat-proposal .ev-tag { background: #eef2ff; color: var(--c-proposal); }
        .cat-final .ev-tag { background: #f0f9ff; color: #0284c7; }
        .cat-stats .ev-tag { background: #fffbeb; color: #d97706; }
        .cat-plag .ev-tag { background: #fff1f2; color: var(--c-plag); }
        .cat-others .ev-tag { background: #f1f5f9; color: var(--c-others); }

        .ev-desc { font-size: 13px; color: var(--text-muted); line-height: 1.5; }
        .ev-meta { font-size: 11.5px; font-weight: 600; color: var(--text-lighter); display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
        .ev-meta .today-chip { background: var(--accent); color: #fff; font-weight: 700; padding: 1px 8px; border-radius: 10px; font-size: 10.5px; letter-spacing: 0.03em; }
        .ev-meta .time-sep { opacity: 0.5; }

        .empty-state { text-align: center; padding: 70px 20px; color: var(--text-muted); }
        .empty-state i { width: 46px; height: 46px; color: var(--border-line); margin-bottom: 14px; }
        .empty-state h3 { font-size: 16px; font-weight: 700; color: var(--text-dark); margin-bottom: 6px; }
        .empty-state p { font-size: 13.5px; }

        @keyframes fadeDown { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }
        @keyframes fadeUp { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }

        /* THEME CLASSES (mirrors sibling sub-pages) */
        body.theme-default, body.theme-blue { --bg-white: #ffffff; --text-dark: #0f172a; --text-muted: #64748b; --border-line: #e2e8f0; --border-light: #f1f5f9; --chip-bg: #f8fafc; --accent: #1e40af; }
        body.theme-red { --bg-white: #ffffff; --text-dark: #7f1d1d; --text-muted: #991b1b; --border-line: #fecaca; --border-light: #fee2e2; --chip-bg: #fef2f2; --accent: #dc2626; }
        body.theme-dark { --bg-white: #151c24; --text-dark: #f8fafc; --text-muted: #94a3b8; --text-lighter: #64748b; --border-line: #334155; --border-light: #202b36; --chip-bg: #1b232d; --accent: #38bdf8; }
        body.theme-pink, body.theme-rose { --bg-white: #ffffff; --text-dark: #831843; --text-muted: #9d174d; --border-line: #fbcfe8; --border-light: #fce7f3; --chip-bg: #fdf2f8; --accent: #db2777; }
        body.theme-green { --bg-white: #ffffff; --text-dark: #14532d; --text-muted: #166534; --border-line: #bbf7d0; --border-light: #dcfce7; --chip-bg: #f0fdf4; --accent: #16a34a; }
        body.theme-purple, body.theme-lavender { --bg-white: #ffffff; --text-dark: #4c1d95; --text-muted: #6d28d9; --border-line: #ddd6fe; --border-light: #ede9fe; --chip-bg: #f5f3ff; --accent: #7c3aed; }
        body.theme-orange, body.theme-amber { --bg-white: #ffffff; --text-dark: #78350f; --text-muted: #92400e; --border-line: #fde68a; --border-light: #fef3c7; --chip-bg: #fffbeb; --accent: #d97706; }

        body.theme-dark .ev-item.cat-proposal .ev-date { background: rgba(99,102,241,0.16); border-color: rgba(99,102,241,0.35); }
        body.theme-dark .ev-item.cat-proposal .ev-day { color: #a5b4fc; }
        body.theme-dark .ev-item.cat-final .ev-date { background: rgba(14,165,233,0.16); border-color: rgba(14,165,233,0.35); }
        body.theme-dark .ev-item.cat-final .ev-day { color: #7dd3fc; }
        body.theme-dark .ev-item.cat-stats .ev-date { background: rgba(245,158,11,0.16); border-color: rgba(245,158,11,0.35); }
        body.theme-dark .ev-item.cat-stats .ev-day { color: #fcd34d; }
        body.theme-dark .ev-item.cat-plag .ev-date { background: rgba(244,63,94,0.16); border-color: rgba(244,63,94,0.35); }
        body.theme-dark .ev-item.cat-plag .ev-day { color: #fda4af; }
        body.theme-dark .ev-item.cat-others .ev-date { background: rgba(148,163,184,0.16); border-color: rgba(148,163,184,0.32); }
        body.theme-dark .ev-item.cat-others .ev-day { color: #cbd5e1; }
        body.theme-dark .cat-proposal .ev-tag { background: rgba(99,102,241,0.18); color: #a5b4fc; }
        body.theme-dark .cat-final .ev-tag { background: rgba(14,165,233,0.18); color: #7dd3fc; }
        body.theme-dark .cat-stats .ev-tag { background: rgba(245,158,11,0.18); color: #fcd34d; }
        body.theme-dark .cat-plag .ev-tag { background: rgba(244,63,94,0.18); color: #fda4af; }
        body.theme-dark .cat-others .ev-tag { background: rgba(148,163,184,0.18); color: #cbd5e1; }

        @media (max-width: 768px) {
            body { padding: 22px 16px; }
            .header-title { font-size: 23px; }
            .ev-date { width: 48px; }
            .ev-day { font-size: 18px; }
        }
    </style>
</head>

<body>
    <div class="cal-frame">
        <div class="page-header">
            <div class="header-title-row">
                <h1 class="header-title"><i data-lucide="history"></i>My Activity Log</h1>
                <div class="count-pill">
                    <i data-lucide="activity" style="width:14px;height:14px;"></i>
                    <span id="totalCount"><?= $total ?></span> logged
                </div>
            </div>
            <p class="header-desc">A record of everything <strong>you've</strong> done — files submitted, uploaded, or removed, and account changes. Approvals and reviews live in your notifications. Filter by module below.</p>
        </div>

        <div class="search-container">
            <input type="text" id="evSearch" class="search-input" placeholder="Search your activity..." autocomplete="off">
            <i data-lucide="search" class="search-icon"></i>
        </div>

        <div class="filter-row" id="filterRow">
            <button class="fpill active" data-filter="all"><span class="dot"></span>All<span class="fcount" data-count="all">0</span></button>
            <button class="fpill" data-filter="proposal"><span class="dot"></span>Proposal<span class="fcount" data-count="proposal">0</span></button>
            <button class="fpill" data-filter="final"><span class="dot"></span>Final<span class="fcount" data-count="final">0</span></button>
            <button class="fpill" data-filter="stats"><span class="dot"></span>Statistics<span class="fcount" data-count="stats">0</span></button>
            <button class="fpill" data-filter="plag"><span class="dot"></span>Plagiarism<span class="fcount" data-count="plag">0</span></button>
            <button class="fpill" data-filter="others"><span class="dot"></span>Others<span class="fcount" data-count="others">0</span></button>
        </div>

        <div class="list-wrapper">
            <div id="listContainer"></div>
            <div class="empty-state" id="emptyState" style="display:none;">
                <i data-lucide="inbox"></i>
                <h3>Nothing here</h3>
                <p>No activity in this module yet.</p>
            </div>
        </div>
    </div>

    <script>
        lucide.createIcons();

        const items = <?= json_encode($items, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?> || [];
        let activeFilter = 'all';

        const TAGS = {
            proposal: { label: 'Proposal',   icon: 'file-text' },
            final:    { label: 'Final',       icon: 'file-check' },
            stats:    { label: 'Statistics',  icon: 'sigma' },
            plag:     { label: 'Plagiarism',  icon: 'shield-check' },
            others:   { label: 'Others',      icon: 'settings-2' }
        };

        function updateCounts() {
            const counts = { all: items.length, proposal: 0, final: 0, stats: 0, plag: 0, others: 0 };
            items.forEach(i => { counts[i.cat] = (counts[i.cat] || 0) + 1; });
            document.querySelectorAll('.fcount').forEach(el => { el.textContent = counts[el.dataset.count] ?? 0; });
        }

        function render() {
            const container = document.getElementById('listContainer');
            const search = (document.getElementById('evSearch').value || '').toLowerCase().trim();
            container.innerHTML = '';

            const visible = items.filter(i => {
                if (activeFilter !== 'all' && i.cat !== activeFilter) return false;
                if (search && !i.search.includes(search)) return false;
                return true;
            });

            document.getElementById('emptyState').style.display = visible.length ? 'none' : 'block';

            let lastMonth = '';
            let delay = 0;
            visible.forEach(i => {
                if (i.monthGroup !== lastMonth) {
                    lastMonth = i.monthGroup;
                    const mh = document.createElement('div');
                    mh.className = 'month-header';
                    mh.textContent = i.monthGroup;
                    container.appendChild(mh);
                }
                const tag = TAGS[i.cat] || TAGS.others;
                const el = document.createElement('div');
                el.className = `ev-item cat-${i.cat}`;
                el.style.animationDelay = `${Math.min(delay, 0.4)}s`;
                delay += 0.03;
                el.innerHTML = `
                    <div class="ev-date">
                        <span class="ev-day">${i.day}</span>
                        <span class="ev-mon">${i.mon}</span>
                    </div>
                    <div class="ev-body">
                        <div class="ev-top">
                            <span class="ev-title">${escapeHtml(i.title)}</span>
                            <span class="ev-tag"><i data-lucide="${tag.icon}"></i>${tag.label}</span>
                        </div>
                        <p class="ev-desc">${escapeHtml(i.desc)}</p>
                        <div class="ev-meta">
                            ${i.isToday ? '<span class="today-chip">Today</span>' : ''}
                            ${escapeHtml(i.metaDate)}<span class="time-sep">•</span>${escapeHtml(i.time)}
                        </div>
                    </div>`;
                container.appendChild(el);
            });
            lucide.createIcons();
        }

        function escapeHtml(s) {
            return (s || '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
        }

        document.getElementById('filterRow').addEventListener('click', e => {
            const pill = e.target.closest('.fpill');
            if (!pill) return;
            document.querySelectorAll('.fpill').forEach(p => p.classList.remove('active'));
            pill.classList.add('active');
            activeFilter = pill.dataset.filter;
            render();
        });
        document.getElementById('evSearch').addEventListener('input', render);

        updateCounts();
        render();

        // Sync theme from the parent portal (same handshake the other sub-pages use)
        const syncTheme = () => {
            const saved = localStorage.getItem('rd-portal-theme') || 'theme-purple';
            document.body.className = saved;
        };
        syncTheme();
        window.addEventListener('storage', syncTheme);
        setInterval(() => {
            try {
                if (window.parent && window.parent.document && window.parent.document.body) {
                    const pTheme = window.parent.document.body.className.replace('zoom-active', '').trim();
                    if (pTheme && pTheme !== document.body.className) document.body.className = pTheme;
                }
            } catch (e) {}
        }, 500);
    </script>
</body>
</html>
