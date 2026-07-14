<?php
// dashboards/calendar_all.php — full events & holidays list (opened from the student calendar "View more")
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once '../config/db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Student') {
    header("Location: ../auth/login.php");
    exit();
}

// Admin/staff-scheduled events (same source the mini-calendar uses)
$cal_stmt = $pdo->query("SELECT title, description, event_date FROM calendar_events ORDER BY event_date ASC");
$calendar_events = $cal_stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Events & Holidays | MCNP-ISAP</title>
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
            --c-holiday: #d97706;
            --c-event: #7c3aed;
            --c-available: #10b981;
            --c-unavailable: #ef4444;
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

        .cal-frame {
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
            gap: 14px;
            margin-bottom: 22px;
            animation: fadeDown 0.4s ease forwards;
        }
        .header-title-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }
        .header-title {
            font-size: 28px;
            font-weight: 800;
            color: var(--text-dark);
            letter-spacing: -0.03em;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .header-title i { width: 26px; height: 26px; color: var(--accent); }
        .count-pill {
            background: var(--chip-bg);
            color: var(--text-muted);
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 6px;
            border: 1px solid var(--border-line);
            white-space: nowrap;
        }
        .header-desc {
            font-size: 14.5px;
            color: var(--text-muted);
            line-height: 1.5;
            max-width: 620px;
        }

        /* SEARCH */
        .search-container { position: relative; margin-bottom: 16px; animation: fadeDown 0.45s ease forwards; }
        .search-icon {
            position: absolute; left: 16px; top: 50%; transform: translateY(-50%);
            color: var(--text-lighter); width: 18px; height: 18px; pointer-events: none; transition: color 0.2s;
        }
        .search-input {
            width: 100%; padding: 13px 16px 13px 44px; border-radius: 999px;
            border: 1px solid var(--border-line); background: var(--bg-white);
            font-family: var(--ui-sans); font-size: 15px; color: var(--text-dark);
            outline: none; transition: all 0.3s cubic-bezier(0.4,0,0.2,1); box-shadow: 0 2px 4px rgba(0,0,0,0.02);
        }
        .search-input::placeholder { color: var(--text-lighter); }
        .search-input:focus { border-color: var(--accent); box-shadow: 0 0 0 4px rgba(124,58,237,0.1); }

        /* FILTER PILLS */
        .filter-row {
            display: flex;
            gap: 8px;
            margin-bottom: 22px;
            overflow-x: auto;
            padding-bottom: 4px;
            animation: fadeDown 0.5s ease forwards;
            scrollbar-width: none;
        }
        .filter-row::-webkit-scrollbar { display: none; }
        .fpill {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 9px 15px;
            border-radius: 999px;
            border: 1px solid var(--border-line);
            background: var(--bg-white);
            color: var(--text-muted);
            font-family: var(--ui-sans);
            font-size: 13.5px;
            font-weight: 650;
            cursor: pointer;
            white-space: nowrap;
            transition: all 0.22s cubic-bezier(0.4,0,0.2,1);
            flex-shrink: 0;
        }
        .fpill:hover { border-color: #cbd5e1; transform: translateY(-1px); }
        .fpill .dot { width: 8px; height: 8px; border-radius: 50%; background: var(--text-lighter); flex-shrink: 0; }
        .fpill .fcount {
            font-family: var(--ui-mono);
            font-size: 11px;
            font-weight: 700;
            background: var(--chip-bg);
            color: var(--text-muted);
            padding: 1px 7px;
            border-radius: 10px;
            border: 1px solid var(--border-light);
        }
        .fpill[data-filter="all"] .dot { background: var(--text-muted); }
        .fpill[data-filter="holiday"] .dot { background: var(--c-holiday); }
        .fpill[data-filter="event"] .dot { background: var(--c-event); }
        .fpill[data-filter="available"] .dot { background: var(--c-available); }
        .fpill[data-filter="unavailable"] .dot { background: var(--c-unavailable); }

        .fpill.active { color: var(--text-dark); border-color: transparent; box-shadow: 0 4px 12px -4px rgba(15,23,42,0.15); }
        .fpill.active[data-filter="all"] { background: #0f172a; color: #fff; }
        .fpill.active[data-filter="all"] .dot { background: #fff; }
        .fpill.active[data-filter="holiday"] { background: #d97706; color: #fff; }
        .fpill.active[data-filter="event"] { background: #7c3aed; color: #fff; }
        .fpill.active[data-filter="available"] { background: #10b981; color: #fff; }
        .fpill.active[data-filter="unavailable"] { background: #ef4444; color: #fff; }
        .fpill.active .dot { background: #fff; }
        .fpill.active .fcount { background: rgba(255,255,255,0.22); color: #fff; border-color: transparent; }

        /* LIST */
        .list-wrapper { flex: 1; overflow-y: auto; padding-right: 12px; padding-bottom: 40px; }

        .month-header {
            font-size: 12px;
            font-weight: 750;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: var(--text-lighter);
            padding: 18px 4px 10px;
            position: sticky;
            top: 0;
            background: linear-gradient(to bottom, var(--bg-white) 60%, transparent);
            z-index: 3;
        }
        .month-header:first-child { padding-top: 2px; }

        .ev-item {
            display: flex;
            gap: 16px;
            align-items: stretch;
            background: var(--bg-white);
            border: 1px solid var(--border-light);
            border-radius: 16px;
            padding: 14px 16px 14px 14px;
            margin-bottom: 10px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.02);
            transition: all 0.2s cubic-bezier(0.4,0,0.2,1);
            border-left: 4px solid var(--border-line);
            opacity: 0;
            animation: fadeUp 0.4s ease forwards;
        }
        .ev-item:hover { box-shadow: 0 10px 20px -6px rgba(0,0,0,0.06); transform: translateY(-2px); border-color: var(--border-line); }
        .ev-item.is-past { opacity: 0.62; }
        .ev-item.cat-holiday { border-left-color: var(--c-holiday); }
        .ev-item.cat-event { border-left-color: var(--c-event); }
        .ev-item.cat-available { border-left-color: var(--c-available); }
        .ev-item.cat-unavailable { border-left-color: var(--c-unavailable); }

        .ev-date {
            flex-shrink: 0;
            width: 56px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            border-radius: 12px;
            background: var(--chip-bg);
            border: 1px solid var(--border-light);
        }
        .ev-day { font-family: var(--ui-mono); font-size: 21px; font-weight: 800; color: var(--text-dark); line-height: 1; }
        .ev-mon { font-size: 10px; font-weight: 750; text-transform: uppercase; letter-spacing: 0.06em; color: var(--text-muted); margin-top: 4px; }
        .ev-item.cat-holiday .ev-date { background: #fffbeb; border-color: #fde68a; }
        .ev-item.cat-holiday .ev-day { color: var(--c-holiday); }
        .ev-item.cat-available .ev-date { background: #ecfdf5; border-color: #a7f3d0; }
        .ev-item.cat-available .ev-day { color: #059669; }
        .ev-item.cat-unavailable .ev-date { background: #fef2f2; border-color: #fecaca; }
        .ev-item.cat-unavailable .ev-day { color: var(--c-unavailable); }
        .ev-item.cat-event .ev-date { background: #f5f3ff; border-color: #ddd6fe; }
        .ev-item.cat-event .ev-day { color: var(--c-event); }

        .ev-body { flex: 1; min-width: 0; display: flex; flex-direction: column; justify-content: center; gap: 5px; }
        .ev-top { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
        .ev-title { font-size: 15px; font-weight: 700; color: var(--text-dark); line-height: 1.35; }
        .ev-tag {
            display: inline-flex; align-items: center; gap: 5px;
            font-size: 11px; font-weight: 700; padding: 3px 9px; border-radius: 20px;
            text-transform: none;
        }
        .ev-tag i { width: 12px; height: 12px; }
        .cat-holiday .ev-tag { background: #fffbeb; color: var(--c-holiday); }
        .cat-event .ev-tag { background: #f5f3ff; color: var(--c-event); }
        .cat-available .ev-tag { background: #ecfdf5; color: #059669; }
        .cat-unavailable .ev-tag { background: #fef2f2; color: var(--c-unavailable); }

        .ev-desc { font-size: 13px; color: var(--text-muted); line-height: 1.5; }
        .ev-meta { font-size: 11.5px; font-weight: 600; color: var(--text-lighter); display: flex; align-items: center; gap: 6px; }
        .ev-meta .today-chip {
            background: var(--accent); color: #fff; font-weight: 700;
            padding: 1px 8px; border-radius: 10px; font-size: 10.5px; letter-spacing: 0.03em;
        }

        .empty-state { text-align: center; padding: 70px 20px; color: var(--text-muted); }
        .empty-state i { width: 46px; height: 46px; color: var(--border-line); margin-bottom: 14px; }
        .empty-state h3 { font-size: 16px; font-weight: 700; color: var(--text-dark); margin-bottom: 6px; }
        .empty-state p { font-size: 13.5px; }

        .loading-note { font-size: 12.5px; color: var(--text-lighter); display: flex; align-items: center; gap: 8px; padding: 4px; margin-bottom: 8px; }
        .loading-note.hide { display: none; }
        .spin { animation: spin 1s linear infinite; width: 14px; height: 14px; }

        @keyframes spin { to { transform: rotate(360deg); } }
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

        body.theme-dark .ev-item.cat-holiday .ev-date { background: rgba(217,119,6,0.14); border-color: rgba(217,119,6,0.35); }
        body.theme-dark .ev-item.cat-available .ev-date { background: rgba(16,185,129,0.14); border-color: rgba(16,185,129,0.35); }
        body.theme-dark .ev-item.cat-unavailable .ev-date { background: rgba(239,68,68,0.14); border-color: rgba(239,68,68,0.35); }
        body.theme-dark .ev-item.cat-event .ev-date { background: rgba(56,189,248,0.14); border-color: rgba(56,189,248,0.35); }
        body.theme-dark .cat-holiday .ev-tag { background: rgba(217,119,6,0.16); }
        body.theme-dark .cat-event .ev-tag { background: rgba(56,189,248,0.16); color: #7dd3fc; }
        body.theme-dark .cat-available .ev-tag { background: rgba(16,185,129,0.16); color: #6ee7b7; }
        body.theme-dark .cat-unavailable .ev-tag { background: rgba(239,68,68,0.16); color: #fca5a5; }

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
                <h1 class="header-title"><i data-lucide="calendar-days"></i>Events &amp; Holidays</h1>
                <div class="count-pill">
                    <i data-lucide="layout-list" style="width:14px;height:14px;"></i>
                    <span id="totalCount">0</span> listed
                </div>
            </div>
            <p class="header-desc">Everything on the research office calendar — office availability, staff-scheduled events, and public holidays. Filter with the pills below.</p>
        </div>

        <div class="search-container">
            <input type="text" id="evSearch" class="search-input" placeholder="Search events or holidays..." autocomplete="off">
            <i data-lucide="search" class="search-icon"></i>
        </div>

        <div class="filter-row" id="filterRow">
            <button class="fpill active" data-filter="all"><span class="dot"></span>All<span class="fcount" data-count="all">0</span></button>
            <button class="fpill" data-filter="holiday"><span class="dot"></span>Holidays<span class="fcount" data-count="holiday">0</span></button>
            <button class="fpill" data-filter="event"><span class="dot"></span>School events<span class="fcount" data-count="event">0</span></button>
            <button class="fpill" data-filter="available"><span class="dot"></span>Available<span class="fcount" data-count="available">0</span></button>
            <button class="fpill" data-filter="unavailable"><span class="dot"></span>Unavailable<span class="fcount" data-count="unavailable">0</span></button>
        </div>

        <div class="list-wrapper">
            <div class="loading-note" id="loadingNote">
                <i data-lucide="loader-circle" class="spin"></i> Loading public holidays…
            </div>
            <div id="listContainer"></div>
            <div class="empty-state" id="emptyState" style="display:none;">
                <i data-lucide="calendar-off"></i>
                <h3>Nothing here</h3>
                <p>No entries match this filter.</p>
            </div>
        </div>
    </div>

    <script>
        lucide.createIcons();

        const dbEvents = <?= json_encode($calendar_events, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?> || [];
        const todayStr = new Date().toISOString().split('T')[0];
        let items = [];
        let activeFilter = 'all';

        const MONTHS = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
        const MONTHS_FULL = ['January','February','March','April','May','June','July','August','September','October','November','December'];
        const WEEKDAYS = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];

        // Same classification the mini-calendar uses: unavailable keywords win over "available".
        function classify(title) {
            const t = (title || '').toLowerCase();
            if (t.includes('not available') || t.includes('quota') || t.includes('closed')) return 'unavailable';
            if (t.includes('available')) return 'available';
            return 'event';
        }

        const TAGS = {
            holiday:    { label: 'Holiday',     icon: 'party-popper' },
            event:      { label: 'School event', icon: 'calendar-plus' },
            available:  { label: 'Available',   icon: 'circle-check' },
            unavailable:{ label: 'Unavailable', icon: 'circle-slash' }
        };

        function buildDbItems() {
            return dbEvents.map(e => {
                const cat = classify(e.title);
                return {
                    date: e.event_date,
                    title: e.title,
                    desc: e.description && e.description.trim() ? e.description : 'Scheduled by the research office.',
                    cat: cat
                };
            });
        }

        async function fetchHolidays(year) {
            try {
                const res = await fetch(`https://date.nager.at/api/v3/PublicHolidays/${year}/PH`);
                if (!res.ok) return [];
                const data = await res.json();
                return data.map(h => ({
                    date: h.date,
                    title: h.localName || h.name,
                    desc: 'Public holiday — the research office is closed.',
                    cat: 'holiday'
                }));
            } catch (e) {
                console.error('Failed to fetch holidays', e);
                return [];
            }
        }

        function updateCounts() {
            const counts = { all: items.length, holiday: 0, event: 0, available: 0, unavailable: 0 };
            items.forEach(i => { counts[i.cat] = (counts[i.cat] || 0) + 1; });
            document.querySelectorAll('.fcount').forEach(el => {
                el.textContent = counts[el.dataset.count] ?? 0;
            });
            document.getElementById('totalCount').textContent = items.length;
        }

        function render() {
            const container = document.getElementById('listContainer');
            const search = (document.getElementById('evSearch').value || '').toLowerCase().trim();
            container.innerHTML = '';

            let visible = items.filter(i => {
                if (activeFilter !== 'all' && i.cat !== activeFilter) return false;
                if (search && !(`${i.title} ${i.desc}`.toLowerCase().includes(search))) return false;
                return true;
            });

            visible.sort((a, b) => a.date < b.date ? -1 : a.date > b.date ? 1 : 0);

            document.getElementById('emptyState').style.display = visible.length ? 'none' : 'block';

            let lastMonthKey = '';
            let delay = 0;
            visible.forEach(i => {
                const d = new Date(i.date + 'T00:00:00');
                const monthKey = `${d.getFullYear()}-${d.getMonth()}`;
                if (monthKey !== lastMonthKey) {
                    lastMonthKey = monthKey;
                    const mh = document.createElement('div');
                    mh.className = 'month-header';
                    mh.textContent = `${MONTHS_FULL[d.getMonth()]} ${d.getFullYear()}`;
                    container.appendChild(mh);
                }

                const isPast = i.date < todayStr;
                const isToday = i.date === todayStr;
                const tag = TAGS[i.cat];

                const el = document.createElement('div');
                el.className = `ev-item cat-${i.cat}${isPast ? ' is-past' : ''}`;
                el.style.animationDelay = `${Math.min(delay, 0.4)}s`;
                delay += 0.03;

                el.innerHTML = `
                    <div class="ev-date">
                        <span class="ev-day">${d.getDate()}</span>
                        <span class="ev-mon">${MONTHS[d.getMonth()]}</span>
                    </div>
                    <div class="ev-body">
                        <div class="ev-top">
                            <span class="ev-title">${escapeHtml(i.title)}</span>
                            <span class="ev-tag"><i data-lucide="${tag.icon}"></i>${tag.label}</span>
                        </div>
                        <p class="ev-desc">${escapeHtml(i.desc)}</p>
                        <div class="ev-meta">
                            ${isToday ? '<span class="today-chip">Today</span>' : ''}
                            ${WEEKDAYS[d.getDay()]} • ${MONTHS[d.getMonth()]} ${d.getDate()}, ${d.getFullYear()}
                        </div>
                    </div>`;
                container.appendChild(el);
            });

            lucide.createIcons();
        }

        function escapeHtml(s) {
            return (s || '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
        }

        // Filter pill wiring
        document.getElementById('filterRow').addEventListener('click', e => {
            const pill = e.target.closest('.fpill');
            if (!pill) return;
            document.querySelectorAll('.fpill').forEach(p => p.classList.remove('active'));
            pill.classList.add('active');
            activeFilter = pill.dataset.filter;
            render();
        });

        document.getElementById('evSearch').addEventListener('input', render);

        // Bootstrap: DB events first (instant), then merge in holidays for this year + next.
        (async function init() {
            items = buildDbItems();
            updateCounts();
            render();

            const y = new Date().getFullYear();
            const [h1, h2] = await Promise.all([fetchHolidays(y), fetchHolidays(y + 1)]);
            items = items.concat(h1, h2);
            document.getElementById('loadingNote').classList.add('hide');
            updateCounts();
            render();
        })();

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
