<?php
/**
 * Shared "Master Overview" dashboard partial (Director-style).
 *
 * Included by coordinator.php so the Coordinator home mirrors the Director's master dashboard.
 * Self-contained: computes its own data (needs $pdo in scope). Reuses the global $message if present.
 * NOTE: placeholder parity between Coordinator and Director — to be unified/refactored later.
 */

// --- Data (namespaced with mo_ so it never collides with the host page's variables) ---
$mo_role = $_SESSION['role'] ?? '';
$mo_is_statistician = ($mo_role === 'Statistician');

$mo_role_titles = [
    'Research Coordinator' => 'Research Coordinator Terminal',
    'Research Director'    => 'Research Director Terminal',
    'Statistician'         => 'Research Statistician Terminal',
];
$mo_terminal_title = $mo_role_titles[$mo_role] ?? 'Staff Terminal';

// Group explorer + Research Groups count now source from the FULL student-leader group
// population (the same set the approval module's sidebar lists), LEFT JOINed to each group's
// latest approval row so groups that have uploaded but have no approvals record STILL appear.
// (Previously this read only from `approvals`, so 48 of 52 groups — incl. 12 with real
// uploads — were invisible in the explorer and undercounted the stat card.)
$mo_workflow_tracks = $pdo->query("
    SELECT u.user_id, u.username, u.research_group_name, u.department, u.program, u.profile_pic, u.email,
           COALESCE(ap.coordinator_status, 'Pending')  AS coordinator_status,
           COALESCE(ap.statistician_status, 'Pending') AS statistician_status,
           COALESCE(ap.director_status, 'Pending')     AS director_status,
           COALESCE(ap.payment_status, 'Unpaid')       AS payment_status,
           la.last_activity
    FROM users u
    LEFT JOIN approvals ap ON ap.approval_id = (SELECT MAX(a2.approval_id) FROM approvals a2 WHERE a2.user_id = u.user_id)
    LEFT JOIN (
        SELECT user_id, MAX(created_at) AS last_activity FROM activity_logs GROUP BY user_id
    ) la ON la.user_id = u.user_id
    WHERE u.role = 'Student' AND u.research_group_name IS NOT NULL AND u.research_group_name <> '' AND u.leader_id IS NULL
    ORDER BY (la.last_activity IS NULL) ASC, la.last_activity DESC, u.research_group_name ASC
")->fetchAll();

$mo_research_groups_count = count($mo_workflow_tracks);

// Role-aware "needs your action" count: latest upload per group/item, in the verification
// status THIS role acts on (Director acts on 'Under Review'; Coordinator/Statistician on 'Pending').
$mo_action_status = ($mo_role === 'Research Director') ? 'Under Review' : 'Pending';
if ($mo_is_statistician) {
    // Reuse statistician.php's already-deduped queue counts (defined before this include).
    $mo_action_needed = (int) ($stats_checklist_pending ?? 0) + (int) ($stats_payment_pending ?? 0) + (int) ($stats_release_pending ?? 0);
    $mo_approved_clearances = (int) $pdo->query("SELECT COUNT(*) FROM approvals WHERE statistician_status = 'Approved'")->fetchColumn();
} else {
    $mo_act = $pdo->prepare("
        SELECT COUNT(*) FROM uploads up
        INNER JOIN (SELECT user_id, item_id, MAX(uploaded_at) AS md FROM uploads GROUP BY user_id, item_id) l
          ON up.user_id = l.user_id AND up.item_id = l.item_id AND up.uploaded_at = l.md
        WHERE up.verification_status = ?");
    $mo_act->execute([$mo_action_status]);
    $mo_action_needed = (int) $mo_act->fetchColumn();
    $mo_approved_docs = (int) $pdo->query("
        SELECT COUNT(*) FROM uploads up
        INNER JOIN (SELECT user_id, item_id, MAX(uploaded_at) AS md FROM uploads GROUP BY user_id, item_id) l
          ON up.user_id = l.user_id AND up.item_id = l.item_id AND up.uploaded_at = l.md
        WHERE up.verification_status = 'Approved'")->fetchColumn();
}

// Primary review target for the action banner / activity click-through (role-aware).
$mo_primary_url = $mo_is_statistician ? 'admin_module_dynamic.php?phase=stats&view=checklist' : 'admin_module_dynamic.php?phase=proposal';
$mo_primary_selector = $mo_is_statistician ? 'phase=stats' : 'phase=proposal';

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
            <h1><?= htmlspecialchars($mo_terminal_title) ?></h1>
            <p>Overview of research stages, workflow status, and recent activity.</p>
        </div>
        <div class="clock-card">
            <span class="clock-live-dot"></span>
            <div>
                <div class="clock-time" id="directorTimeClock">--:--:--</div>
                <div class="clock-date" id="directorClockDate">&nbsp;</div>
            </div>
        </div>
    </div>

    <?php if (!empty($mo_message)): ?>
        <div class="alert-success">
            <i data-lucide="check-circle" style="vertical-align: middle; margin-right: 6px;"></i>
            <?= htmlspecialchars($mo_message) ?>
        </div>
    <?php endif; ?>

    <!-- Action-first summary -->
    <div class="action-banner">
        <div class="action-banner-icon"><i data-lucide="inbox"></i></div>
        <div>
            <div class="action-banner-count"><?= (int) $mo_action_needed ?></div>
            <div class="action-banner-text">
                <?php if ((int) $mo_action_needed > 0): ?>
                    submission<?= $mo_action_needed == 1 ? '' : 's' ?> waiting for your review
                <?php else: ?>
                    You're all caught up — nothing needs your review right now.
                <?php endif; ?>
            </div>
        </div>
        <?php if ((int) $mo_action_needed > 0): ?>
            <button class="btn btn-primary" onclick="openOverlay('<?= $mo_primary_url ?>', document.querySelector('.nav-item-btn[onclick*=\'<?= $mo_primary_selector ?>\']'))">
                <i data-lucide="arrow-right" style="width:16px;height:16px;"></i> Review now
            </button>
        <?php endif; ?>
    </div>

    <!-- Compact stat widgets -->
    <div class="dashboard-grid">
        <div class="stat-card">
            <div class="stat-value"><?= (int) $mo_research_groups_count ?></div>
            <div class="stat-label">Research Groups</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?= (int) $mo_action_needed ?></div>
            <div class="stat-label"><?= $mo_is_statistician ? 'Pending Validations' : 'Needs Action' ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?= $mo_is_statistician ? (int) ($mo_approved_clearances ?? 0) : (int) ($mo_approved_docs ?? 0) ?></div>
            <div class="stat-label"><?= $mo_is_statistician ? 'Approved Clearances' : 'Approved Documents' ?></div>
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
                        <?php $mo_gi = 0; foreach ($mo_workflow_tracks as $group): $mo_gi++; $mo_extra = $mo_gi > 4; ?>
                            <div class="custom-dropdown-item" <?= $mo_extra ? 'data-extra="1"' : '' ?>
                                 onclick='selectCustomDropdownOption(<?= htmlspecialchars(json_encode($group), ENT_QUOTES, "UTF-8") ?>)'
                                 data-search-term="<?= htmlspecialchars(strtolower($group['research_group_name'] . ' ' . $group['username'] . ' ' . $group['program'] . ' ' . $group['department'])) ?>"
                                 style="padding: 10px 14px; font-size: 12.5px; font-weight: 600; color: var(--text-dark); cursor: pointer; transition: background 0.2s; border-bottom: 1px solid rgba(0,0,0,0.03);<?= $mo_extra ? ' display:none;' : '' ?>"
                                 onmouseover="this.style.backgroundColor='#faf8f4'" onmouseout="this.style.backgroundColor='transparent'">
                                <?= htmlspecialchars($group['research_group_name']) ?> <span style="font-weight: normal; color: var(--text-muted); font-size: 11px;">(<?= htmlspecialchars($group['username']) ?>)</span>
                            </div>
                        <?php endforeach; ?>
                        <div id="customDropdownNoResults" style="display: none; padding: 12px; font-size: 11.5px; color: var(--text-muted); text-align: center;">No groups found</div>
                        <?php if (count($mo_workflow_tracks) > 4): ?>
                            <button type="button" id="moShowMoreGroups" onclick="window.toggleMoShowMore()" style="width:100%; padding:8px; font-size:11px; font-weight:700; color:var(--mcnp-teal); background:#faf8f4; border:none; border-top:1px solid var(--border-line); cursor:pointer;">
                                Show all <?= count($mo_workflow_tracks) ?> groups
                            </button>
                        <?php endif; ?>
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
                        <h4 id="groupName" style="color:var(--ink); font-size:16px; font-weight:700;">Group Name</h4>
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
    <?php
    // Statistician has no "phase=proposal" nav button (locked to phase=stats), so the click-through
    // target must be role-aware or querySelector returns null and openOverlay() throws on click.
    $mo_activity_click_url = $mo_is_statistician ? 'admin_module_dynamic.php?phase=stats&view=checklist' : 'admin_module_dynamic.php?phase=proposal';
    $mo_activity_click_selector = $mo_is_statistician ? 'phase=stats' : 'phase=proposal';
    ?>
    <div class="activity-feed">
        <div class="feed-head">
            <h3><i data-lucide="activity"></i> Recent Activity</h3>
            <?php if (count($mo_recent_activities) > 5): ?>
                <button type="button" class="feed-seeall" onclick="openActivityLogsModal()">
                    <i data-lucide="list"></i> See all (<?= count($mo_recent_activities) ?>)
                </button>
            <?php endif; ?>
        </div>
        <?php if (count($mo_recent_activities) === 0): ?>
            <p style="text-align: center; color: var(--muted); padding: 30px;">No recent activity yet.</p>
        <?php else: ?>
            <div id="activityLogsList">
                <?php $mo_i = 0; foreach ($mo_recent_activities as $activity):
                    if ($mo_i >= 5) break;
                    // status_type in the logs is a mix of 'success'/'warning'/'info' and
                    // 'Approved'/'Revision Requested' — normalise so the colour is always right.
                    $st = strtolower(trim($activity['status_type'] ?? ''));
                    if (in_array($st, ['approved', 'success'], true)) { $ac = 'success'; $ai = 'circle-check'; $al = 'Approved'; }
                    elseif (in_array($st, ['revision requested', 'warning'], true)) { $ac = 'warning'; $ai = 'rotate-ccw'; $al = 'Revision'; }
                    else { $ac = 'info'; $ai = 'file-text'; $al = 'Update'; }
                    $mo_i++;
                ?>
                <div class="activity-item" onclick="openOverlay('<?= $mo_activity_click_url ?>', document.querySelector('.nav-item-btn[onclick*=\'<?= $mo_activity_click_selector ?>\']'))">
                    <div class="activity-icon <?= $ac ?>"><i data-lucide="<?= $ai ?>"></i></div>
                    <div class="activity-content">
                        <div class="activity-title"><?= htmlspecialchars($activity['title']) ?></div>
                        <div class="activity-meta"><i data-lucide="users"></i> <?= htmlspecialchars($activity['research_group_name']) ?> &middot; <?= date('M d, Y \a\t g:i A', strtotime($activity['created_at'])) ?></div>
                    </div>
                    <span class="activity-chip <?= $ac ?>"><?= $al ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- ACTIVITY LOGS MODAL -->
    <div id="activityLogsModal" class="fullscreen-modal" style="display: none; position: fixed; inset: 0; background: rgba(20,18,15,0.5); z-index: 200; align-items: center; justify-content: center; padding: 20px; box-sizing: border-box;">
        <div style="background: var(--bg-white,#ffffff); width: 100%; max-width: 750px; border-radius: var(--card-radius); border: 2px solid var(--border-line); box-shadow: 0 20px 50px rgba(0,0,0,0.15); display: flex; flex-direction: column; max-height: 90vh; overflow: hidden;">
            <div style="padding: 20px 24px; border-bottom: 2.5px solid var(--border-line); display: flex; justify-content: space-between; align-items: center; background: #faf8f4; flex-shrink: 0;">
                <div style="display: flex; align-items: center; gap: 10px;">
                    <div style="background: var(--mcnp-teal); color: white; width: 36px; height: 36px; border-radius: 50%; display: flex; align-items: center; justify-content: center;">
                        <i data-lucide="history" style="width: 18px; height: 18px;"></i>
                    </div>
                    <div>
                        <h3 style="color: var(--ink); font-size: 18px; margin: 0; font-weight: 800; letter-spacing:-.01em;">Comprehensive Activity Logs</h3>
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

<!-- Styles for these classes (dashboard-grid, stat-card, activity-feed, badges, etc.)
     now live in the shared assets/css/portal.css design system. -->
<style>
    /* Group-explorer custom dropdown — theme via design-system tokens (light + dark) */
    #customDropdownTrigger { background: var(--surface-2) !important; color: var(--ink) !important; border-color: var(--line) !important; }
    #customDropdownTrigger:hover, #customDropdownTrigger:focus { border-color: var(--accent) !important; box-shadow: 0 0 0 3px var(--accent-ghost); }
    .custom-dropdown-menu { background: var(--surface) !important; border-color: var(--line) !important; box-shadow: var(--shadow-3) !important; }
    #customDropdownMenu > div:first-child { background: var(--surface-2) !important; border-color: var(--line) !important; }
    .custom-dropdown-item { color: var(--ink) !important; border-color: var(--line-soft) !important; }
    .custom-dropdown-item:hover { background: var(--surface-2) !important; }
    #moShowMoreGroups { background: var(--surface-2) !important; color: var(--accent) !important; border-color: var(--line) !important; }
</style>

<script>
    (function () {
        // Clock widget for the master overview header (time + date, PH time)
        function updateMoClock() {
            var now = new Date();
            var t = document.getElementById('directorTimeClock');
            if (t) t.textContent = now.toLocaleString("en-US", { timeZone: "Asia/Manila", hour: '2-digit', minute: '2-digit', second: '2-digit' });
            var d = document.getElementById('directorClockDate');
            if (d) d.textContent = now.toLocaleString("en-US", { timeZone: "Asia/Manila", weekday: 'short', month: 'short', day: 'numeric' });
        }
        setInterval(updateMoClock, 1000); updateMoClock();

        // Custom dropdown open/close
        var trigger = document.getElementById('customDropdownTrigger');
        var menu = document.getElementById('customDropdownMenu');
        var search = document.getElementById('customDropdownSearch');
        var moGroupsExpanded = false;
        var moTotalGroups = <?= (int) count($mo_workflow_tracks) ?>;

        window.toggleMoShowMore = function () {
            moGroupsExpanded = !moGroupsExpanded;
            document.querySelectorAll('#customDropdownOptionsList [data-extra="1"]').forEach(function (el) {
                el.style.display = moGroupsExpanded ? 'block' : 'none';
            });
            var btn = document.getElementById('moShowMoreGroups');
            if (btn) btn.textContent = moGroupsExpanded ? 'Show fewer groups' : ('Show all ' + moTotalGroups + ' groups');
        };

        if (trigger && menu) {
            trigger.addEventListener('click', function (e) {
                e.stopPropagation();
                var isOpen = menu.style.display === 'block';
                menu.style.display = isOpen ? 'none' : 'block';
                if (!isOpen) {
                    moGroupsExpanded = false;
                    document.querySelectorAll('#customDropdownOptionsList [data-extra="1"]').forEach(function (el) { el.style.display = 'none'; });
                    var btn = document.getElementById('moShowMoreGroups');
                    if (btn) { btn.textContent = 'Show all ' + moTotalGroups + ' groups'; btn.style.display = 'block'; }
                    if (search) { search.value = ''; window.filterCustomDropdownOptions(); setTimeout(function () { search.focus(); }, 50); }
                }
            });
            document.addEventListener('click', function (e) {
                if (!menu.contains(e.target) && !trigger.contains(e.target)) menu.style.display = 'none';
            });
        }

        window.filterCustomDropdownOptions = function () {
            var q = (document.getElementById('customDropdownSearch').value || '').toLowerCase().trim();
            var searching = q.length > 0;
            var items = document.querySelectorAll('#customDropdownOptionsList .custom-dropdown-item');
            var has = false;
            items.forEach(function (item) {
                var t = item.getAttribute('data-search-term') || '';
                var matches = t.includes(q);
                var isExtra = item.getAttribute('data-extra') === '1';
                var visible = matches && (searching || moGroupsExpanded || !isExtra);
                item.style.display = visible ? 'block' : 'none';
                if (visible) has = true;
            });
            var nr = document.getElementById('customDropdownNoResults');
            if (nr) nr.style.display = has ? 'none' : 'block';
            var btn = document.getElementById('moShowMoreGroups');
            if (btn) btn.style.display = searching ? 'none' : 'block';
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
                var title = a.title || '', desc = a.description || '', st = (a.status_type || '').toLowerCase(), grp = a.research_group_name || '', usr = a.username || '';
                var cls = (st === 'approved' || st === 'success') ? 'success' : ((st === 'revision requested' || st === 'warning') ? 'warning' : 'info');
                var category = getFormCategory(title, desc);
                var ms = title.toLowerCase().includes(searchVal) || desc.toLowerCase().includes(searchVal) || grp.toLowerCase().includes(searchVal) || usr.toLowerCase().includes(searchVal);
                var mst = true;
                if (statusVal === 'Approved') mst = (cls === 'success');
                else if (statusVal === 'Revision Requested') mst = (cls === 'warning');
                else if (statusVal === 'other') mst = (cls === 'info');
                var mf = (formVal === 'all' || category === formVal);
                if (ms && mst && mf) {
                    matched++;
                    var icon = cls === 'success' ? 'circle-check' : (cls === 'warning' ? 'rotate-ccw' : 'file-text');
                    var label = cls === 'success' ? 'Approved' : (cls === 'warning' ? 'Revision' : 'Update');
                    container.insertAdjacentHTML('beforeend',
                        '<div class="activity-item" style="cursor:default; margin-bottom:0;">' +
                        '<div class="activity-icon ' + cls + '"><i data-lucide="' + icon + '"></i></div>' +
                        '<div class="activity-content">' +
                        '<div class="activity-title">' + escapeHtml(title) + '</div>' +
                        '<div class="activity-desc">' + escapeHtml(desc) + '</div>' +
                        '<div class="activity-meta"><i data-lucide="users"></i> ' + escapeHtml(grp) + ' (' + escapeHtml(usr) + ') &middot; ' + formatLogDate(a.created_at) + '</div>' +
                        '</div>' +
                        '<span class="activity-chip ' + cls + '">' + label + '</span>' +
                        '</div>');
                }
            });
            document.getElementById('modalLogCount').textContent = 'Showing ' + matched + ' of ' + allRecentActivities.length + ' logs';
            if (window.lucide) lucide.createIcons();
            if (matched === 0) container.innerHTML = '<div style="text-align:center; color:var(--text-muted); padding:40px; font-weight:600;">No matching activity logs found.</div>';
        };
    })();
</script>
