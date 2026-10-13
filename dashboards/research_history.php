<?php
// dashboards/research_history.php — read-only archive of past research cycles.
//
// When a group leader uses "Start a New Research" (reset_handler.php), the finished
// cycle is MOVED out of the live tables into the `*_archive` tables so the student
// portal reads 0% again. Nothing is destroyed — this page is where the Research Office
// reads it back, documents included.
//
// STRICTLY READ-ONLY: no POST handling, no writes, no decision actions. The archived
// verdicts are a historical record and must not be re-litigated from here.

require_once '../config/db.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['Research Coordinator', 'Research Director', 'Statistician', 'Admin'], true)) {
    exit('Access Denied');
}

$selected_leader = isset($_GET['group']) ? (int)$_GET['group'] : 0;

// Requirement labels. checklist_items.item_name carries the display name for every item
// except 5/6, which only fill in requirement_name — COALESCE covers both.
$item_names = [];
foreach ($pdo->query("SELECT item_id, COALESCE(NULLIF(item_name,''), requirement_name) AS label FROM checklist_items") as $r) {
    $item_names[(int)$r['item_id']] = $r['label'];
}

// Same phase grouping analytics.php uses, plus the two staged/staff-uploaded items so
// nothing in an archive falls through to "Other".
$phase_map = [
    'Proposal'    => [11, 12, 13, 14, 15, 16, 17],
    'Final'       => [5, 6, 20, 21, 22, 23, 24, 25, 26, 27],
    'Statistics'  => [30, 31, 32, 33, 34, 35, 36, 37],
    'Plagiarism'  => [4, 40],
];
function rh_phase_of(int $item_id, array $phase_map): string
{
    foreach ($phase_map as $label => $items) {
        if (in_array($item_id, $items, true)) return $label;
    }
    return 'Other';
}

// --------------------------------------------------------------- group list
// Canonical leader-group query (same predicate as _master_overview.php / analytics.php),
// LEFT JOINed to the archive so groups that have never reset still appear — staff
// shouldn't have to guess which groups have history.
$groups = $pdo->query("
    SELECT u.user_id, u.username, u.research_group_name, u.department, u.program, u.year_level,
           COUNT(rc.cycle_id)   AS cycle_count,
           MAX(rc.archived_at)  AS last_archived
    FROM users u
    LEFT JOIN research_cycles rc ON rc.leader_user_id = u.user_id
    WHERE u.role = 'Student' AND u.research_group_name IS NOT NULL AND u.research_group_name <> '' AND u.leader_id IS NULL
    GROUP BY u.user_id, u.username, u.research_group_name, u.department, u.program, u.year_level
    ORDER BY (COUNT(rc.cycle_id) > 0) DESC, MAX(rc.archived_at) DESC, u.research_group_name ASC
")->fetchAll();

$total_cycles = (int)$pdo->query("SELECT COUNT(*) FROM research_cycles")->fetchColumn();
$total_docs   = (int)$pdo->query("SELECT COUNT(*) FROM uploads_archive")->fetchColumn();
$groups_with  = count(array_filter($groups, fn($g) => (int)$g['cycle_count'] > 0));

// ------------------------------------------------------------ selected group
$cycles = [];
$current = null;

if ($selected_leader > 0) {
    $cur = $pdo->prepare("SELECT user_id, username, research_group_name, research_title, department, program, year_level
                          FROM users WHERE user_id = ? AND role = 'Student' AND leader_id IS NULL");
    $cur->execute([$selected_leader]);
    $current = $cur->fetch() ?: null;

    $c_stmt = $pdo->prepare("SELECT * FROM research_cycles WHERE leader_user_id = ? ORDER BY cycle_no DESC");
    $c_stmt->execute([$selected_leader]);
    $cycles = $c_stmt->fetchAll();

    foreach ($cycles as &$cy) {
        $cid = (int)$cy['cycle_id'];

        // Latest archived row per requirement — one upload per item_id, matching how the
        // live review screens dedupe. Older versions stay in the table for the audit
        // trail but would only clutter the summary.
        $u = $pdo->prepare("SELECT ua.* FROM uploads_archive ua
                            INNER JOIN (
                                SELECT item_id, MAX(orig_upload_id) AS mx
                                FROM uploads_archive WHERE cycle_id = ? GROUP BY item_id
                            ) l ON ua.item_id = l.item_id AND ua.orig_upload_id = l.mx
                            WHERE ua.cycle_id = ?
                            ORDER BY ua.item_id");
        $u->execute([$cid, $cid]);
        $cy['docs'] = $u->fetchAll();

        $v = $pdo->prepare("SELECT COUNT(*) FROM uploads_archive WHERE cycle_id = ?");
        $v->execute([$cid]);
        $cy['doc_versions'] = (int)$v->fetchColumn();

        $b = $pdo->prepare("SELECT * FROM defense_bookings_archive WHERE cycle_id = ? ORDER BY phase");
        $b->execute([$cid]);
        $cy['defenses'] = $b->fetchAll();

        $f = $pdo->prepare("SELECT status, statistician_remarks, formatted_control_no, date_submitted, date_released, result_file
                            FROM form_stat_treatment_archive WHERE cycle_id = ? ORDER BY orig_form_id DESC");
        $f->execute([$cid]);
        $cy['stats_forms'] = $f->fetchAll();

        $p = $pdo->prepare("SELECT pa.*, us.username AS actor_name
                            FROM payment_logs_archive pa
                            LEFT JOIN users us ON us.user_id = pa.actor_id
                            WHERE pa.cycle_id = ? ORDER BY pa.created_at DESC");
        $p->execute([$cid]);
        $cy['payments'] = $p->fetchAll();

        $a = $pdo->prepare("SELECT ala.*, us.username
                            FROM activity_logs_archive ala
                            LEFT JOIN users us ON us.user_id = ala.user_id
                            WHERE ala.cycle_id = ? ORDER BY ala.created_at DESC");
        $a->execute([$cid]);
        $cy['activity'] = $a->fetchAll();

        $cy['members'] = json_decode($cy['members_json'] ?? '[]', true) ?: [];
    }
    unset($cy);
}

/** Map an archived verdict onto portal.css's status pill vocabulary. */
function rh_pill(?string $status): string
{
    return match ($status) {
        'Approved'           => 'ok',
        'Revision Requested' => 'bad',
        'Under Review'       => 'mid',
        default              => 'idle',
    };
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/svg+xml" href="../assets/images/favicon.svg?v=2">
    <link rel="icon" type="image/png" sizes="32x32" href="../assets/images/favicon-32.png?v=2">
    <link rel="apple-touch-icon" href="../assets/images/apple-touch-icon.png?v=2">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Research History</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/portal.css">
    <script src="../assets/js/portal.js"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        .rh-layout { display: grid; grid-template-columns: 300px 1fr; gap: 20px; align-items: start; }
        @media (max-width: 900px) { .rh-layout { grid-template-columns: 1fr; } }

        .rh-list { background: var(--surface); border: 1px solid var(--line); border-radius: var(--card-radius); padding: 10px; max-height: 74vh; overflow-y: auto; }
        .rh-group {
            display: block; width: 100%; text-align: left; background: none; border: none;
            padding: 11px 13px; border-radius: var(--r-sm); cursor: pointer;
            text-decoration: none; color: inherit; font-family: inherit; margin-bottom: 2px;
        }
        .rh-group:hover { background: var(--surface-2); }
        .rh-group.active { background: var(--surface-3); }
        .rh-group .rh-g-name { font-size: 13px; font-weight: 650; color: var(--ink); line-height: 1.35; }
        .rh-group .rh-g-meta { font-size: 11px; color: var(--muted); margin-top: 3px; display: flex; align-items: center; gap: 6px; }
        .rh-count {
            background: var(--accent); color: #fff; border-radius: 999px;
            font-size: 10px; font-weight: 800; padding: 1px 7px; letter-spacing: .2px;
        }
        .rh-count.zero { background: var(--surface-3); color: var(--muted); }

        .rh-cycle { background: var(--surface); border: 1px solid var(--line); border-radius: var(--card-radius); padding: 22px; margin-bottom: 20px; }
        .rh-cycle-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; flex-wrap: wrap; margin-bottom: 18px; }
        .rh-cycle-title { font-size: 17px; font-weight: 750; color: var(--ink); margin: 0 0 6px; line-height: 1.35; }
        .rh-badges { display: flex; gap: 6px; flex-wrap: wrap; }
        .rh-badge {
            font-size: 10.5px; font-weight: 700; padding: 3px 9px; border-radius: 999px;
            background: var(--surface-3); color: var(--muted); white-space: nowrap;
        }
        .rh-badge.mono { font-family: 'JetBrains Mono', monospace; font-size: 10px; }

        .rh-section { margin-top: 20px; }
        .rh-section > h4 {
            font-size: 11px; font-weight: 800; color: var(--muted); text-transform: uppercase;
            letter-spacing: .6px; margin: 0 0 10px; padding-bottom: 7px; border-bottom: 1px solid var(--line);
        }
        .rh-phase-label { font-size: 11.5px; font-weight: 700; color: var(--muted); margin: 14px 0 7px; }
        .rh-phase-label:first-child { margin-top: 0; }

        .rh-doc { display: flex; align-items: center; gap: 11px; padding: 9px 12px; border-radius: var(--r-sm); background: var(--surface-2); margin-bottom: 5px; }
        .rh-doc-name { flex: 1; min-width: 0; font-size: 12.5px; font-weight: 600; color: var(--ink); }
        .rh-doc-file { font-size: 10.5px; color: var(--muted); font-weight: 400; display: block; margin-top: 2px; overflow-wrap: anywhere; }
        .rh-doc-date { font-size: 11px; color: var(--muted); white-space: nowrap; }
        .rh-open {
            font-size: 11px; font-weight: 700; text-decoration: none; color: var(--accent);
            border: 1px solid var(--line); border-radius: 999px; padding: 4px 11px; white-space: nowrap;
        }
        .rh-open:hover { background: var(--surface-3); }
        .rh-remark { font-size: 11.5px; color: var(--muted); font-style: italic; padding: 2px 12px 9px 12px; margin-top: -4px; }

        .pill { font-size: 10.5px; font-weight: 750; padding: 3px 10px; border-radius: 999px; white-space: nowrap; }
        .pill.ok   { background: rgba(16,185,129,.13); color: #059669; }
        .pill.bad  { background: rgba(239,68,68,.13);  color: #dc2626; }
        .pill.mid  { background: rgba(245,158,11,.15); color: #b45309; }
        .pill.idle { background: var(--surface-3); color: var(--muted); }
        /* portal.css switches themes with body.theme-dark — the light pill inks are too
           dim against the dark surfaces, so lift them rather than re-tinting. */
        body.theme-dark .pill.ok  { background: rgba(16,185,129,.20); color: #34d399; }
        body.theme-dark .pill.bad { background: rgba(239,68,68,.20);  color: #f87171; }
        body.theme-dark .pill.mid { background: rgba(245,158,11,.22); color: #fbbf24; }

        .rh-kv { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 10px; }
        .rh-kv > div { background: var(--surface-2); border-radius: var(--r-sm); padding: 10px 13px; }
        .rh-kv .k { font-size: 10px; font-weight: 800; color: var(--muted); text-transform: uppercase; letter-spacing: .4px; }
        .rh-kv .v { font-size: 13px; font-weight: 650; color: var(--ink); margin-top: 3px; overflow-wrap: anywhere; }

        .rh-timeline { max-height: 300px; overflow-y: auto; }
        .rh-tl-row { display: flex; gap: 10px; padding: 7px 0; border-bottom: 1px solid var(--line); font-size: 12px; }
        .rh-tl-row:last-child { border-bottom: none; }
        .rh-tl-when { color: var(--muted); font-size: 11px; white-space: nowrap; min-width: 96px; }
        .rh-tl-what { color: var(--ink); }
        .rh-tl-what small { display: block; color: var(--muted); font-weight: 400; margin-top: 1px; }

        details.rh-more > summary { cursor: pointer; font-size: 11.5px; font-weight: 700; color: var(--accent); list-style: none; padding: 4px 0; }
        details.rh-more > summary::-webkit-details-marker { display: none; }

        .rh-empty { background: var(--surface); border: 1px dashed var(--line); border-radius: var(--card-radius); padding: 46px 30px; text-align: center; }
        .rh-empty i { width: 34px; height: 34px; color: var(--muted); margin-bottom: 12px; }
        .rh-empty h3 { font-size: 15px; font-weight: 700; color: var(--ink); margin: 0 0 6px; }
        .rh-empty p { font-size: 12.5px; color: var(--muted); margin: 0 auto; max-width: 420px; line-height: 1.55; }
    </style>
</head>

<body class="admin-module">
    <div class="page-header">
        <div>
            <h1 class="page-title">Research History</h1>
            <p class="page-subtitle">
                Archived research cycles. When a group starts a new research, everything they submitted
                is filed here &mdash; documents included. Read-only.
            </p>
        </div>
    </div>

    <div class="dashboard-grid">
        <div class="stat-card">
            <div class="stat-value"><?= $total_cycles ?></div>
            <div class="stat-label">Archived Cycles</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?= $groups_with ?></div>
            <div class="stat-label">Groups With History</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?= $total_docs ?></div>
            <div class="stat-label">Archived Documents</div>
        </div>
    </div>

    <div class="rh-layout">
        <div class="rh-list">
            <?php foreach ($groups as $g): $n = (int)$g['cycle_count']; ?>
                <a class="rh-group <?= (int)$g['user_id'] === $selected_leader ? 'active' : '' ?>"
                   href="research_history.php?group=<?= (int)$g['user_id'] ?>">
                    <span class="rh-g-name"><?= htmlspecialchars($g['research_group_name']) ?></span>
                    <span class="rh-g-meta">
                        <span class="rh-count <?= $n === 0 ? 'zero' : '' ?>"><?= $n ?></span>
                        <?= htmlspecialchars($g['username']) ?>
                    </span>
                </a>
            <?php endforeach; ?>
        </div>

        <div>
            <?php if (!$current): ?>
                <div class="rh-empty">
                    <i data-lucide="archive"></i>
                    <h3>Pick a research group</h3>
                    <p>Choose a group on the left to read its archived research. The number beside each
                       group is how many past researches it has filed.</p>
                </div>

            <?php elseif (empty($cycles)): ?>
                <div class="rh-empty">
                    <i data-lucide="folder-open"></i>
                    <h3><?= htmlspecialchars($current['research_group_name']) ?></h3>
                    <p>This group has not archived any research yet. Their current work is still live in
                       the review queues &mdash; history only appears once their leader starts a new research.</p>
                </div>

            <?php else: ?>
                <?php foreach ($cycles as $cy): ?>
                    <div class="rh-cycle">
                        <div class="rh-cycle-head">
                            <div style="min-width:0;">
                                <h3 class="rh-cycle-title"><?= htmlspecialchars($cy['research_group_name'] ?: 'Untitled Research') ?></h3>
                                <div class="rh-badges">
                                    <span class="rh-badge">Research #<?= (int)$cy['cycle_no'] ?></span>
                                    <?php if ($cy['year_level']): ?>
                                        <span class="rh-badge"><?= htmlspecialchars($cy['year_level']) ?></span>
                                    <?php endif; ?>
                                    <?php if ($cy['program']): ?>
                                        <span class="rh-badge"><?= htmlspecialchars($cy['program']) ?></span>
                                    <?php endif; ?>
                                    <?php if ($cy['plag_control_no']): ?>
                                        <span class="rh-badge mono"><?= htmlspecialchars($cy['plag_control_no']) ?></span>
                                    <?php endif; ?>
                                    <?php if ($cy['stats_control_no']): ?>
                                        <span class="rh-badge mono"><?= htmlspecialchars($cy['stats_control_no']) ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div style="text-align:right;">
                                <div style="font-size:11px; color:var(--muted);">Archived</div>
                                <div style="font-size:13px; font-weight:700; color:var(--ink);">
                                    <?= htmlspecialchars(date('M j, Y', strtotime($cy['archived_at']))) ?>
                                </div>
                            </div>
                        </div>

                        <?php if ($cy['research_title'] && $cy['research_title'] !== $cy['research_group_name']): ?>
                            <div class="rh-section">
                                <h4>Registered Title</h4>
                                <div style="font-size:13px; color:var(--ink);"><?= htmlspecialchars($cy['research_title']) ?></div>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($cy['members'])): ?>
                            <div class="rh-section">
                                <h4>Proponents at time of archiving</h4>
                                <div class="rh-kv">
                                    <?php foreach ($cy['members'] as $m): ?>
                                        <div>
                                            <div class="k"><?= htmlspecialchars($m['role'] ?? 'Member') ?><?php
                                                if (($m['role'] ?? '') === 'Member' && array_key_exists('carried_over', $m)) {
                                                    echo $m['carried_over'] ? ' &middot; stayed' : ' &middot; left';
                                                }
                                            ?></div>
                                            <div class="v"><?= htmlspecialchars($m['username'] ?? '—') ?></div>
                                            <div style="font-size:11px; color:var(--muted); margin-top:2px;">
                                                <?= htmlspecialchars($m['student_number'] ?: ($m['email'] ?? '')) ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($cy['docs'])): ?>
                            <div class="rh-section">
                                <h4>Documents
                                    <span style="text-transform:none; letter-spacing:0; font-weight:600;">
                                        &mdash; <?= count($cy['docs']) ?> requirement<?= count($cy['docs']) === 1 ? '' : 's' ?>,
                                        <?= (int)$cy['doc_versions'] ?> file<?= (int)$cy['doc_versions'] === 1 ? '' : 's' ?> including older versions
                                    </span>
                                </h4>
                                <?php
                                $by_phase = [];
                                foreach ($cy['docs'] as $d) {
                                    $by_phase[rh_phase_of((int)$d['item_id'], $phase_map)][] = $d;
                                }
                                foreach (array_keys($phase_map) as $ph):
                                    if (empty($by_phase[$ph])) continue; ?>
                                    <div class="rh-phase-label"><?= htmlspecialchars($ph) ?></div>
                                    <?php foreach ($by_phase[$ph] as $d): ?>
                                        <div class="rh-doc">
                                            <span class="rh-doc-name">
                                                <?= htmlspecialchars($item_names[(int)$d['item_id']] ?? ('Item ' . (int)$d['item_id'])) ?>
                                                <span class="rh-doc-file"><?= htmlspecialchars($d['original_filename'] ?: basename($d['file_path'])) ?></span>
                                            </span>
                                            <span class="rh-doc-date"><?= $d['uploaded_at'] ? htmlspecialchars(date('M j, Y', strtotime($d['uploaded_at']))) : '' ?></span>
                                            <span class="pill <?= rh_pill($d['verification_status']) ?>"><?= htmlspecialchars($d['verification_status'] ?: 'Pending') ?></span>
                                            <a class="rh-open" href="<?= htmlspecialchars($d['file_path']) ?>" target="_blank" rel="noopener">Open</a>
                                        </div>
                                        <?php if (!empty($d['remarks'])): ?>
                                            <div class="rh-remark">&ldquo;<?= htmlspecialchars($d['remarks']) ?>&rdquo;</div>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                <?php endforeach; ?>
                                <?php if (!empty($by_phase['Other'])): ?>
                                    <div class="rh-phase-label">Other</div>
                                    <?php foreach ($by_phase['Other'] as $d): ?>
                                        <div class="rh-doc">
                                            <span class="rh-doc-name"><?= htmlspecialchars($item_names[(int)$d['item_id']] ?? ('Item ' . (int)$d['item_id'])) ?></span>
                                            <span class="pill <?= rh_pill($d['verification_status']) ?>"><?= htmlspecialchars($d['verification_status'] ?: 'Pending') ?></span>
                                            <a class="rh-open" href="<?= htmlspecialchars($d['file_path']) ?>" target="_blank" rel="noopener">Open</a>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($cy['defenses'])): ?>
                            <div class="rh-section">
                                <h4>Defense Schedule</h4>
                                <div class="rh-kv">
                                    <?php foreach ($cy['defenses'] as $b): ?>
                                        <div>
                                            <div class="k"><?= htmlspecialchars(ucfirst($b['phase'])) ?> Defense</div>
                                            <div class="v"><?= $b['defense_date'] ? htmlspecialchars(date('M j, Y', strtotime($b['defense_date']))) : 'No date set' ?></div>
                                            <div style="margin-top:5px;"><span class="pill <?= $b['status'] === 'Scheduled' ? 'ok' : ($b['status'] === 'Rejected' || $b['status'] === 'Cancelled' ? 'bad' : 'mid') ?>"><?= htmlspecialchars($b['status']) ?></span></div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($cy['stats_forms'])): ?>
                            <div class="rh-section">
                                <h4>Statistical Treatment</h4>
                                <?php foreach ($cy['stats_forms'] as $f): ?>
                                    <div class="rh-kv" style="margin-bottom:8px;">
                                        <div>
                                            <div class="k">Final Phase</div>
                                            <div class="v" style="font-size:12px;"><?= htmlspecialchars($f['status']) ?></div>
                                        </div>
                                        <div>
                                            <div class="k">Control No.</div>
                                            <div class="v" style="font-size:12px; font-family:'JetBrains Mono',monospace;"><?= htmlspecialchars($f['formatted_control_no'] ?: '—') ?></div>
                                        </div>
                                        <div>
                                            <div class="k">Released</div>
                                            <div class="v" style="font-size:12px;"><?= $f['date_released'] ? htmlspecialchars(date('M j, Y', strtotime($f['date_released']))) : '—' ?></div>
                                        </div>
                                        <?php if (!empty($f['result_file'])): ?>
                                            <div>
                                                <div class="k">Result</div>
                                                <div class="v"><a class="rh-open" href="<?= htmlspecialchars($f['result_file']) ?>" target="_blank" rel="noopener">Open file</a></div>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <?php if (!empty($f['statistician_remarks'])): ?>
                                        <div class="rh-remark">&ldquo;<?= htmlspecialchars($f['statistician_remarks']) ?>&rdquo;</div>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($cy['payments'])): ?>
                            <div class="rh-section">
                                <h4>Payment Record</h4>
                                <?php foreach ($cy['payments'] as $p): ?>
                                    <div class="rh-tl-row">
                                        <span class="rh-tl-when"><?= htmlspecialchars(date('M j, Y', strtotime($p['created_at']))) ?></span>
                                        <span class="rh-tl-what">
                                            <?= htmlspecialchars(ucfirst($p['action'])) ?>
                                            <?= $p['control_no'] ? ' &middot; ' . htmlspecialchars($p['control_no']) : '' ?>
                                            <small>by <?= htmlspecialchars($p['actor_name'] ?: 'Unknown staff') ?><?= $p['remarks'] ? ' — ' . htmlspecialchars($p['remarks']) : '' ?></small>
                                        </span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($cy['activity'])): ?>
                            <div class="rh-section">
                                <details class="rh-more">
                                    <summary>Show full activity log (<?= count($cy['activity']) ?> entries)</summary>
                                    <div class="rh-timeline" style="margin-top:10px;">
                                        <?php foreach ($cy['activity'] as $a): ?>
                                            <div class="rh-tl-row">
                                                <span class="rh-tl-when"><?= htmlspecialchars(date('M j, Y', strtotime($a['created_at']))) ?></span>
                                                <span class="rh-tl-what">
                                                    <?= htmlspecialchars($a['title']) ?>
                                                    <small><?= htmlspecialchars($a['description']) ?><?= $a['username'] ? ' — ' . htmlspecialchars($a['username']) : '' ?></small>
                                                </span>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </details>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <script>
        if (window.lucide) lucide.createIcons();
    </script>
</body>

</html>
