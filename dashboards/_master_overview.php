<?php
/**
 * Shared "Master Overview" dashboard partial (Director-style).
 *
 * Included by coordinator.php so the Coordinator home mirrors the Director's master dashboard.
 * Self-contained: computes its own data (needs $pdo in scope). Reuses the global $message if present.
 * NOTE: placeholder parity between Coordinator and Director — to be unified/refactored later.
 */

// --- Data (namespaced with mo_ so it never collides with the host page's variables) ---
$mo_workflow_tracks = $pdo->query("
    SELECT ap.approval_id, ap.coordinator_status, ap.statistician_status, ap.director_status, ap.payment_status, ap.printing_enabled,
           u.research_group_name, f.form_name, u.user_id, u.username, u.email, u.program, u.department, u.profile_pic
    FROM approvals ap
    JOIN users u ON ap.user_id = u.user_id
    JOIN forms f ON ap.form_id = f.form_id
")->fetchAll();

$mo_college_counts = $pdo->query("
    SELECT CASE WHEN u.department LIKE '%Medical Colleges%' THEN 'MCNP'
                WHEN u.department LIKE '%International School%' THEN 'ISAP' ELSE 'Other' END as college,
           'Proposal' as item_type, COUNT(DISTINCT up.upload_id) as pending_count
    FROM uploads up JOIN users u ON up.user_id = u.user_id
    WHERE up.verification_status IN ('Pending','Under Review') AND up.item_id = 14
    GROUP BY college
    UNION ALL
    SELECT CASE WHEN u.department LIKE '%Medical Colleges%' THEN 'MCNP'
                WHEN u.department LIKE '%International School%' THEN 'ISAP' ELSE 'Other' END as college,
           'Data/Literature' as item_type, COUNT(DISTINCT up.upload_id) as pending_count
    FROM uploads up JOIN users u ON up.user_id = u.user_id
    WHERE up.verification_status IN ('Pending','Under Review') AND up.item_id IN (3,4)
    GROUP BY college
")->fetchAll();

$mo_counts_by_college = ['ISAP' => ['Proposal' => 0, 'Data/Literature' => 0], 'MCNP' => ['Proposal' => 0, 'Data/Literature' => 0]];
foreach ($mo_college_counts as $cc) {
    $col = $cc['college'] ?? 'ISAP';
    if (!isset($mo_counts_by_college[$col])) $mo_counts_by_college[$col] = ['Proposal' => 0, 'Data/Literature' => 0];
    $mo_counts_by_college[$col][$cc['item_type']] = $cc['pending_count'];
}

$mo_recent_activities = $pdo->query("
    SELECT al.title, al.description, al.status_type, al.created_at, u.username, u.research_group_name
    FROM activity_logs al JOIN users u ON al.user_id = u.user_id
    ORDER BY al.created_at DESC LIMIT 60
")->fetchAll();

$mo_message = $message ?? '';
?>
<div class="container" id="masterDashboard">
    <div class="header">
        <div class="header-title">
            <h1>Research Coordinator Terminal</h1>
            <p>Overview of all research stages, payment validations, and recent achievements.</p>
        </div>
        <div class="clock-widget">
            <i data-lucide="clock"></i>
            <span id="directorTimeClock">loading...</span>
        </div>
    </div>

    <?php if (!empty($mo_message)): ?>
        <div class="alert-success">
            <i data-lucide="check-circle" style="vertical-align: middle; margin-right: 6px;"></i>
            <?= htmlspecialchars($mo_message) ?>
        </div>
    <?php endif; ?>

    <!-- Stats Grid -->
    <div class="dashboard-grid">
        <div class="stat-card">
            <div class="stat-value"><?= count($mo_workflow_tracks) ?></div>
            <div class="stat-label">Research Groups</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?= array_sum($mo_counts_by_college['ISAP']) ?></div>
            <div class="stat-label">ISAP Pending Tasks</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?= array_sum($mo_counts_by_college['MCNP']) ?></div>
            <div class="stat-label">MCNP Pending Tasks</div>
        </div>
    </div>

    <!-- Interactive Student/Group Selector -->
    <div class="selector-section">
        <div class="selector-header" style="display: flex; gap: 12px; align-items: center; justify-content: space-between; border-bottom: 1.5px solid var(--border-line); padding-bottom: 12px; margin-bottom: 18px; flex-wrap: wrap;">
            <h3 style="margin-bottom: 0;">Interactive Student/Group Explorer</h3>
            <div class="custom-dropdown-container" style="position: relative; width: 100%; max-width: 320px; z-index: 120;">
                <button type="button" id="customDropdownTrigger" class="custom-dropdown-trigger" style="display: flex; justify-content: space-between; align-items: center; width: 100%; padding: 10px 14px; border-radius: 10px; border: 1.5px solid var(--border-line); font-size: 13px; font-family: inherit; background: #faf9f6; color: var(--text-dark); cursor: pointer; text-align: left; transition: all 0.2s; outline: none; font-weight: 600; box-sizing: border-box;">
                    <span id="customDropdownSelectedText">-- Select Student Group --</span>
                    <i data-lucide="chevron-down" style="width: 15px; height: 15px; color: var(--text-muted); margin-left: 8px;"></i>
                </button>
                <div id="customDropdownMenu" class="custom-dropdown-menu" style="display: none; position: absolute; top: calc(100% + 6px); left: 0; right: 0; background: var(--bg-white, #ffffff); border: 1.5px solid var(--border-line); border-radius: var(--control-radius); box-shadow: 0 10px 25px rgba(0,0,0,0.08); overflow: hidden;">
                    <div style="padding: 8px; border-bottom: 1px solid var(--border-line); background: #faf8f4; display: flex; align-items: center; gap: 8px;">
                        <i data-lucide="search" style="color: #9ca3af; width: 13px; height: 13px; flex-shrink: 0;"></i>
                        <input type="text" id="customDropdownSearch" placeholder="Type to filter..." style="border: none; background: transparent; outline: none; font-size: 11.5px; font-family: inherit; font-weight: 600; width: 100%; padding: 0; color: var(--text-dark);" oninput="filterCustomDropdownOptions()">
                    </div>
                    <div id="customDropdownOptionsList" style="max-height: 200px; overflow-y: auto; display: flex; flex-direction: column;">
                        <?php foreach ($mo_workflow_tracks as $group): ?>
                            <div class="custom-dropdown-item"
                                 onclick='selectCustomDropdownOption(<?= htmlspecialchars(json_encode($group), ENT_QUOTES, "UTF-8") ?>)'
                                 data-search-term="<?= htmlspecialchars(strtolower($group['research_group_name'] . ' ' . $group['username'] . ' ' . $group['program'] . ' ' . $group['department'])) ?>"
                                 style="padding: 10px 14px; font-size: 12.5px; font-weight: 600; color: var(--text-dark); cursor: pointer; transition: background 0.2s; border-bottom: 1px solid rgba(0,0,0,0.03);"
                                 onmouseover="this.style.backgroundColor='#faf8f4'" onmouseout="this.style.backgroundColor='transparent'">
                                <?= htmlspecialchars($group['research_group_name']) ?> <span style="font-weight: normal; color: var(--text-muted); font-size: 11px;">(<?= htmlspecialchars($group['username']) ?>)</span>
                            </div>
                        <?php endforeach; ?>
                        <div id="customDropdownNoResults" style="display: none; padding: 12px; font-size: 11.5px; color: var(--text-muted); text-align: center;">No groups found</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Selected Group Profile Display (hidden until a group is chosen) -->
        <div class="selected-group-profile-card" id="selectedGroupProfile" style="display:none;">
            <div style="display:flex; justify-content:space-between; align-items:start;">
                <div style="display:flex; gap:16px;">
                    <img id="groupPfp" src="" class="profile-pfp">
                    <div>
                        <h4 id="groupName" style="color:var(--mcnp-teal); font-family:'Cinzel', serif; font-size:16px;">Group Name</h4>
                        <p id="groupLeader" style="font-weight:600; color:#4b5563; font-size:12.5px; margin-top:2px;">Leader: </p>
                        <p id="groupMail" style="font-family:'JetBrains Mono', monospace; font-size:11.5px; color:#6b7280;"></p>
                        <p id="groupDetails" style="font-size:11px; color:#9ca3af; margin-top:2px;"></p>
                    </div>
                </div>
                <div style="text-align:right;">
                    <span style="font-size:10px; font-weight:800; color:#4a453e; text-transform:uppercase;">Administrative Milestones</span>
                    <div style="display:flex; flex-direction:column; gap:6px; margin-top:6px; align-items:flex-end;">
                        <div style="font-size:12px;"><span style="color:#6b7280;">Coordinator:</span> <strong id="progCoord">Pending</strong></div>
                        <div style="font-size:12px;"><span style="color:#6b7280;">Statistician:</span> <strong id="progStats">Pending</strong></div>
                        <div style="font-size:12px;"><span style="color:#6b7280;">Payment Status:</span> <strong id="progPay">Unpaid</strong></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Recent Activities -->
    <div class="activity-feed">
        <h3 style="font-family:'Cinzel', serif; color:var(--mcnp-teal); font-size:15px; margin-bottom:12px;"><i data-lucide="activity"></i> Recent Activity Logs</h3>
        <?php if (count($mo_recent_activities) === 0): ?>
            <p style="text-align: center; color: var(--text-muted); padding: 30px;">No recent submissions yet.</p>
        <?php else: ?>
            <div id="activityLogsList">
                <?php $mo_i = 0; foreach ($mo_recent_activities as $activity):
                    if ($mo_i >= 5) break;
                    $icon_class = $activity['status_type'] === 'Approved' ? 'success' : ($activity['status_type'] === 'Revision Requested' ? 'warning' : 'info');
                    $mo_i++;
                ?>
                <div class="activity-item" onclick="openOverlay('admin_module_dynamic.php?phase=proposal', document.querySelector('.nav-item-btn[onclick*=\'phase=proposal\']'))">
                    <div class="activity-icon <?= $icon_class ?>"><?= $icon_class === 'success' ? '✓' : ($icon_class === 'warning' ? '!' : '📄') ?></div>
                    <div class="activity-content">
                        <div class="activity-title">New Submission: <?= htmlspecialchars($activity['title']) ?></div>
                        <div class="activity-desc">Status: <?= htmlspecialchars($activity['status_type']) ?></div>
                        <div class="activity-time">📦 <?= htmlspecialchars($activity['research_group_name']) ?> • <?= date('M d, Y @ h:i A', strtotime($activity['created_at'])) ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php if (count($mo_recent_activities) > 5): ?>
                <div style="text-align: center; margin-top: 16px;">
                    <button type="button" onclick="openActivityLogsModal()" style="background: #faf8f4; border: 1.5px solid var(--border-line); padding: 8px 18px; border-radius: 8px; font-family: var(--ui-sans); font-size: 12.5px; font-weight: 700; color: var(--mcnp-teal); cursor: pointer; display: inline-flex; align-items: center; gap: 6px;">
                        <i data-lucide="history" style="width: 15px; height: 15px;"></i> See All Activity Logs (<?= count($mo_recent_activities) ?>)
                    </button>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <!-- ACTIVITY LOGS MODAL -->
    <div id="activityLogsModal" class="fullscreen-modal" style="display: none; position: fixed; inset: 0; background: rgba(12,52,61,0.45); backdrop-filter: blur(8px); z-index: 200; align-items: center; justify-content: center; padding: 20px; box-sizing: border-box;">
        <div style="background: var(--bg-white,#ffffff); width: 100%; max-width: 750px; border-radius: var(--card-radius); border: 2px solid var(--border-line); box-shadow: 0 20px 50px rgba(0,0,0,0.15); display: flex; flex-direction: column; max-height: 90vh; overflow: hidden;">
            <div style="padding: 20px 24px; border-bottom: 2.5px solid var(--border-line); display: flex; justify-content: space-between; align-items: center; background: #faf8f4; flex-shrink: 0;">
                <div style="display: flex; align-items: center; gap: 10px;">
                    <div style="background: var(--mcnp-teal); color: white; width: 36px; height: 36px; border-radius: 50%; display: flex; align-items: center; justify-content: center;">
                        <i data-lucide="history" style="width: 18px; height: 18px;"></i>
                    </div>
                    <div>
                        <h3 style="font-family: 'Cinzel', serif; color: var(--mcnp-teal); font-size: 18px; margin: 0; font-weight: 800;">Comprehensive Activity Logs</h3>
                        <p style="color: var(--text-muted); font-size: 11.5px; margin: 0;">Institutional Submission &amp; Review Logs Pipeline</p>
                    </div>
                </div>
                <button type="button" onclick="closeActivityLogsModal()" style="background: transparent; border: none; cursor: pointer; color: var(--text-muted); padding: 6px;">
                    <i data-lucide="x" style="width: 22px; height: 22px;"></i>
                </button>
            </div>
            <div style="padding: 16px 24px; background: #fdfdfd; border-bottom: 1.5px solid var(--border-line); display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px; box-sizing: border-box; flex-shrink: 0;">
                <div style="display: flex; flex-direction: column; gap: 4px;">
                    <label style="font-size: 10px; font-weight: 800; color: var(--text-muted); text-transform: uppercase;">Search Logs</label>
                    <div style="position: relative; display: flex; align-items: center; background: #faf8f4; border: 1.5px solid var(--border-line); border-radius: 8px; padding: 6px 10px;">
                        <i data-lucide="search" style="color: #9ca3af; width: 12px; height: 12px; margin-right: 6px;"></i>
                        <input type="text" id="modalLogSearch" placeholder="Filter groups / titles..." onkeyup="filterModalLogs()" style="background: transparent; border: none; outline: none; font-size: 11.5px; font-weight: 600; width: 100%; padding: 0; color: var(--text-dark); font-family: var(--ui-sans);">
                    </div>
                </div>
                <div style="display: flex; flex-direction: column; gap: 4px;">
                    <label style="font-size: 10px; font-weight: 800; color: var(--text-muted); text-transform: uppercase;">Filter Status</label>
                    <select id="modalLogStatusFilter" onchange="filterModalLogs()" style="padding: 6px 10px; border-radius: 8px; border: 1.5px solid var(--border-line); font-size: 11.5px; font-weight: 600; outline: none; background: #faf9f6;">
                        <option value="all">All Statuses</option>
                        <option value="Approved">Approved / Success</option>
                        <option value="Revision Requested">Revision Requested / Warnings</option>
                        <option value="other">Pending / Info / Reviews</option>
                    </select>
                </div>
                <div style="display: flex; flex-direction: column; gap: 4px;">
                    <label style="font-size: 10px; font-weight: 800; color: var(--text-muted); text-transform: uppercase;">Filter File / Form Stage</label>
                    <select id="modalLogFormFilter" onchange="filterModalLogs()" style="padding: 6px 10px; border-radius: 8px; border: 1.5px solid var(--border-line); font-size: 11.5px; font-weight: 600; outline: none; background: #faf9f6;">
                        <option value="all">All Stages</option>
                        <option value="capsule">Capsule Proposal (Form No. 008)</option>
                        <option value="final">Final Defense / Form 5</option>
                        <option value="plagiarism">Plagiarism Verification</option>
                        <option value="endorsement">Institutional Endorsement</option>
                        <option value="general">Other General Milestones</option>
                    </select>
                </div>
            </div>
            <div id="modalLogsContainer" style="padding: 16px 24px; overflow-y: auto; flex: 1; display: flex; flex-direction: column; gap: 12px; background: #faf9f6;"></div>
            <div style="padding: 14px 24px; border-top: 1.5px solid var(--border-line); display: flex; justify-content: space-between; align-items: center; background: #faf8f4; flex-shrink: 0;">
                <span style="font-size: 11.5px; color: var(--text-muted); font-weight: 600;" id="modalLogCount">Showing 0 of 0 logs</span>
                <button type="button" onclick="closeActivityLogsModal()" style="padding: 8px 16px; font-size: 11.5px; border: 1.5px solid var(--border-line); background: #fff; border-radius: 8px; cursor: pointer; font-weight: 700; color: var(--mcnp-teal);">Close Portal</button>
            </div>
        </div>
    </div>
</div>

<style>
    /* Master overview partial: classes coordinator.php doesn't already define */
    .dashboard-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 18px; margin-bottom: 28px; }
    .stat-card { background: var(--bg-white); border-radius: var(--card-radius); padding: 24px; box-shadow: 0 8px 25px rgba(12,52,61,0.03); border-left: 5px solid var(--mcnp-teal); border: 1.5px solid var(--border-line); position: relative; overflow: hidden; }
    .stat-card::after { content: ''; position: absolute; bottom: 0; right: 0; width: 40px; height: 40px; background: linear-gradient(135deg, transparent 40%, rgba(204,153,0,0.1) 100%); }
    .stat-value { font-size: 32px; font-weight: 800; color: var(--mcnp-teal); margin-bottom: 4px; font-family: 'Cinzel', serif; }
    .stat-label { font-size: 12.5px; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.8px; font-weight: bold; }
    .alert-success { background: #ecfdf5; color: #136643; padding: 16px 20px; border-radius: 14px; margin-bottom: 24px; font-weight: 700; border-left: 5px solid var(--success, #059669); }
    .activity-feed { background: var(--bg-white); border-radius: var(--card-radius); padding: 28px; border: 1.5px solid var(--border-line); box-shadow: 0 10px 30px rgba(0,0,0,0.02); margin-bottom: 28px; }
    .activity-item { display: flex; gap: 14px; padding: 14px; border-bottom: 1.5px solid var(--border-line); cursor: pointer; transition: 0.2s; }
    .activity-item:hover { background: #faf8f4; border-radius: var(--control-radius); }
    .activity-item:last-child { border-bottom: none; }
    .activity-icon { width: 36px; height: 36px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: bold; flex-shrink: 0; }
    .activity-icon.success { background: #d1fae5; color: #059669; }
    .activity-icon.warning { background: #fef3c7; color: #d97706; }
    .activity-icon.info { background: #dbeafe; color: #2563eb; }
    .activity-content { flex: 1; }
    .activity-title { font-weight: bold; color: var(--text-dark); font-size: 13px; }
    .activity-desc { color: var(--text-muted); font-size: 12px; margin-top: 2px; }
    .activity-time { color: var(--text-muted); font-size: 11px; margin-top: 4px; }
    .badge-unpaid { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }
</style>

<script>
    (function () {
        // Clock for the master overview header
        function updateMoClock() {
            var el = document.getElementById('directorTimeClock');
            if (el) el.innerHTML = new Date().toLocaleString("en-US", { timeZone: "Asia/Manila", hour: '2-digit', minute: '2-digit', second: '2-digit' });
        }
        setInterval(updateMoClock, 1000); updateMoClock();

        // Custom dropdown open/close
        var trigger = document.getElementById('customDropdownTrigger');
        var menu = document.getElementById('customDropdownMenu');
        var search = document.getElementById('customDropdownSearch');
        if (trigger && menu) {
            trigger.addEventListener('click', function (e) {
                e.stopPropagation();
                var isOpen = menu.style.display === 'block';
                menu.style.display = isOpen ? 'none' : 'block';
                if (!isOpen && search) { search.value = ''; window.filterCustomDropdownOptions(); setTimeout(function () { search.focus(); }, 50); }
            });
            document.addEventListener('click', function (e) {
                if (!menu.contains(e.target) && !trigger.contains(e.target)) menu.style.display = 'none';
            });
        }

        window.filterCustomDropdownOptions = function () {
            var q = (document.getElementById('customDropdownSearch').value || '').toLowerCase().trim();
            var items = document.querySelectorAll('#customDropdownOptionsList .custom-dropdown-item');
            var has = false;
            items.forEach(function (item) {
                var t = item.getAttribute('data-search-term') || '';
                if (t.includes(q)) { item.style.display = 'block'; has = true; } else { item.style.display = 'none'; }
            });
            var nr = document.getElementById('customDropdownNoResults');
            if (nr) nr.style.display = has ? 'none' : 'block';
        };

        window.selectCustomDropdownOption = function (data) {
            var textEl = document.getElementById('customDropdownSelectedText');
            if (textEl) textEl.textContent = data.research_group_name;
            if (menu) menu.style.display = 'none';
            var card = document.getElementById('selectedGroupProfile');
            if (!card) return;
            card.style.display = 'block';
            document.getElementById('groupPfp').src = data.profile_pic || 'https://api.dicebear.com/9.x/bottts/svg?seed=' + encodeURIComponent(data.username);
            document.getElementById('groupName').textContent = data.research_group_name;
            document.getElementById('groupLeader').innerHTML = 'Leader: <strong style="color:var(--mcnp-teal);">' + data.username + '</strong>';
            document.getElementById('groupMail').innerHTML = '<i data-lucide="mail" style="width:12px; height:12px; display:inline-block; vertical-align:middle; margin-right:4px;"></i> ' + data.email;
            document.getElementById('groupDetails').textContent = data.program + ' • ' + data.department;
            document.getElementById('progCoord').textContent = data.coordinator_status;
            document.getElementById('progCoord').className = 'badge-status ' + (data.coordinator_status === 'Approved' ? 'badge-paid' : 'badge-pending');
            document.getElementById('progStats').textContent = data.statistician_status;
            document.getElementById('progStats').className = 'badge-status ' + (data.statistician_status === 'Approved' ? 'badge-paid' : 'badge-pending');
            document.getElementById('progPay').textContent = data.payment_status;
            document.getElementById('progPay').className = 'badge-status ' + (data.payment_status === 'Paid' ? 'badge-paid' : 'badge-unpaid');
            if (window.lucide) lucide.createIcons();
        };

        // Activity logs modal
        var allRecentActivities = <?= json_encode($mo_recent_activities) ?>;

        function escapeHtml(t) { if (!t) return ''; return t.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;"); }
        function formatLogDate(s) { if (!s) return ''; var d = new Date(s); return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) + ' @ ' + d.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' }); }
        function getFormCategory(title, desc) {
            var text = (title + ' ' + desc).toLowerCase();
            if (text.includes('capsule') || text.includes('proposal') || text.includes('008')) return 'capsule';
            if (text.includes('final') || text.includes('defense') || text.includes('award') || text.includes('milestone')) return 'final';
            if (text.includes('plagiarism') || text.includes('plag')) return 'plagiarism';
            if (text.includes('endorse')) return 'endorsement';
            return 'general';
        }

        window.openActivityLogsModal = function () {
            var m = document.getElementById('activityLogsModal');
            if (!m) return;
            m.style.display = 'flex';
            document.getElementById('modalLogSearch').value = '';
            document.getElementById('modalLogStatusFilter').value = 'all';
            document.getElementById('modalLogFormFilter').value = 'all';
            window.filterModalLogs();
        };
        window.closeActivityLogsModal = function () {
            var m = document.getElementById('activityLogsModal');
            if (m) m.style.display = 'none';
        };
        window.filterModalLogs = function () {
            var searchVal = document.getElementById('modalLogSearch').value.toLowerCase().trim();
            var statusVal = document.getElementById('modalLogStatusFilter').value;
            var formVal = document.getElementById('modalLogFormFilter').value;
            var container = document.getElementById('modalLogsContainer');
            if (!container) return;
            container.innerHTML = '';
            var matched = 0;
            allRecentActivities.forEach(function (a) {
                var title = a.title || '', desc = a.description || '', st = a.status_type || '', grp = a.research_group_name || '', usr = a.username || '';
                var category = getFormCategory(title, desc);
                var ms = title.toLowerCase().includes(searchVal) || desc.toLowerCase().includes(searchVal) || grp.toLowerCase().includes(searchVal) || usr.toLowerCase().includes(searchVal);
                var mst = true;
                if (statusVal === 'Approved') mst = (st === 'Approved');
                else if (statusVal === 'Revision Requested') mst = (st === 'Revision Requested');
                else if (statusVal === 'other') mst = (st !== 'Approved' && st !== 'Revision Requested');
                var mf = (formVal === 'all' || category === formVal);
                if (ms && mst && mf) {
                    matched++;
                    var ic = st === 'Approved' ? 'success' : (st === 'Revision Requested' ? 'warning' : 'info');
                    container.insertAdjacentHTML('beforeend',
                        '<div class="activity-item" style="cursor:default; margin-bottom:0;">' +
                        '<div class="activity-icon ' + ic + '">' + (ic === 'success' ? '✓' : (ic === 'warning' ? '!' : '📄')) + '</div>' +
                        '<div class="activity-content" style="flex:1;">' +
                        '<div style="display:flex; justify-content:space-between; flex-wrap:wrap; gap:8px;">' +
                        '<div class="activity-title">New Submission: ' + escapeHtml(title) + '</div></div>' +
                        '<div class="activity-desc">' + escapeHtml(desc) + '</div>' +
                        '<div class="activity-time">📦 ' + escapeHtml(grp) + ' (' + escapeHtml(usr) + ') • ' + formatLogDate(a.created_at) + '</div>' +
                        '</div></div>');
                }
            });
            document.getElementById('modalLogCount').textContent = 'Showing ' + matched + ' of ' + allRecentActivities.length + ' logs';
            if (window.lucide) lucide.createIcons();
            if (matched === 0) container.innerHTML = '<div style="text-align:center; color:var(--text-muted); padding:40px; font-weight:600;">No matching activity logs found.</div>';
        };
    })();
</script>
