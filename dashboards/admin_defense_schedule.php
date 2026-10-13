<?php
// dashboards/admin_defense_schedule.php — the RDC Secretary screen (application flow steps 2 & 3:
// "check the available date" and "log, record, and schedule").
//
// Reached as admin_module_dynamic.php?phase=final&view=schedule, which includes this file and
// exits before its own group-inbox rendering. Kept in its own file because it shares no query,
// no action and no layout with that review queue.
//
// Availability is open-by-default: staff close exceptions (holidays, closures) rather than
// publishing slots. See defense_lib.php.
//
// Expects from the includer: $pdo, $role, $phase.

require_once 'defense_lib.php';
require_once 'group_roster_lib.php';   // ws_group_rosters() / ws_names_markup() — "View names"

// Director + Coordinator both act as the RDC Secretary.
if (!in_array($role, ['Research Coordinator', 'Research Director'], true)) {
    exit("Access Denied");
}

$df_phase = ($phase === 'proposal') ? 'proposal' : 'final';
$csrf = $_SESSION['csrf_token'];
$today = df_today();

// There is no "requests waiting on a decision" queue any more, and there is no query for one.
// Students stopped choosing their own dates on 2026-09-19: every date now arrives through the
// focal -> coordinator -> secretary chain and every booking that carries one has a batch_id.
// The old queue read `status = 'Date Selected' AND batch_id IS NULL`, which nothing can write
// any more — so it sat at the top of this screen as a permanently empty box, above the work.
// The office's own record of everything logged (application flow step 3), newest defense first.
$booked_stmt = $pdo->prepare("
    SELECT b.booking_id, b.decided_at, b.defense_date, b.user_id,
           u.username, u.research_group_name, u.program, u.department, u.student_number,
           d.username AS decided_by_name,
           up.file_path AS application_path, up.original_filename AS application_filename
    FROM defense_bookings b
    JOIN users u ON u.user_id = b.user_id
    LEFT JOIN users d ON d.user_id = b.decided_by
    LEFT JOIN uploads up ON up.upload_id = b.application_upload_id
    WHERE b.phase = ? AND b.status = 'Scheduled'
    ORDER BY b.defense_date DESC");
$booked_stmt->execute([$df_phase]);
$booked_all = $booked_stmt->fetchAll(PDO::FETCH_ASSOC);
// The office accumulates one of these per group forever, so the record is collapsed behind a pill
// bar and only opens when someone actually asks for it — see the "Logged & recorded" section.
// Split once here so the pills can carry real counts instead of the JS having to re-derive them.
$logged_upcoming = [];
$logged_past     = [];
foreach ($booked_all as $r) {
    if ($r['defense_date'] >= $today) $logged_upcoming[] = $r;
    else                              $logged_past[]     = $r;
}
// Soonest first — a list of what is coming reads forwards, unlike the record, which reads back.
usort($logged_upcoming, function ($a, $b) { return strcmp($a['defense_date'], $b['defense_date']); });

// Upcoming is now the SAME rows as the record, just the forward half of it. It used to come from
// df_upcoming(), whose rows carry no booking detail, which is why nothing on that card could be
// opened. Sharing one dataset is what makes every preview row clickable.
const DS_UPCOMING_PREVIEW = 4;
$upcoming = array_slice($logged_upcoming, 0, DS_UPCOMING_PREVIEW);

// Sections the Coordinator has confirmed and the Secretary still has to log. This is the last
// step of the focal -> coordinator -> secretary chain, and it lives on THIS screen rather than
// in Schedule Approvals because logging a day means reading the calendar next to it.
require_once __DIR__ . '/focal_lib.php';
$ds_can_log = fp_can_act($ds_role ?? ($role ?? ''), 'Confirmed');
$ds_to_log = $ds_other_phase = [];
if ($ds_can_log) {
    foreach (fp_chain_queue($pdo, 'Confirmed', null, fp_school_scope($pdo, $role ?? '', (int)$_SESSION['user_id'])) as $bt) {
        // Both phases are counted, because "nothing to log" and "nothing to log ON THIS
        // SCREEN" are different answers and only one of them is ever true. A bare 0 while a
        // section sits waiting on the other phase reads as a counter that does not work.
        if ($bt['phase'] === $df_phase) { $ds_to_log[] = $bt; } else { $ds_other_phase[] = $bt; }
    }
}
// Two levels, matching how the work actually arrives: the focal person bundles a SECTION, so
// that is the card. Opening one drops to the group cards — the same rich card the request queue
// uses, because the office reads a group the same way whichever screen it is on.
$ds_focus = isset($_GET['batch']) ? (int)$_GET['batch'] : 0;
$ds_focus_batch = null;
$ds_focus_rows  = [];
foreach ($ds_to_log as $bt) {
    if ((int)$bt['batch_id'] === $ds_focus) $ds_focus_batch = $bt;
}
if ($ds_focus_batch) {
    // Shaped the way components/ds_request_card.php expects, so it renders unchanged.
    $fs = $pdo->prepare("
        SELECT b.booking_id, b.user_id, b.status, b.slot_no, b.updated_at, b.defense_date,
               u.username, u.research_group_name, u.program, u.department, u.student_number,
               up.file_path AS application_path, up.original_filename AS application_filename
        FROM defense_bookings b
        JOIN users u ON u.user_id = b.user_id
        LEFT JOIN uploads up ON up.upload_id = b.application_upload_id
        WHERE b.batch_id = ?
        ORDER BY b.slot_no ASC, b.booking_id ASC");
    $fs->execute([$ds_focus]);
    $ds_focus_rows = $fs->fetchAll(PDO::FETCH_ASSOC);
    foreach ($ds_focus_rows as &$fr) { $fr['conflict'] = null; }
    unset($fr);
}

// Rosters for every group on this screen — the record and the opened section both offer
// "View names", and the office needs the proponents before it signs a ticket for them.
// The opened bundle's groups need rosters and documents too — the card shows "View names"
// and the requirement list wherever it is rendered.
$ds_rosters = ws_group_rosters($pdo, array_merge(
    array_column($booked_all, 'user_id'),
    array_column($ds_focus_rows, 'user_id')
));

// Every document this stage collects, keyed by group — proposal asks for one, final for six.
// The secretary sees them here next to the requested date, which is the whole point of the
// screen: the date and the paperwork are decided together. `verification_status` comes along
// because a date may only be confirmed once every one of them is Approved in the Checklist.

$ds_app_docs = [];
$ds_item_names = [];
$ds_items = df_phase2_items($df_phase);
// $ds_items is empty for proposal now — that phase collects nothing in the panel, its
// endorsement having become an ordinary requirement. An empty `IN ()` is a MySQL syntax error,
// so this block is skipped rather than run against nothing.
if ($ds_focus_rows && $ds_items) {
    $ds_users = array_unique(array_column($ds_focus_rows, 'user_id'));
    $ph_u = implode(',', array_fill(0, count($ds_users), '?'));
    $ph_i = implode(',', array_fill(0, count($ds_items), '?'));
    // Latest version per (group, item): older ones are history, not what is being checked.
    $ds_stmt = $pdo->prepare("SELECT up.user_id, up.item_id, up.file_path, up.original_filename,
                                     up.verification_status, ci.item_name
        FROM uploads up
        JOIN checklist_items ci ON ci.item_id = up.item_id
        JOIN (SELECT user_id, item_id, MAX(upload_id) AS max_id FROM uploads
              WHERE user_id IN ($ph_u) AND item_id IN ($ph_i) GROUP BY user_id, item_id) latest
          ON latest.max_id = up.upload_id");
    $ds_stmt->execute(array_merge($ds_users, $ds_items));
    foreach ($ds_stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $ds_app_docs[$r['user_id']][] = $r;
    }
}
// Same empty-list guard as the block above: proposal collects no panel documents any more, and
// `IN ()` is a MySQL syntax error rather than an empty result.
if ($ds_items) {
    $ds_name_stmt = $pdo->prepare("SELECT item_id, item_name FROM checklist_items WHERE item_id IN ("
        . implode(',', array_map('intval', $ds_items)) . ")");
    $ds_name_stmt->execute();
    foreach ($ds_name_stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $ds_item_names[(int)$r['item_id']] = $r['item_name'];
    }
}

// Calendar payload: this month through the end of the booking window, plus the blackout id so
// a closed day can be reopened with one click.
// Capacity is per SCHOOL (10 each), so the planner is built once per school and merged. A day
// is only truly FULL when every school is full — MCNP filling up leaves ISAP's ten untouched,
// and a single combined "17 of 20" would tell the Secretary nothing she can act on.
$df_schools = df_schools($pdo);
$cal = [];
$cur = strtotime($today . ' first day of this month');
$endMonth = strtotime(date('Y-m-01', strtotime(df_window_end())));
while ($cur <= $endMonth) {
    $perSchool = [];
    foreach ($df_schools as $sch) {
        foreach (df_month_cells($pdo, $df_phase, (int)date('Y', $cur), (int)date('n', $cur), 0, $sch) as $c2) {
            $perSchool[$c2['date']][$sch] = [
                'tag'  => aa_school_tag($sch),
                'used' => (int)$c2['slots_used'],
                'left' => (int)$c2['slots_left'],
                'full' => (int)$c2['slots_left'] === 0,
            ];
        }
    }
    // The unscoped pass supplies everything that is NOT capacity — blackout, Sunday, past,
    // window — which is identical whichever school is asking.
    foreach (df_month_cells($pdo, $df_phase, (int)date('Y', $cur), (int)date('n', $cur)) as $cell) {
        $per     = array_values($perSchool[$cell['date']] ?? []);
        $allFull = $per !== [] && count(array_filter($per, fn($p) => $p['full'])) === count($per);
        $used    = array_sum(array_column($per, 'used'));
        $left    = array_sum(array_column($per, 'left'));
        $state   = $cell['state'];
        // 'taken' from the unscoped pass counted both schools against one school's limit.
        if ($state === 'taken' && !$allFull) $state = 'open';
        if ($allFull) $state = 'taken';
        $cal[$cell['date']] = [
            'state'       => $state,
            'reason'      => $cell['reason'],
            'is_today'    => $cell['is_today'],
            'blackout_id' => $cell['blackout']['blackout_id'] ?? null,
            'blackout_by' => $cell['blackout']['source'] ?? null,
            // A day holds DEFENSE_SLOTS_PER_SCHOOL_PER_DAY per school across both phases, so the
            // planner lists the groups rather than naming a single holder, and carries the
            // per-school split so the Secretary reads two tidy tens, not one pile of twenty.
            'slots_used'  => $used,
            'slots_left'  => $left,
            'per_school'  => $per,
            'groups'      => array_map(function ($g) {
                return [
                    'name'   => $g['research_group_name'],
                    'status' => $g['status'],
                    'phase'  => $g['phase'],
                    'slot'   => $g['slot_no'],
                    'id'     => $g['booking_id'],
                ];
            }, $cell['booking']['groups'] ?? []),
        ];
    }
    $cur = strtotime('+1 month', $cur);
}

$blackout_count = 0;
foreach ($cal as $c) {
    if ($c['state'] === 'blocked') $blackout_count++;
}

$flash = $_SESSION['message'] ?? '';
$flash_type = $_SESSION['message_type'] ?? 'success';
unset($_SESSION['message'], $_SESSION['message_type']);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/svg+xml" href="../assets/images/favicon.svg?v=2">
    <link rel="icon" type="image/png" sizes="32x32" href="../assets/images/favicon-32.png?v=2">
    <link rel="apple-touch-icon" href="../assets/images/apple-touch-icon.png?v=2">
    <title>Admin - Defense Scheduling</title>
    <link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@600;700;800;900&family=Inter:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500;700;800&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/portal.css">
    <script src="../assets/js/portal.js"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        .ds-wrap { display: grid; grid-template-columns: minmax(0, 1fr) 330px; gap: 20px; align-items: start; }
        @media (max-width: 1100px) { .ds-wrap { grid-template-columns: minmax(0, 1fr); } }

        /* ── Sections to log: the Secretary's final step, beside the calendar ─────────
           Reuses .ds-reqcard so a section reads like the group cards below it. */
        /* ── Section bundle: the group card's shape, filled with a room. ─────────────── */
        a.ds-reqcard.ds-bundle { display: block; text-decoration: none; color: inherit; }
        a.ds-reqcard.ds-bundle:hover { transform: translateY(-2px); box-shadow: 0 10px 26px rgba(15, 23, 42, .09); }
        .ds-bc-avatar {
            display: grid; place-items: center;
            background: color-mix(in srgb, var(--accent) 11%, transparent); color: var(--accent, #7c3aed);
        }

        /* Verified signatures — a green badge-check reads instantly; the NAME leads because
           that is what is being relied on, with the role as the caption under it. */
        .ds-bc-sigs {
            display: grid; gap: 8px; margin-top: 12px;
            padding: 11px 12px; border-radius: 12px;
            background: rgba(5, 150, 105, .06); border: 1px solid rgba(5, 150, 105, .16);
        }
        .ds-bc-sig { display: flex; align-items: center; gap: 9px; min-width: 0; }
        .ds-bc-sig > i { width: 17px; height: 17px; flex-shrink: 0; color: #059669; }
        .ds-bc-sig > div { min-width: 0; line-height: 1.25; }
        .ds-bc-sig strong {
            display: block; font-size: 12.5px; font-weight: 750; color: var(--ink);
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .ds-bc-sig span {
            display: block; font-size: 10.5px; font-weight: 700; letter-spacing: .05em;
            text-transform: uppercase; color: #059669; margin-top: 1px;
        }
        .is-conflict .ds-bc-sigs { background: rgba(220, 38, 38, .05); border-color: rgba(220, 38, 38, .16); }

        .ds-chip-bad { color: #b91c1c !important; background: #fee2e2 !important; }
        /* Level 2's action bar: one bar for the whole section, under the group cards. */
        .ds-logbar {
            display: flex; align-items: center; gap: 16px; flex-wrap: wrap;
            margin-top: 14px; padding: 14px 18px; border-radius: 16px;
            background: color-mix(in srgb, var(--accent) 7%, transparent); border: 1px solid color-mix(in srgb, var(--accent) 22%, transparent);
        }
        .ds-logbar-text { flex: 1; min-width: 0; font-size: 12.5px; color: var(--muted); }
        .ds-logbar-text strong { font-size: 15px; font-weight: 800; color: var(--ink); margin-right: 5px; }
        .ds-log-drop { display: block; margin-top: 3px; color: #b45309; font-size: 11.5px; }

        /* `display:block` above beats the `hidden` attribute, so the empty "0 groups will be
           taken off" line rendered anyway. Third time this exact trap has bitten in this
           codebase, so it is settled globally here rather than per element. */
        [hidden] { display: none !important; }

        .ds-rc-pick { margin-left: auto; display: flex; align-items: center; gap: 9px; flex-shrink: 0; cursor: pointer; }
        .ds-rc-slot { font-family: 'JetBrains Mono', monospace; font-size: 11px; font-weight: 800; color: #fff; background: var(--ink); border-radius: 999px; padding: 3px 9px; }
        .ds-keep { width: 17px; height: 17px; margin: 0; flex-shrink: 0; accent-color: var(--accent, #7c3aed); }
        .ds-reqcard:has(.ds-keep:not(:checked)) { opacity: .5; border-style: dashed; }

        .ds-section-title {
            display: flex; align-items: center; gap: 9px;
            font-size: 13px; font-weight: 700; letter-spacing: .02em; margin: 0 0 12px;
        }
        .ds-count {
            font-family: 'JetBrains Mono', monospace; font-size: 11px; font-weight: 700;
            padding: 2px 8px; border-radius: 10px; background: var(--surface-2, #f1f5f9);
        }
        .ds-linkbtn {
            margin-left: auto; background: none; border: none; cursor: pointer;
            font-family: inherit; font-size: 11.5px; font-weight: 700; color: var(--accent); padding: 3px 5px;
        }
        .ds-linkbtn:hover { text-decoration: underline; }

        .ds-card {
            border: 1px solid var(--line); border-radius: 16px;
            padding: 16px; margin-bottom: 12px; background: var(--surface, #fff);
        }
        .ds-card-top { display: flex; gap: 13px; align-items: flex-start; margin-bottom: 14px; }
        .ds-requester {
            display: flex; gap: 13px; align-items: center; margin-bottom: 14px;
            padding: 11px 12px; border-radius: 13px;
            background: var(--surface-2, #f8fafc); border: 1px solid var(--line);
        }
        .ds-meta-row { display: flex; flex-wrap: wrap; gap: 4px 14px; margin-top: 5px; }
        .ds-meta-row span {
            display: inline-flex; align-items: center; gap: 5px;
            font-size: 11.5px; opacity: .72; min-width: 0;
        }
        .ds-filebtn {
            display: flex; align-items: center; gap: 9px; width: 100%; margin-bottom: 13px;
            padding: 10px 12px; border-radius: 11px; cursor: pointer; text-align: left;
            background: var(--surface-2, #f8fafc); border: 1px solid var(--line);
            color: inherit; font-family: inherit; font-size: 12px; font-weight: 650;
        }
        .ds-filebtn:hover { border-color: color-mix(in srgb, var(--accent) 40%, var(--line)); }
        .ds-filebtn span { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

        /* Requirements, collapsed. Six file rows per group turned this queue into a wall — the
           documents are decided in the Checklist, so here they are one row that expands. */
        .ds-reqtoggle {
            display: flex; align-items: center; gap: 9px; width: 100%; margin-bottom: 13px;
            padding: 10px 12px; border-radius: 11px; cursor: pointer; text-align: left;
            background: var(--surface-2, #f8fafc); border: 1px solid var(--line);
            color: inherit; font-family: inherit; font-size: 12px; font-weight: 650;
        }
        .ds-reqtoggle:hover { border-color: color-mix(in srgb, var(--accent) 40%, var(--line)); }
        .ds-reqlabel { flex: 1; min-width: 0; }
        .ds-reqchev { transition: transform .18s ease; }
        .ds-reqtoggle[aria-expanded="true"] .ds-reqchev { transform: rotate(90deg); }
        .ds-reqcount {
            flex-shrink: 0; font-size: 11px; font-weight: 750; padding: 2px 9px; border-radius: 999px;
            background: #fef3c7; color: #92400e;
        }
        .ds-reqcount.is-clear { background: #d1fae5; color: #047857; }

        .ds-reqlist { display: none; margin: -6px 0 13px; padding: 4px; border-radius: 11px; background: var(--surface-2, #f8fafc); }
        .ds-reqlist.open { display: block; }
        .ds-reqrow {
            display: flex; align-items: center; gap: 9px; padding: 8px 10px; border-radius: 8px;
            text-decoration: none; color: inherit; font-size: 12px; font-weight: 600;
        }
        .ds-reqrow:hover { background: var(--surface, #fff); }
        .ds-reqrow.is-missing { opacity: .6; pointer-events: none; }
        .ds-reqname { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .ds-reqstate { flex-shrink: 0; font-size: 10.5px; font-weight: 700; color: var(--muted, #94a3b8); }

        .ds-prev-body { background: var(--surface-2, #f8fafc); border-radius: 12px; overflow: hidden; text-align: center; }
        .ds-prev-body img { max-width: 100%; max-height: 62vh; display: block; margin: 0 auto; }
        .ds-prev-body iframe { width: 100%; height: 62vh; border: none; display: block; }
        .ds-avatar {
            width: 42px; height: 42px; border-radius: 12px; flex-shrink: 0;
            display: flex; align-items: center; justify-content: center;
            background: var(--surface-2, #f1f5f9); font-weight: 700; font-size: 15px;
        }
        .ds-group { font-size: 14.5px; font-weight: 700; line-height: 1.3; margin-bottom: 3px; }
        .ds-sub { font-size: 12px; opacity: .7; line-height: 1.4; }

        .ds-date-row {
            display: flex; align-items: center; gap: 12px;
            border: 1px solid var(--line); border-radius: 12px;
            padding: 10px 12px; margin-bottom: 13px;
        }
        .ds-date-chip {
            width: 52px; flex-shrink: 0; text-align: center; padding: 6px 0;
            border-radius: 10px; background: var(--surface-2, #f1f5f9);
        }
        .ds-date-d { display: block; font-family: 'JetBrains Mono', monospace; font-size: 19px; font-weight: 800; line-height: 1; }
        .ds-date-m { display: block; font-size: 9.5px; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; opacity: .7; margin-top: 3px; }
        .ds-date-txt { min-width: 0; font-size: 13px; font-weight: 650; }
        .ds-date-txt span { display: block; font-size: 11.5px; font-weight: 500; opacity: .65; margin-top: 2px; }

        .ds-warn {
            display: flex; align-items: flex-start; gap: 8px; margin-bottom: 13px;
            background: rgba(239, 68, 68, .08); border: 1px solid rgba(239, 68, 68, .25);
            border-radius: 11px; padding: 9px 11px; font-size: 12px; color: #dc2626; line-height: 1.4;
        }

        .ds-actions { display: flex; gap: 9px; flex-wrap: wrap; }
        .ds-actions .btn { flex: 1; min-width: 130px; justify-content: center; }
        .ds-actions .btn:disabled { opacity: .45; cursor: not-allowed; filter: grayscale(.5); }
        /* Rejecting sends a group back to square one — it should read as destructive without
           shouting louder than the primary action beside it. */
        .ds-btn-reject {
            background: color-mix(in srgb, #dc2626 9%, transparent);
            border: 1px solid color-mix(in srgb, #dc2626 32%, transparent);
            color: #dc2626;
        }
        .ds-btn-reject:hover { background: color-mix(in srgb, #dc2626 16%, transparent); border-color: #dc2626; }

        /* "Logged & recorded" is the done pile — green reads as settled next to the amber queue. */
        .ds-title-wait i { color: #d97706; }
        .ds-title-wait .ds-count { background: rgba(217, 119, 6, .16); color: #b45309; }
        .ds-title-done i { color: #059669; }
        .ds-title-done .ds-count { background: rgba(16, 185, 129, .16); color: #047857; }
        .ds-logged-row .ds-logged-date { color: #047857; }
        .ds-logged-row:hover { background: rgba(16, 185, 129, .07); }

        .ds-reason-box { display: none; margin-top: 11px; }
        .ds-reason-box textarea {
            width: 100%; min-height: 62px; resize: vertical; padding: 9px 11px;
            border-radius: 10px; border: 1px solid var(--line);
            font-family: 'Inter', sans-serif; font-size: 13px; background: transparent; color: inherit;
        }
        .ds-reason-box .btn { margin-top: 8px; width: 100%; justify-content: center; }

        /* ---- nothing here, and that is fine ----
           Quill, then the line that says why. The mascot is 96px and decorative: alt="" so a
           screen reader gets the heading and skips the picture, which carries no information a
           sighted reader does not also get from the text. */
        .nx { display: flex; flex-direction: column; align-items: center; text-align: center;
            padding: 44px 24px 40px; }
        /* Inline SVG, not the PNG: the raster copy has a stray white shape beside the left
           wing. Sized here, and settled down a touch so a bright sticker reads as furniture
           on a screen that has nothing else on it. */
        .nx .quill-still { width: 96px; height: 96px; margin-bottom: 4px; opacity: .95; }
        .nx h3 { font-size: 16px; font-weight: 800; color: var(--ink); margin: 6px 0 0;
            letter-spacing: -.015em; }
        .nx p { font-size: 13px; color: var(--muted); line-height: 1.6; margin: 7px 0 0; max-width: 42ch; }
        .nx p a { color: var(--accent); font-weight: 700; }
        .nx .nx-go { display: inline-flex; align-items: center; gap: 7px; margin-top: 15px;
            font-size: 12.5px; font-weight: 750; text-decoration: none; color: var(--ink-soft);
            background: var(--surface); border: 1px solid var(--line); border-radius: var(--r-pill);
            padding: 8px 16px; transition: color .14s ease, border-color .14s ease, transform .14s ease; }
        .nx .nx-go:hover { color: var(--accent); transform: translateY(-1px);
            border-color: color-mix(in srgb, var(--accent) 42%, var(--line)); }
        .nx .nx-go i, .nx .nx-go svg { width: 14px; height: 14px; }
        /* The sidebar column is 330px — a 96px bird there IS the card. */
        .nx.nx-sm { padding: 10px 6px 6px; }
        .nx.nx-sm .quill-still { width: 60px; height: 60px; }
        .nx.nx-sm h3 { font-size: 13.5px; }
        .nx.nx-sm p { font-size: 12px; margin-top: 4px; }
        @media (prefers-reduced-motion: reduce) { .nx .nx-go { transition: none; } }
        /* ---- the two tabs, and the record behind the second one ----
           One track with two segments, not two loose buttons: the segment that is lit is where
           you are, which a pair of outlined buttons never quite says. */
        .ds-tabs { display: inline-flex; gap: 3px; margin: 0 0 16px; padding: 3px;
            background: var(--surface-2); border: 1px solid var(--line); border-radius: var(--r-pill); }
        .ds-tab { display: inline-flex; align-items: center; gap: 7px; cursor: pointer;
            font-size: 12.5px; font-weight: 700; color: var(--muted); white-space: nowrap;
            background: none; border: 0; border-radius: var(--r-pill);
            padding: 7px 16px; transition: color .14s ease, background .14s ease; }
        .ds-tab:hover { color: var(--ink); }
        .ds-tab.on { background: var(--surface); color: var(--ink); box-shadow: var(--shadow-sm); }
        .ds-tab i, .ds-tab svg { width: 14px; height: 14px; }
        .ds-tab span { font-size: 11px; font-weight: 800; opacity: .66; }

        /* The way back out of an opened section — a button, not a sentence with an arrow
           glued to it. It was a bare `&larr;` entity in a text link with its padding zeroed. */
        .ds-back { display: inline-flex; align-items: center; gap: 7px; margin: 0 0 14px;
            font-size: 12.5px; font-weight: 750; color: var(--ink-soft); text-decoration: none;
            background: var(--surface); border: 1px solid var(--line); border-radius: var(--r-pill);
            padding: 7px 15px 7px 12px;
            transition: color .14s ease, border-color .14s ease, transform .14s ease; }
        .ds-back i, .ds-back svg { width: 14px; height: 14px; }
        .ds-back:hover { color: var(--accent); transform: translateX(-2px);
            border-color: color-mix(in srgb, var(--accent) 42%, var(--line)); }

        .ds-rec-head { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin-bottom: 12px; }
        .ds-search { flex: 1; min-width: 200px; display: flex; align-items: center; gap: 8px;
            background: var(--surface); border: 1px solid var(--line); border-radius: var(--r-pill); padding: 8px 14px; }
        .ds-search i, .ds-search svg { width: 14px; height: 14px; color: var(--muted); flex-shrink: 0; }
        .ds-search input { flex: 1; min-width: 0; border: 0; outline: 0; background: none;
            font: inherit; font-size: 12.5px; color: var(--ink); }
        .ds-search input::placeholder { color: var(--muted); }
        .ds-filter { display: inline-flex; gap: 3px; background: var(--surface-2);
            border: 1px solid var(--line); border-radius: var(--r-pill); padding: 3px; }
        .ds-fb { display: inline-flex; align-items: center; gap: 6px; cursor: pointer;
            font-size: 12.5px; font-weight: 700; color: var(--muted); white-space: nowrap;
            background: none; border: 0; border-radius: var(--r-pill); padding: 6px 14px;
            transition: color .14s ease, background .14s ease; }
        .ds-fb:hover { color: var(--ink); }
        .ds-fb.on { background: var(--surface); color: var(--ink); box-shadow: var(--shadow-sm); }
        .ds-fb span { font-size: 11px; font-weight: 800; opacity: .66; }

        /* ---- the office calendar ----
           Rebuilt around two rules. FIXED SIX WEEKS, always, with the neighbouring months'
           days shown faintly: a 5-week month and a 6-week month used to be different heights,
           so the whole right-hand column jumped every time you paged. And CAPACITY ON THE FACE
           of the day — a flat colour wash said "something is booked here" and nothing else,
           when the question being asked of this calendar is always "how much room is left". */
        .ds-cal-top { display: flex; align-items: center; gap: 10px; margin-bottom: 12px; }
        .ds-cal-month { font-size: 16px; font-weight: 800; letter-spacing: -.02em; color: var(--ink); }
        .ds-cal-month b { font-weight: 800; }
        .ds-cal-month span { font-weight: 600; color: var(--muted); margin-left: 5px; }
        .ds-cal-nav { display: flex; gap: 4px; margin-left: auto; }
        .ds-cal-nav button {
            width: 27px; height: 27px; border-radius: 9px; border: 1px solid var(--line);
            background: var(--surface); color: var(--ink-soft); cursor: pointer; padding: 0;
            display: flex; align-items: center; justify-content: center;
            transition: border-color .14s ease, color .14s ease;
        }
        .ds-cal-nav button:hover:not(:disabled) { color: var(--accent); border-color: color-mix(in srgb, var(--accent) 42%, var(--line)); }
        .ds-cal-nav button:disabled { opacity: .3; cursor: not-allowed; }
        .ds-today-btn { font-size: 11px; font-weight: 750; color: var(--accent); cursor: pointer;
            background: color-mix(in srgb, var(--accent) 10%, var(--surface)); border: 0;
            border-radius: var(--r-pill); padding: 5px 11px; }
        .ds-today-btn:disabled { opacity: .35; cursor: default; }

        .ds-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 3px; }
        .ds-dow { text-align: center; font-size: 9px; font-weight: 800; color: var(--muted);
            letter-spacing: .08em; text-transform: uppercase; padding-bottom: 5px; }
        .ds-day {
            position: relative; height: 38px; border-radius: 10px; border: 1.5px solid transparent;
            background: var(--surface-2); font-family: inherit; overflow: hidden;
            font-size: 12px; font-weight: 700; color: var(--ink-soft); cursor: pointer; padding: 0 0 4px;
            display: flex; align-items: center; justify-content: center;
            transition: border-color .14s ease, transform .14s ease;
        }
        /* The neighbouring month's days. Present so the grid is always six rows, and dim enough
           to read as "not this month" without a second glance. */
        .ds-day.is-out { background: transparent; color: var(--muted); opacity: .3; cursor: default; }
        .ds-day:hover:not(.is-out):not(.is-past):not(.is-closed) {
            border-color: color-mix(in srgb, var(--accent) 45%, var(--line)); transform: translateY(-1px); }
        .ds-day.is-past { opacity: .32; cursor: default; }
        .ds-day.is-closed { opacity: .5; cursor: default; }
        .ds-day.is-today { box-shadow: inset 0 0 0 2px var(--accent); color: var(--accent); }
        .ds-day.is-taken { background: color-mix(in srgb, var(--success) 16%, var(--surface)); color: var(--success); }
        .ds-day.is-pending { background: color-mix(in srgb, var(--warning) 15%, var(--surface));
            color: color-mix(in srgb, var(--warning) 62%, var(--ink)); }
        /* Closed days are HATCHED as well as tinted — the one state that is a decision somebody
           made rather than a consequence of bookings, and the only one worth reading at a glance
           in a month where half the days carry some colour. */
        .ds-day.is-blocked { color: var(--danger);
            background: repeating-linear-gradient(135deg,
                color-mix(in srgb, var(--danger) 13%, var(--surface)) 0 5px,
                color-mix(in srgb, var(--danger) 5%, var(--surface)) 5px 10px); }

        /* How full the day is, drawn on the day. Two ticks, one per institution, because the
           two tens are separate pools — one full and one empty is NOT a half-full day, and a
           single bar would say exactly that. */
        .ds-load { position: absolute; left: 6px; right: 6px; bottom: 4px; display: flex; gap: 2px; }
        .ds-load i { flex: 1; height: 2.5px; border-radius: 2px; background: currentColor; opacity: .18; }
        .ds-load i.p { opacity: .55; }
        .ds-load i.f { opacity: 1; }

        /* What the month actually holds, instead of a key to the colours. The colours are
           legible on their own; how many days are still open is not, and it is the thing the
           Secretary is looking for. */
        /* One small line, and it has to STAY one line: this sits above the Upcoming card and
           every pixel it takes is a pixel of that card you cannot see. flex-wrap: nowrap makes
           wrapping impossible rather than unlikely; the labels are short for the same reason. */
        .ds-sum { display: flex; flex-wrap: nowrap; gap: 5px; align-items: center;
            margin-top: 10px; padding-top: 9px; border-top: 1px solid var(--line); }
        .ds-sum b { display: inline-flex; align-items: center; gap: 5px; min-width: 0;
            font-size: 10px; font-weight: 700; color: var(--muted); white-space: nowrap; }
        .ds-sum b i { width: 6px; height: 6px; border-radius: 2px; display: block; flex-shrink: 0; }
        .ds-sum b em { font-style: normal; font-weight: 800; color: var(--ink); }

        .ds-up-row {
            display: flex; align-items: center; gap: 9px; padding: 8px 0;
            border-bottom: 1px solid var(--line); font-size: 12.5px;
        }
        .ds-up-row:last-child { border-bottom: none; }
        .ds-up-date { font-family: 'JetBrains Mono', monospace; font-weight: 700; font-size: 11px; flex-shrink: 0; opacity: .8; }
        .ds-up-name { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

        .ds-logged-row {
            display: flex; align-items: center; gap: 10px; padding: 9px 4px; width: 100%;
            border: none; border-bottom: 1px solid var(--line);
            background: none; color: inherit; font-family: inherit; font-size: 12.5px;
            cursor: pointer; text-align: left; border-radius: 8px;
        }
        .ds-logged-row:last-child { border-bottom: none; }
        .ds-logged-row:hover { background: var(--surface-2, #f8fafc); }
        .ds-logged-date { font-family: 'JetBrains Mono', monospace; font-weight: 700; flex-shrink: 0; font-size: 11.5px; }
        .ds-logged-grp { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .ds-chev { opacity: .4; flex-shrink: 0; }

        .ds-empty {
            text-align: center; padding: 30px 18px; border-radius: 14px;
            border: 1px dashed var(--line); opacity: .7; font-size: 13px; line-height: 1.5;
        }
        .ds-empty strong { display: block; font-size: 14px; margin-bottom: 5px; opacity: .9; }

        /* ── Requested: square cards on a grid ───────────────────────────────────
           These used to be one full-bleed card per request, so three requests filled a
           screen and the date — the only thing this queue is actually about — was a chip
           halfway down it. Now the date IS the card's head and the cards tile the way the
           checklist ones do. */
        .ds-req-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(312px, 1fr)); gap: 14px; align-items: start; }
        .ds-reqcard {
            display: flex; flex-direction: column; overflow: hidden;
            border: 1px solid var(--line); border-radius: 16px;
            background: var(--surface, #fff);
            transition: box-shadow .18s ease, border-color .18s ease;
        }
        .ds-reqcard:hover { box-shadow: 0 14px 30px -20px rgba(15, 23, 42, .5); }
        .ds-reqcard.is-conflict { border-color: rgba(239, 68, 68, .4); }

        .ds-rc-head {
            display: flex; align-items: center; gap: 13px; padding: 14px 15px;
            background: linear-gradient(155deg, rgba(217, 119, 6, .13), transparent 72%);
            border-bottom: 1px solid var(--line);
        }
        .ds-reqcard.is-conflict .ds-rc-head { background: linear-gradient(155deg, rgba(239, 68, 68, .13), transparent 72%); }
        .ds-rc-daybox {
            width: 58px; flex-shrink: 0; text-align: center; padding: 7px 0; border-radius: 13px;
            background: var(--surface, #fff); border: 1px solid rgba(217, 119, 6, .32);
        }
        .ds-rc-daybox .d { display: block; font-family: 'JetBrains Mono', monospace; font-size: 22px; font-weight: 800; line-height: 1; color: #b45309; }
        .ds-rc-daybox .m { display: block; font-size: 9px; font-weight: 800; text-transform: uppercase; letter-spacing: .07em; opacity: .7; margin-top: 4px; }
        .ds-rc-when { min-width: 0; flex: 1; }
        .ds-rc-when strong { display: block; font-size: 13.5px; font-weight: 750; line-height: 1.3; }
        .ds-rc-when span { display: block; font-size: 11px; opacity: .62; margin-top: 3px; }

        .ds-rc-body { padding: 13px 15px; display: flex; flex-direction: column; gap: 11px; flex: 1; }
        .ds-rc-who { display: flex; align-items: center; gap: 11px; min-width: 0; }
        .ds-rc-who .ds-avatar { width: 36px; height: 36px; border-radius: 11px; font-size: 13px; }
        .ds-rc-whotext { min-width: 0; flex: 1; }
        .ds-rc-whotext strong { display: block; font-size: 13.5px; font-weight: 750; line-height: 1.3; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .ds-rc-whotext span { display: block; font-size: 11px; opacity: .65; margin-top: 2px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .ds-rc-chips { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
        .ds-rc-foot { margin-top: auto; display: flex; gap: 8px; }
        .ds-rc-foot .btn { flex: 1; min-width: 0; justify-content: center; font-size: 12.5px; padding: 9px 10px; }
        .ds-rc-foot .btn:disabled { opacity: .45; cursor: not-allowed; filter: grayscale(.5); }
        .ds-reqcard .ds-reqtoggle, .ds-reqcard .ds-filebtn { margin-bottom: 0; }
        .ds-reqcard .ds-reqlist { margin: 0; }
        .ds-reqcard .ds-warn { margin-bottom: 0; font-size: 11.5px; }

        /* The School ID chip. Restated here rather than shared: this screen renders its own
           document and exits, so admin_module_dynamic.php's style block never reaches it. */
        .school-id-chip {
            display: inline-flex; align-items: center; gap: 4px;
            font-size: 10.5px; font-weight: 700; letter-spacing: .02em;
            padding: 2px 7px; border-radius: 20px; white-space: nowrap;
            background: rgba(100, 116, 139, .12); color: var(--muted);
        }
        .school-id-chip i { width: 11px; height: 11px; flex-shrink: 0; }

        /* ── "View names": the group behind the leader ──────────────────────────
           Same component the plagiarism queue uses; the markup comes from
           ws_names_markup() so the two can never drift. */
        .ws-names-btn {
            display: inline-flex; align-items: center; gap: 5px; padding: 2px 8px;
            border: 1px solid var(--line); border-radius: 20px; background: var(--surface, #fff);
            font-family: inherit; font-size: 10px; font-weight: 700; color: var(--muted, #94a3b8); cursor: pointer;
            transition: color .16s ease, border-color .16s ease;
        }
        .ws-names-btn:hover, .ws-names-btn.is-open { color: inherit; border-color: var(--muted, #94a3b8); }
        .ws-names-btn i { width: 11px; height: 11px; }
        .ws-names-n { font-weight: 800; opacity: .7; }
        .ws-names-panel {
            padding: 10px 12px; border: 1px solid var(--line);
            border-radius: 10px; background: var(--surface-2, #f8fafc);
            flex-direction: column; gap: 8px;
        }
        .ws-name-row { display: flex; align-items: center; gap: 9px; }
        .ws-name-row img { width: 26px; height: 26px; border-radius: 50%; flex-shrink: 0; background: var(--surface, #fff); object-fit: cover; }
        .ws-name-text { display: flex; flex-direction: column; min-width: 0; }
        .ws-name-text strong { font-size: 12px; font-weight: 700; }
        .ws-name-text span { font-size: 10px; opacity: .65; word-break: break-word; }
        .ws-names-solo { font-size: 10.5px; font-style: italic; opacity: .6; }

        /* ── Upcoming rows, and the record rows ────────────────────────────────
           Both are the same object — a logged schedule — so they are the same row, and both
           open the same detail window. */
        .ds-uprow {
            display: flex; align-items: center; gap: 11px; width: 100%; padding: 9px 8px;
            border: none; border-bottom: 1px solid var(--line); border-radius: 9px;
            background: none; color: inherit; font-family: inherit; text-align: left; cursor: pointer;
            transition: background .15s ease;
        }
        .ds-uprow:last-child { border-bottom: none; }
        .ds-uprow:hover { background: rgba(16, 185, 129, .07); }
        .ds-uprow-date {
            width: 42px; flex-shrink: 0; text-align: center; padding: 5px 0; border-radius: 9px;
            background: var(--surface-2, #f1f5f9);
        }
        .ds-uprow-date .d { display: block; font-family: 'JetBrains Mono', monospace; font-size: 14px; font-weight: 800; line-height: 1; }
        .ds-uprow-date .m { display: block; font-size: 8.5px; font-weight: 800; text-transform: uppercase; letter-spacing: .06em; opacity: .65; margin-top: 3px; }
        .ds-uprow.is-past .ds-uprow-date { opacity: .5; }
        .ds-uprow-id { flex: 1; min-width: 0; }
        .ds-uprow-id strong { display: block; font-size: 12.5px; font-weight: 700; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .ds-uprow-id span { display: block; font-size: 10.5px; opacity: .6; margin-top: 2px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .ds-when-chip {
            flex-shrink: 0; font-size: 9.5px; font-weight: 800; padding: 3px 8px; border-radius: 999px;
            background: var(--surface-2, #f1f5f9); opacity: .8;
        }
        .ds-when-chip.soon { background: rgba(217, 119, 6, .18); color: #b45309; opacity: 1; }
        .ds-when-chip.today { background: rgba(239, 68, 68, .16); color: #dc2626; opacity: 1; }

        /* ── Booking detail: a record card, not a list of key/value rows ────────── */
        .ds-bk-hero {
            display: flex; align-items: center; gap: 14px; padding: 15px;
            border-radius: 15px; margin-bottom: 14px;
            background: linear-gradient(150deg, rgba(16, 185, 129, .14), transparent 74%);
            border: 1px solid rgba(16, 185, 129, .28);
        }
        .ds-bk-hero.is-past { background: linear-gradient(150deg, rgba(100, 116, 139, .14), transparent 74%); border-color: var(--line); }
        .ds-bk-daybox {
            width: 66px; flex-shrink: 0; text-align: center; padding: 9px 0; border-radius: 14px;
            background: var(--surface, #fff); border: 1px solid rgba(16, 185, 129, .3);
        }
        .ds-bk-hero.is-past .ds-bk-daybox { border-color: var(--line); }
        .ds-bk-daybox .d { display: block; font-family: 'JetBrains Mono', monospace; font-size: 25px; font-weight: 800; line-height: 1; }
        .ds-bk-daybox .m { display: block; font-size: 9px; font-weight: 800; text-transform: uppercase; letter-spacing: .07em; opacity: .7; margin-top: 4px; }
        .ds-bk-herotext { min-width: 0; flex: 1; }
        .ds-bk-herotext strong { display: block; font-size: 15px; font-weight: 780; line-height: 1.3; }
        .ds-bk-herotext span { display: block; font-size: 11.5px; opacity: .68; margin-top: 4px; }
        .ds-bk-facts { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 10px; margin-bottom: 14px; }
        .ds-bk-fact {
            padding: 10px 12px; border-radius: 12px; min-width: 0;
            background: var(--surface-2, #f8fafc); border: 1px solid var(--line);
        }
        .ds-bk-fact span { display: block; font-size: 9px; font-weight: 800; letter-spacing: .06em; text-transform: uppercase; opacity: .55; margin-bottom: 4px; }
        .ds-bk-fact strong { display: block; font-size: 12.5px; font-weight: 700; word-break: break-word; }
        .ds-bk-block { margin-bottom: 14px; }
        .ds-bk-blocktitle { font-size: 10px; font-weight: 800; letter-spacing: .06em; text-transform: uppercase; opacity: .55; margin-bottom: 7px; }
        .ds-bk-roster { display: flex; flex-direction: column; gap: 8px; padding: 11px 12px; border-radius: 12px; background: var(--surface-2, #f8fafc); border: 1px solid var(--line); }

        /* ---- modals ---- */
        .ds-modal {
            position: fixed; inset: 0; z-index: 950; display: none;
            align-items: center; justify-content: center; padding: 24px;
            background: rgba(15, 23, 42, .5); backdrop-filter: blur(8px); -webkit-backdrop-filter: blur(8px);
        }
        .ds-modal.open { display: flex; }
        .ds-modal-box {
            background: var(--surface, #fff); border-radius: 18px; width: 100%; max-width: 460px;
            max-height: 84vh; overflow: auto; padding: 20px;
            box-shadow: 0 26px 60px -18px rgba(0, 0, 0, .45);
            animation: dsPop .24s cubic-bezier(.2, .9, .3, 1.15);
        }
        .ds-modal-box.wide { max-width: 620px; }
        .ds-modal-box.planner { max-width: min(1120px, 95vw); max-height: 93vh; }
        @keyframes dsPop { from { opacity: 0; transform: translateY(14px) scale(.97); } to { opacity: 1; transform: none; } }
        .ds-modal-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; margin-bottom: 16px; }
        .ds-modal-head h3 { margin: 0 0 3px; font-size: 16px; font-weight: 750; }
        .ds-modal-head p { margin: 0; font-size: 12.5px; opacity: .7; }
        .ds-x {
            background: none; border: none; cursor: pointer; padding: 4px; opacity: .5;
            display: flex; flex-shrink: 0; color: inherit;
        }
        .ds-x:hover { opacity: 1; }

        .ds-kv { display: flex; gap: 12px; padding: 9px 0; border-bottom: 1px solid var(--line); font-size: 13px; }
        .ds-kv:last-of-type { border-bottom: none; }
        .ds-kv .k { width: 116px; flex-shrink: 0; opacity: .6; font-size: 12px; }
        .ds-kv .v { flex: 1; min-width: 0; font-weight: 650; word-break: break-word; }

        .pl-top { display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; }
        .pl-month { font-size: 15px; font-weight: 800; }
        .pl-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 4px; }
        .pl-dow {
            text-align: center; font-size: 10px; font-weight: 750; opacity: .5;
            text-transform: uppercase; letter-spacing: .04em; padding-bottom: 4px;
        }
        /* A cell is a FIXED height and a chip is ONE line. A research title runs to fifteen
           words, and letting three of them set the row height made every row a different size
           and the month a different size again — the grid stopped being a grid. At most three
           chips show; the rest is a count, and the day opens for the full list. */
        .pl-cell {
            height: 112px; border-radius: 12px; padding: 7px;
            border: 1px solid var(--line); background: var(--surface);
            display: flex; flex-direction: column; gap: 4px; overflow: hidden;
        }
        .pl-cell.is-out { border-color: transparent; background: transparent; }
        .pl-cell.is-out .pl-num { opacity: .28; }
        .pl-cell.is-past { opacity: .5; }
        .pl-cell.is-sunday { background: var(--surface-2); }
        .pl-cell.is-today { border-color: var(--accent); box-shadow: inset 0 0 0 1px var(--accent); }
        .pl-head { display: flex; align-items: center; gap: 5px; }
        .pl-num { font-size: 12px; font-weight: 800; color: var(--ink-soft); }
        .pl-cell.is-today .pl-num { color: var(--accent); }
        /* The day's load, in the corner where a diary would put it. */
        .pl-cap { margin-left: auto; font-size: 9.5px; font-weight: 800; letter-spacing: .02em;
            color: var(--muted); background: var(--surface-2); border-radius: var(--r-pill); padding: 1px 6px;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis; min-width: 0; }
        .pl-cap.full { color: var(--success); background: color-mix(in srgb, var(--success) 15%, var(--surface)); }
        .pl-chips { display: flex; flex-direction: column; gap: 3px; min-height: 0; overflow: hidden; }
        .pl-chip {
            font-size: 10px; font-weight: 700; line-height: 1.35; padding: 4px 7px;
            border-radius: 7px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
            flex-shrink: 0; border: 0; font-family: inherit; text-align: left; width: 100%;
        }
        .pl-chip.c-sched { background: color-mix(in srgb, var(--success) 16%, var(--surface));
            color: color-mix(in srgb, var(--success) 62%, var(--ink)); cursor: pointer; }
        .pl-chip.c-pend { background: color-mix(in srgb, var(--warning) 16%, var(--surface));
            color: color-mix(in srgb, var(--warning) 62%, var(--ink)); cursor: pointer; }
        .pl-chip.c-blocked { background: color-mix(in srgb, var(--danger) 13%, var(--surface)); color: var(--danger); }
        .pl-chip[data-b]:hover { filter: brightness(.95); }
        .pl-more { font-size: 9.5px; font-weight: 750; color: var(--muted); padding-left: 3px; flex-shrink: 0; }
        @media (max-width: 720px) {
            .pl-cell { height: 84px; padding: 5px; }
            .pl-chip { font-size: 9px; padding: 3px 5px; }
        }

        .pl-key { display: flex; flex-wrap: wrap; gap: 4px 10px; margin-left: auto; margin-right: 10px; }
        .pl-key b { display: inline-flex; align-items: center; gap: 6px; font-size: 10.5px;
            font-weight: 700; color: var(--muted); white-space: nowrap; }
        .pl-key b i { width: 7px; height: 7px; border-radius: 2.5px; display: block; }
        @media (max-width: 720px) { .pl-key { display: none; } }

        .ds-planner-month { font-size: 12px; font-weight: 750; opacity: .55; text-transform: uppercase; letter-spacing: .05em; margin: 16px 0 7px; }
        .ds-planner-month:first-child { margin-top: 0; }
        .ds-planner-row { display: flex; align-items: center; gap: 11px; padding: 9px 11px; border-radius: 11px; border: 1px solid var(--line); margin-bottom: 6px; font-size: 12.5px; }
        .ds-planner-row .d { font-family: 'JetBrains Mono', monospace; font-weight: 800; font-size: 15px; width: 30px; text-align: center; flex-shrink: 0; }
        .ds-planner-row .b { flex: 1; min-width: 0; }
        .ds-planner-row .b small { display: block; opacity: .6; font-size: 11px; margin-top: 1px; }
        .ds-planner-row.t-taken { border-color: rgba(16, 185, 129, .4); background: rgba(16, 185, 129, .07); }
        .ds-planner-row.t-pending { border-color: rgba(217, 119, 6, .4); background: rgba(217, 119, 6, .07); }
        .ds-planner-row.t-blocked { border-color: rgba(239, 68, 68, .35); background: rgba(239, 68, 68, .06); }

        /* ================= SCHEDULING, 2026-09-29 redesign (.sc-*) =================
           Layout + look only; the ids the script reads are untouched. Tokens, so dark follows.
           The feature card is brand purple in both themes, like Approvals. */
        body.admin-module { --sc-display: "Outfit", var(--font-ui); }
        [hidden] { display: none !important; }
        .sc-head { margin-bottom: 16px; }
        .sc-eyebrow { font-size: 12px; font-weight: 700; color: var(--muted); }
        .sc-head h1 { font-family: var(--sc-display); font-size: 32px; font-weight: 700; letter-spacing: -.02em; color: var(--ink); line-height: 1.15; margin: 2px 0 6px; }
        .sc-head p { font-size: 13.5px; color: var(--muted); max-width: 520px; line-height: 1.5; }
        .sc-wrap { grid-template-columns: minmax(0, 1fr) 330px; gap: 20px; }
        .sc-left { min-width: 0; display: flex; flex-direction: column; gap: 16px; }
        .sc-left > #dsViewLog, .sc-left > #dsViewRecord { min-width: 0; }

        /* the To log / Record pill (the Approvals pill look) */
        .sc-tabs { display: inline-flex; align-self: flex-start; gap: 4px; padding: 4px; margin: 0; border-radius: 999px; background: var(--surface-3); border: 0; }
        .sc-tabs .ds-tab { gap: 8px; padding: 8px 16px; border-radius: 999px; font-size: 13px; font-weight: 600; color: var(--ink); background: transparent; border: 0; }
        .sc-tabs .ds-tab.on { background: var(--surface); box-shadow: var(--shadow-1); font-weight: 700; }
        .sc-tabs .ds-tab span { font-size: 11.5px; font-weight: 800; color: var(--accent); opacity: 1; }

        /* the section to log */
        .sc-date { flex-shrink: 0; width: 54px; padding: 7px 0; border-radius: 14px; display: flex; flex-direction: column; align-items: center;
            line-height: 1.1; background: var(--surface); color: var(--ink); box-shadow: var(--shadow-1); }
        .sc-date b { font-family: var(--sc-display); font-size: 23px; font-weight: 700; }
        .sc-date small { font-size: 10.5px; font-weight: 600; color: var(--muted); }
        .sc-date.light { width: 66px; padding: 9px 0; background: #fff; color: var(--sh-hero-a, #2e1065); box-shadow: none; }
        .sc-date.light b { font-size: 28px; }
        .sc-date.light small { color: #6b5b95; }
        .sc-feature { position: relative; overflow: hidden; border-radius: 26px; padding: 22px 24px; color: #fff;
            background: linear-gradient(120deg, var(--sh-hero-a, #2e1065), var(--sh-hero-b, #4c1d95)); box-shadow: 0 18px 40px -20px rgba(46, 16, 101, .7); }
        .sc-feature::after { content: ''; position: absolute; right: -10%; top: -45%; width: 42%; height: 140%;
            border-radius: 50%; background: rgba(255, 255, 255, .07); pointer-events: none; }
        .sc-feature > * { position: relative; z-index: 1; }
        .sc-feature-top { display: flex; align-items: center; gap: 16px; }
        .sc-feature-id h3 { font-family: var(--sc-display); font-size: 23px; font-weight: 700; letter-spacing: -.01em; color: #fff; }
        .sc-feature-id p { font-size: 12.5px; color: rgba(255, 255, 255, .75); margin-top: 3px; }
        .sc-sigs { display: flex; flex-wrap: wrap; gap: 8px; margin: 16px 0 18px; }
        .sc-sigs span { display: inline-flex; align-items: center; gap: 7px; padding: 5px 12px 5px 5px; border-radius: 999px;
            background: rgba(255, 255, 255, .12); font-size: 12px; color: rgba(255, 255, 255, .8); }
        .sc-sigs i, .sc-sigs svg { width: 20px; height: 20px; padding: 4px; border-radius: 50%; background: #86efac; color: #14532d; stroke-width: 3; }
        .sc-sigs b { color: #fff; font-weight: 700; }
        .sc-feature-foot { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; }
        .sc-feature-foot > span { font-size: 12.5px; color: rgba(255, 255, 255, .8); }
        .sc-feature-foot > span.bad { color: #fecaca; font-weight: 700; }
        .sc-btn-light { display: inline-flex; align-items: center; gap: 6px; padding: 10px 18px; border-radius: 999px;
            background: #fff; color: var(--sh-hero-a, #2e1065); font-size: 13px; font-weight: 700; text-decoration: none; }
        .sc-btn-light:hover { background: #f3e8ff; }
        .sc-btn-light i, .sc-btn-light svg { width: 15px; height: 15px; }
        .sc-also { font-size: 13px; font-weight: 700; color: var(--ink); margin: 16px 0 8px; }
        .sc-also span { font-size: 11px; font-weight: 800; color: var(--accent); margin-left: 4px; }
        .sc-rows { display: flex; flex-direction: column; gap: 8px; }
        .sc-row { display: flex; align-items: center; gap: 14px; padding: 12px 16px; border-radius: 18px; background: var(--surface-3); text-decoration: none; color: inherit; }
        .sc-row:hover { background: color-mix(in srgb, var(--accent) 10%, var(--surface-3)); }
        .sc-row-id { flex: 1; min-width: 0; display: flex; flex-direction: column; }
        .sc-row-id b { font-family: var(--sc-display); font-size: 16px; font-weight: 600; color: var(--ink); }
        .sc-row-id small { font-size: 12px; color: var(--muted); }
        .sc-row-go { display: inline-flex; align-items: center; gap: 4px; font-size: 12.5px; font-weight: 700; color: var(--accent); }
        .sc-row-go i, .sc-row-go svg { width: 14px; height: 14px; }

        /* cards */
        .sc-card { background: var(--surface); border: 1px solid var(--line); border-radius: 24px; padding: 18px 20px; }
        .sc-card-head { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-bottom: 12px; }
        .sc-card-head h2 { font-family: var(--sc-display); font-size: 18px; font-weight: 600; color: var(--ink); }
        .sc-link { background: none; border: 0; cursor: pointer; font: inherit; font-size: 12.5px; font-weight: 700; color: var(--accent); padding: 0; }
        .sc-link:hover { text-decoration: underline; }

        /* coming up, by day */
        .sc-day { display: grid; grid-template-columns: 120px minmax(0, 1fr); gap: 16px; padding: 12px 0; border-top: 1px solid var(--line); }
        .sc-day:first-of-type { border-top: 0; padding-top: 2px; }
        .sc-day-when { display: flex; flex-direction: column; }
        .sc-day-when b { font-size: 13.5px; color: var(--ink); }
        .sc-day-when.today b { color: color-mix(in srgb, var(--danger) 78%, var(--ink)); }
        .sc-day-when.soon b { color: color-mix(in srgb, var(--warning) 72%, var(--ink)); }
        .sc-day-when small { font-size: 11.5px; color: var(--muted); }
        .sc-day-list { display: flex; flex-direction: column; min-width: 0; }
        .sc-coming .ds-uprow.sc-up { all: unset; box-sizing: border-box; cursor: pointer; display: flex; align-items: baseline; justify-content: space-between;
            gap: 12px; padding: 5px 8px; margin: 0 -8px; border-radius: 10px; }
        .sc-coming .ds-uprow.sc-up:hover { background: var(--surface-2); }
        .sc-up b { font-size: 13px; font-weight: 700; color: var(--ink); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; min-width: 0; }
        .sc-up small { font-size: 11.5px; color: var(--muted); white-space: nowrap; flex-shrink: 0; }
        .sc-more { align-self: flex-start; margin-top: 3px; font-size: 11.5px; }

        /* the office calendar */
        .sc-backs { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 14px; }
        .sc-back { display: inline-flex; align-items: center; gap: 8px; padding: 9px 16px; border-radius: 999px;
            background: var(--surface-3); color: var(--ink); font-size: 13px; font-weight: 700; text-decoration: none; }
        .sc-back:hover { background: color-mix(in srgb, var(--accent) 14%, var(--surface-3)); }
        .sc-back.ghost { background: transparent; border: 1px solid var(--line); font-weight: 600; }
        .sc-back i, .sc-back svg { width: 15px; height: 15px; }
        .sc-cal { padding: 18px 18px 16px; }
        .sc-cal .sc-card-head h2 { white-space: nowrap; font-size: 17px; }
        .sc-cal .sc-card-head { gap: 6px; }
        .sc-cal-ctl { display: flex; align-items: center; gap: 6px; }
        .sc-cal .ds-today-btn { padding: 6px 13px; font-size: 12px; font-weight: 700; color: var(--ink); background: transparent;
            border: 1px solid color-mix(in srgb, var(--accent) 35%, var(--line)); }
        .sc-cal .ds-cal-nav { margin-left: 0; }
        .sc-cal .ds-cal-nav button { width: 32px; height: 32px; border-radius: 50%; border: 0; background: transparent; color: var(--ink); }
        .sc-cal .ds-cal-nav button:last-child { background: var(--surface-3); }
        .sc-cal .ds-cal-month { display: block; margin: 2px 0 12px; font-family: var(--sc-display); font-size: 15px; font-weight: 600; }
        .sc-cal .ds-cal-month b { font-weight: 600; }
        .sc-cal .ds-cal-month span { font-weight: 600; color: var(--ink); margin-left: 5px; }
        .sc-cal .ds-grid { gap: 4px; }
        .sc-cal .ds-dow { font-size: 10.5px; font-weight: 600; letter-spacing: 0; text-transform: none; padding-bottom: 6px; }
        .sc-cal .ds-day { height: 38px; border-radius: 11px; background: transparent; font-size: 12.5px; font-weight: 600; color: var(--ink); }
        .sc-cal .ds-day.is-out { opacity: .35; }
        .sc-cal .ds-day.is-past { opacity: .38; }
        .sc-cal .ds-day.is-pending { background: color-mix(in srgb, var(--warning) 22%, var(--surface)); color: color-mix(in srgb, var(--warning) 45%, var(--ink)); font-weight: 800; }
        .sc-cal .ds-day.is-taken { background: var(--sh-hero-b, #4c1d95); color: #fff; font-weight: 800; }
        .sc-cal .ds-day.is-blocked { color: var(--muted);
            background: repeating-linear-gradient(135deg, var(--surface-3) 0 5px, var(--surface) 5px 10px); }
        .sc-cal .ds-day.is-today { box-shadow: inset 0 0 0 2px var(--accent); }
        .sc-cal .ds-load { left: 30%; right: 30%; bottom: 5px; }
        .sc-cal .ds-day.is-pending .ds-load i { background: color-mix(in srgb, var(--warning) 80%, #92400e); }
        .sc-cal .ds-load i { height: 3px; }
        .sc-cal .ds-sum { margin-top: 12px; }
        .sc-cal .ds-sum b { font-size: 11px; }
        .sc-hint { font-size: 11.5px; color: var(--muted); margin: 10px 0 0; line-height: 1.45; }
        .sc-cal-acts { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 12px; }
        .sc-cal-acts form { margin: 0; }
        .sc-pill { display: inline-flex; align-items: center; gap: 6px; padding: 8px 14px; border-radius: 999px; border: 0; cursor: pointer;
            background: var(--surface-3); color: var(--ink); font: inherit; font-size: 12px; font-weight: 700; }
        .sc-pill:hover { background: color-mix(in srgb, var(--accent) 14%, var(--surface-3)); }
        .sc-pill i, .sc-pill svg { width: 14px; height: 14px; }

        /* Side by side down to 900px, like the concept; the old one-column switch at 1100 sent the
           calendar under the list in an ordinary module window. */
        @media (max-width: 1100px) { .sc-wrap { grid-template-columns: minmax(0, 1fr) 300px; } }
        @media (max-width: 900px)  { .sc-wrap { grid-template-columns: minmax(0, 1fr); } }
        @media (max-width: 620px) {
            .sc-head h1 { font-size: 26px; }
            .sc-feature { padding: 18px; border-radius: 22px; }
            .sc-day { grid-template-columns: 1fr; gap: 6px; }
            .sc-up small { display: none; }
        }

        /* ================= LOG A SECTION + the windows (2026-09-29, Claude Design) =================
           .lg-* the opened section · .dd-* a day's detail · .up-* Upcoming · .pl-* the planner.
           Tokens throughout; tiles and the "hot" pieces are brand purple in both themes. */
        .bd-tags { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 10px; }
        .bd-tags span { padding: 5px 11px; border-radius: 9px; background: var(--surface); font-size: 12px; font-weight: 600; color: var(--ink); }
        .bd-datecard { display: flex; align-items: center; gap: 14px; padding: 12px 20px 12px 12px; border-radius: 22px; background: var(--surface); box-shadow: var(--shadow-2); }
        .bd-datecard > div { display: flex; flex-direction: column; gap: 2px; }
        .bd-datecard > div > b { font-family: var(--sc-display); font-size: 18px; font-weight: 600; color: var(--ink); }
        .bd-datecard > div > small { font-size: 11.5px; color: var(--muted); }
        .bd-tile { width: 58px; padding: 8px 0; border-radius: 15px; display: flex; flex-direction: column; align-items: center; line-height: 1.1;
            background: linear-gradient(135deg, var(--sh-hero-a, #2e1065), var(--sh-hero-b, #4c1d95)); color: #fff; }
        .bd-tile b { font-family: var(--sc-display); font-size: 25px; }
        .bd-tile small { font-size: 10.5px; font-weight: 700; opacity: .85; }
        .bd-slot { width: 40px; padding: 5px 0; border-radius: 11px; flex-shrink: 0; display: flex; flex-direction: column; align-items: center; line-height: 1.1;
            background: linear-gradient(135deg, var(--sh-hero-a, #2e1065), var(--sh-hero-b, #4c1d95)); color: #fff; }
        .bd-slot small { font-size: 9px; font-weight: 700; opacity: .85; }
        .bd-slot b { font-family: var(--sc-display); font-size: 18px; }

        .lg-hero { display: grid; grid-template-columns: minmax(0, 1fr) auto; align-items: center; gap: 20px;
            padding: 22px 24px; border-radius: 26px; background: var(--surface-3); margin-bottom: 16px; }
        .lg-hero-id { min-width: 0; }
        .lg-hero h1 { font-family: var(--sc-display); font-size: 30px; font-weight: 700; letter-spacing: -.02em; color: var(--ink); line-height: 1.15; margin-top: 2px; }
        .lg-chain { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 14px; }
        .lg-chain span { display: inline-flex; align-items: center; gap: 7px; padding: 5px 12px 5px 5px; border-radius: 999px; background: var(--surface); font-size: 12px; color: var(--muted); }
        .lg-chain b { color: var(--ink); font-weight: 700; }
        .lg-chain i, .lg-chain svg { width: 20px; height: 20px; padding: 4px; border-radius: 50%; stroke-width: 3;
            background: color-mix(in srgb, var(--success) 20%, var(--surface)); color: color-mix(in srgb, var(--success) 72%, var(--ink)); }
        .lg-chain .you { background: var(--sh-hero-a, #2e1065); color: rgba(255, 255, 255, .8); }
        .lg-chain .you b { color: #fff; }
        .lg-dot { display: grid !important; place-items: center; background: #fff !important; padding: 0 !important; }
        .lg-dot::after { content: ''; width: 8px; height: 8px; border-radius: 50%; background: var(--sh-hero-b, #4c1d95); }

        .lg-groups { padding: 18px 18px 14px; margin-bottom: 14px; }
        .lg-groups .sc-card-head { flex-wrap: wrap; }
        .lg-groups .sc-card-head h2 span { color: var(--muted); font-weight: 500; margin-left: 4px; }
        .lg-groups .sc-card-head small { font-size: 12px; color: var(--muted); }
        .lg-item + .lg-item { margin-top: 8px; }
        .lg-row { display: flex; align-items: center; gap: 12px; padding: 10px 14px; border-radius: 16px; background: var(--surface-3); cursor: pointer;
            transition: opacity .18s ease, background .18s ease; }
        .lg-row:hover { background: color-mix(in srgb, var(--accent) 8%, var(--surface-3)); }
        .lg-row .ds-keep { width: 18px; height: 18px; accent-color: var(--accent); flex-shrink: 0; cursor: pointer; }
        /* Unticked = coming off this date: faded, so the list still reads the day as it will be. */
        .lg-item:has(.ds-keep:not(:checked)) .lg-row { opacity: .5; background: var(--surface); box-shadow: inset 0 0 0 1px var(--line); }
        .lg-av { width: 32px; height: 32px; border-radius: 10px; flex-shrink: 0; display: grid; place-items: center;
            font-family: var(--sc-display); font-size: 14px; font-weight: 700; color: #3b0764; }
        .lg-id { flex: 1; min-width: 0; display: flex; flex-direction: column; }
        .lg-id b { font-size: 13.5px; color: var(--ink); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .lg-id small { font-size: 11.5px; color: var(--muted); }
        .lg-chk { padding: 4px 10px; border-radius: 999px; font-size: 11px; font-weight: 800; white-space: nowrap;
            background: color-mix(in srgb, var(--warning) 20%, var(--surface)); color: color-mix(in srgb, var(--warning) 60%, var(--ink)); }
        .lg-chk.ok { background: color-mix(in srgb, var(--success) 16%, var(--surface)); color: color-mix(in srgb, var(--success) 70%, var(--ink)); }
        .lg-row .ws-names-btn { flex-shrink: 0; padding: 6px 12px; border-radius: 999px; border: 1px solid var(--line); background: var(--surface);
            font-size: 12px; font-weight: 700; color: var(--ink); cursor: pointer; display: inline-flex; align-items: center; gap: 6px; }
        .lg-row .ws-names-btn i, .lg-row .ws-names-btn svg { width: 13px; height: 13px; }
        .lg-row .ws-names-n { font-size: 10.5px; color: var(--muted); }
        .lg-item .ws-names-panel { margin: 6px 0 0 60px; }

        /* The floating bar: the action for the whole section follows you down the list. */
        .ds-logbar.lg-bar { position: sticky; bottom: 16px; z-index: 60; width: 100%; display: flex; align-items: center; gap: 10px; padding: 12px 12px 12px 22px; margin: 0;
            border-radius: 999px; background: linear-gradient(120deg, var(--sh-hero-a, #2e1065), var(--sh-hero-b, #4c1d95)); color: #fff; border: 0;
            box-shadow: 0 18px 44px -12px rgba(46, 16, 101, .65); }
        .lg-bar .ds-logbar-text { flex: 1; min-width: 0; display: flex; flex-direction: column; font-size: 13px; color: rgba(255, 255, 255, .85); }
        .lg-bar .ds-logbar-text strong { font-family: var(--sc-display); font-size: 16px; color: #fff; margin-right: 4px; }
        .lg-bar .ds-log-drop { font-size: 11.5px; color: #fde68a; }
        .lg-send { padding: 10px 18px; border-radius: 999px; border: 1.5px solid rgba(255, 255, 255, .5); background: transparent; color: #fff;
            font: inherit; font-size: 13px; font-weight: 700; cursor: pointer; }
        .lg-send:hover { background: rgba(255, 255, 255, .1); }
        .lg-go { display: inline-flex; align-items: center; gap: 8px; padding: 10px 18px; border-radius: 999px; border: 0; background: #fff; color: var(--sh-hero-a, #2e1065);
            font: inherit; font-size: 13px; font-weight: 700; cursor: pointer; }
        .lg-go:hover { background: #f3e8ff; }
        .lg-go i, .lg-go svg { width: 15px; height: 15px; }
        .lg-go:disabled { opacity: .55; cursor: not-allowed; }   /* portal.css styles no :disabled */
        .lg-sendform { display: flex; flex-direction: column; gap: 10px; }
        .lg-sendform textarea { min-height: 90px; padding: 10px 12px; border-radius: 14px; border: 1px solid var(--line); background: var(--surface);
            color: var(--ink); font: inherit; font-size: 13px; resize: vertical; }
        .lg-sendform .lg-go { align-self: flex-end; background: var(--accent); color: var(--accent-ink); }
        .lg-calnote { display: flex; align-items: center; gap: 8px; margin-top: 10px; padding: 8px 12px; border-radius: 12px;
            background: color-mix(in srgb, var(--accent) 9%, var(--surface)); font-size: 11.5px; color: var(--ink); }
        .lg-calnote i { width: 8px; height: 8px; border-radius: 50%; background: var(--accent); }
        .sc-cal .ds-day.is-target { background: var(--accent); color: var(--accent-ink); box-shadow: 0 6px 14px -6px color-mix(in srgb, var(--accent) 80%, transparent); }
        .sc-cal .ds-day.is-target .ds-load i { background: currentColor; }

        /* ---- every window on this screen: a big rounded sheet over a blurred page ---- */
        .ds-modal { background: rgba(46, 16, 101, .32); backdrop-filter: blur(6px); -webkit-backdrop-filter: blur(6px); }
        .ds-modal-box { max-height: 90vh; overflow-y: auto; border-radius: 28px; padding: 24px 24px 22px; border: 0; box-shadow: 0 30px 80px -20px rgba(46, 16, 101, .5); }
        .ds-modal-head { align-items: center; }
        .ds-modal-head h3 { font-family: var(--sc-display); font-size: 21px; font-weight: 700; letter-spacing: -.01em; color: var(--ink); }
        .ds-x { width: 38px; height: 38px; border-radius: 50%; border: 0; background: var(--surface-3); color: var(--ink); display: grid; place-items: center; cursor: pointer; }

        /* a day's detail */
        .dd-tile { width: 50px; padding: 6px 0; border-radius: 14px; flex-shrink: 0; display: flex; flex-direction: column; align-items: center; line-height: 1.1;
            background: linear-gradient(135deg, var(--sh-hero-a, #2e1065), var(--sh-hero-b, #4c1d95)); color: #fff; margin-right: 4px; }
        .dd-tile b { font-family: var(--sc-display); font-size: 22px; }
        .dd-tile small { font-size: 10px; font-weight: 700; opacity: .85; }
        .dd-headtext { flex: 1; min-width: 0; }
        .dd-when { display: inline-block; margin-top: 4px; padding: 3px 10px; border-radius: 999px; font-size: 11px; font-weight: 800;
            background: color-mix(in srgb, var(--accent) 12%, var(--surface)); color: var(--accent); }
        .dd-when.today { background: color-mix(in srgb, var(--danger) 13%, var(--surface)); color: color-mix(in srgb, var(--danger) 72%, var(--ink)); }
        .dd-when.soon { background: color-mix(in srgb, var(--warning) 22%, var(--surface)); color: color-mix(in srgb, var(--warning) 62%, var(--ink)); }
        .dd-cap { display: flex; align-items: center; gap: 18px; padding: 16px 18px; border-radius: 20px; background: var(--surface-3); margin-bottom: 14px; }
        .dd-bars { flex: 1; display: flex; flex-direction: column; gap: 9px; padding-right: 18px; border-right: 1px solid var(--line); }
        .dd-bar { display: flex; align-items: center; gap: 10px; }
        .dd-bar b { width: 42px; font-size: 12px; font-weight: 800; color: var(--ink); }
        .dd-bar span { flex: 1; display: flex; gap: 3px; }
        .dd-bar span i { flex: 1; height: 7px; border-radius: 3px; background: var(--surface); }
        .dd-bar span i.on.s0 { background: var(--sh-hero-b, #4c1d95); }
        .dd-bar span i.on.s1 { background: color-mix(in srgb, var(--accent) 70%, #fff); }
        .dd-bar small { width: 34px; text-align: right; font-size: 11.5px; color: var(--muted); }
        .dd-left { text-align: right; display: flex; flex-direction: column; }
        .dd-left b { font-family: var(--sc-display); font-size: 34px; font-weight: 700; color: var(--ink); line-height: 1; }
        .dd-left small { font-size: 11.5px; color: var(--muted); margin-top: 4px; }
        .dd-list { display: flex; flex-direction: column; gap: 6px; max-height: 44vh; overflow-y: auto; }
        .dd-row { display: flex; align-items: center; gap: 12px; padding: 4px 2px; }
        .dd-slot { width: 38px; padding: 4px 0; border-radius: 10px; flex-shrink: 0; display: flex; flex-direction: column; align-items: center; line-height: 1.1;
            background: var(--surface-3); color: var(--ink); }
        .dd-slot small { font-size: 9px; color: var(--muted); font-weight: 700; }
        .dd-slot b { font-family: var(--sc-display); font-size: 16px; }
        .dd-name { flex: 1; min-width: 0; font-size: 13px; color: var(--ink); line-height: 1.3; }
        .dd-st { padding: 4px 10px; border-radius: 999px; font-size: 11px; font-weight: 800; white-space: nowrap; }
        .dd-st.ok { background: color-mix(in srgb, var(--success) 16%, var(--surface)); color: color-mix(in srgb, var(--success) 70%, var(--ink)); }
        .dd-st.held { background: color-mix(in srgb, var(--warning) 22%, var(--surface)); color: color-mix(in srgb, var(--warning) 62%, var(--ink)); }
        .dd-note { display: flex; gap: 9px; align-items: flex-start; margin: 14px 0 12px; padding: 11px 14px; border-radius: 14px;
            background: var(--surface-2); font-size: 12px; color: var(--muted); line-height: 1.5; }
        .dd-note i, .dd-note svg { width: 15px; height: 15px; flex-shrink: 0; margin-top: 1px; color: var(--accent); }

        /* Upcoming, by day */
        .up-day { display: flex; align-items: center; gap: 10px; margin: 16px 0 8px; font-size: 12.5px; color: var(--muted); }
        .up-day:first-child { margin-top: 0; }
        .up-day .dd-when { margin: 0; }
        .up-day > i { flex: 1; height: 1px; background: var(--line); }
        .up-day small { font-size: 11.5px; }
        .ds-uprow.up-row { all: unset; box-sizing: border-box; cursor: pointer; width: 100%; display: flex; align-items: center; gap: 12px;
            padding: 9px 12px; border-radius: 16px; background: var(--surface-3); margin-bottom: 6px; }
        .ds-uprow.up-row:hover { background: color-mix(in srgb, var(--accent) 10%, var(--surface-3)); }
        .up-date { width: 42px; padding: 5px 0; border-radius: 11px; flex-shrink: 0; display: flex; flex-direction: column; align-items: center; line-height: 1.1;
            background: var(--surface); color: var(--ink); }
        .up-date b { font-family: var(--sc-display); font-size: 17px; }
        .up-date small { font-size: 9.5px; color: var(--muted); font-weight: 700; }
        .up-id { flex: 1; min-width: 0; display: flex; flex-direction: column; }
        .up-id b { font-size: 13.5px; color: var(--ink); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .up-id small { font-size: 11.5px; color: var(--muted); }
        .up-row > i, .up-row > svg { width: 16px; height: 16px; color: var(--muted); }

        /* the planner */
        .pl-tabs { display: inline-flex; gap: 4px; padding: 4px; border-radius: 999px; background: var(--surface-3); margin-bottom: 12px; flex-wrap: wrap; }
        .pl-tabs button { padding: 7px 14px; border-radius: 999px; border: 0; background: transparent; font: inherit; font-size: 12.5px; font-weight: 600; color: var(--ink); cursor: pointer; }
        .pl-tabs button.on { background: var(--surface); box-shadow: var(--shadow-1); font-weight: 700; }
        .pl-month { font-family: var(--sc-display); font-size: 17px; font-weight: 600; color: var(--ink); }
        .pl-key b i.k-ok { background: color-mix(in srgb, var(--success) 45%, var(--surface)); }
        .pl-key b i.k-held { background: color-mix(in srgb, var(--warning) 55%, var(--surface)); }
        .pl-key b i.k-shut { background: repeating-linear-gradient(135deg, var(--danger) 0 2px, transparent 2px 4px); }
        .ds-modal-box.planner { max-height: 93vh; }
        .pl-cell { border-radius: 14px; }
        .pl-cell.is-today { border-color: var(--accent); box-shadow: 0 0 0 2px var(--accent); }
        .pl-cell.is-focus { box-shadow: 0 0 0 3px color-mix(in srgb, var(--accent) 55%, transparent); }
        .pl-cell.is-past { opacity: .42; }
        .pl-bars { margin-left: auto; display: flex; flex-direction: column; gap: 2px; width: 34px; }
        .pl-bars i { display: block; height: 4px; border-radius: 2px; background: var(--surface-3); overflow: hidden; }
        .pl-bars i em { display: block; height: 100%; border-radius: inherit; }
        .pl-bars i.s0 em { background: var(--sh-hero-b, #4c1d95); }
        .pl-bars i.s1 em { background: color-mix(in srgb, var(--accent) 70%, #fff); }
        .pl-chip { border-radius: 999px; }
        .pl-chip.c-sched { background: color-mix(in srgb, var(--success) 18%, var(--surface)); color: color-mix(in srgb, var(--success) 68%, var(--ink)); }
        .pl-chip.c-pend { background: color-mix(in srgb, var(--warning) 24%, var(--surface)); color: color-mix(in srgb, var(--warning) 60%, var(--ink)); }
        .pl-chip.c-blocked { color: color-mix(in srgb, var(--danger) 75%, var(--ink));
            background: repeating-linear-gradient(135deg, color-mix(in srgb, var(--danger) 14%, var(--surface)) 0 5px, color-mix(in srgb, var(--danger) 5%, var(--surface)) 5px 10px); }

        @media (max-width: 620px) {
            .lg-hero { padding: 18px; grid-template-columns: 1fr; }
            .lg-hero h1 { font-size: 24px; }
            .bd-datecard { width: 100%; }
            .lg-row { flex-wrap: wrap; }
            .lg-chk { order: 5; }
            .ds-logbar.lg-bar { border-radius: 22px; flex-wrap: wrap; padding: 12px 14px; bottom: 10px; }
            .lg-bar .ds-logbar-text { flex-basis: 100%; }
            .lg-send, .lg-go { flex: 1; justify-content: center; }
            .dd-cap { flex-direction: column; align-items: stretch; }
            .dd-bars { border-right: 0; padding-right: 0; }
            .dd-left { text-align: left; flex-direction: row; align-items: baseline; gap: 8px; }
        }
    </style>
</head>

<body class="admin-module">

    <?php
    /* SCHEDULING (2026-09-29 redesign, from the Claude Design concept). Layout only: every id
       and class the script below reads (the .ds-tab buttons, #dsViewLog / #dsViewRecord,
       #dsUpcomingCard, .ds-uprow[data-bk], the calendar ids, the planner) is kept, so closing a
       day, logging a section, the record and the planner behave exactly as before. */
    $sc_days_word = function (string $date) use ($today): array {
        $d = (int)round((strtotime($date) - strtotime($today)) / 86400);
        if ($d === 0) return ['Today', 'today'];
        if ($d === 1) return ['Tomorrow', 'soon'];
        return [date('D, M j', strtotime($date)), ''];
    };
    // "Wednesday, Oct 7 is partly booked on the office calendar" — the day as the calendar sees it.
    $sc_day_note = function (string $date) use ($cal): string {
        $c = $cal[$date] ?? null;
        $day = date('l, M j', strtotime($date));
        if (!$c) return $day;
        if ($c['state'] === 'blocked') return $day . ' is closed on the office calendar';
        if ($c['state'] === 'taken')   return $day . ' is full on the office calendar';
        if (($c['slots_used'] ?? 0) > 0) return $day . ' is partly booked on the office calendar';
        return $day . ' is open on the office calendar';
    };
    ?>
    <header class="sc-head">
        <span class="sc-eyebrow"><?= ucfirst($df_phase) ?></span>
        <h1>Defense scheduling</h1>
        <p>The office is open by default. Close the exceptions, then log and record each group&rsquo;s defense.</p>
    </header>

    <?php if ($flash): ?>
        <div class="alert font-bold" id="dsAlert">
            <i data-lucide="<?= $flash_type === 'error' ? 'alert-circle' : 'check-circle' ?>" style="vertical-align:middle;margin-right:8px;"></i>
            <?= htmlspecialchars($flash) ?>
        </div>
    <?php endif; ?>

    <div class="ds-wrap sc-wrap">
        <div class="sc-left">
        <?php if (!$ds_focus_batch): ?>
            <div class="ds-tabs sc-tabs">
                <?php /* A Coordinator cannot log — that signature is the Secretary's — so she lands
                         on the record, with no empty tab to wonder about. */ ?>
                <?php if ($ds_can_log): ?>
                    <button type="button" class="ds-tab on" data-tab="log">To log<span><?= count($ds_to_log) ?></span></button>
                <?php endif; ?>
                <button type="button" class="ds-tab <?= $ds_can_log ? '' : 'on' ?>" data-tab="record">Record<span><?= count($booked_all) ?></span></button>
            </div>
        <?php endif; ?>

        <div id="dsViewLog"<?= $ds_can_log ? '' : ' hidden' ?>>
            <?php if ($ds_can_log && !$ds_focus_batch): ?>
                <?php if (empty($ds_to_log)): ?>
                    <div class="nx">
                        <?php include __DIR__ . '/components/quill_empty.php'; ?>
                        <h3>Nothing to log for the <?= $df_phase ?> defense</h3>
                        <p>A section arrives here once its Coordinator has confirmed the date the
                           Focal Person put forward. Until then there is nothing for you to sign.</p>
                        <?php if ($ds_other_phase): ?>
                            <a class="nx-go" href="?phase=<?= $df_phase === 'final' ? 'proposal' : 'final' ?>&view=schedule">
                                <i data-lucide="arrow-right"></i>
                                <?= count($ds_other_phase) ?> section<?= count($ds_other_phase) === 1 ? ' is' : 's are' ?>
                                waiting on the <?= $df_phase === 'final' ? 'proposal' : 'final' ?> defense screen
                            </a>
                        <?php endif; ?>
                    </div>
                <?php else:
                    // The soonest section is the big card; any others sit under it, compact —
                    // several purple cards in a row read as a wall.
                    $bt = $ds_to_log[0];
                    $bt_ts = strtotime($bt['defense_date']);
                    $bt_stale = $bt['defense_date'] < $today;
                    $bt_href = '?phase=' . htmlspecialchars($df_phase) . '&view=schedule&batch=' . (int)$bt['batch_id'];
                ?>
                    <article class="sc-feature">
                        <div class="sc-feature-top">
                            <span class="sc-date light"><small><?= date('D', $bt_ts) ?></small><b><?= date('j', $bt_ts) ?></b><small><?= date('M', $bt_ts) ?></small></span>
                            <div class="sc-feature-id">
                                <h3><?= htmlspecialchars($bt['program']) ?></h3>
                                <p><?= $bt['year_level'] ? htmlspecialchars($bt['year_level']) . ', ' : '' ?>Section <?= htmlspecialchars($bt['label']) ?>,
                                   <?= df_phase_label($bt['phase']) ?>, <?= (int)$bt['group_count'] ?> group<?= (int)$bt['group_count'] === 1 ? '' : 's' ?></p>
                            </div>
                        </div>
                        <div class="sc-sigs">
                            <span><i data-lucide="check"></i><b><?= htmlspecialchars($bt['focal_name'] ?: '—') ?></b>Focal person</span>
                            <span><i data-lucide="check"></i><b><?= htmlspecialchars($bt['coord_name'] ?: '—') ?></b>Coordinator</span>
                        </div>
                        <div class="sc-feature-foot">
                            <a class="sc-btn-light" href="<?= $bt_href ?>">Open the section <i data-lucide="chevron-right"></i></a>
                            <span class="<?= $bt_stale ? 'bad' : '' ?>"><?= $bt_stale ? 'This date has already passed' : htmlspecialchars($sc_day_note($bt['defense_date'])) ?></span>
                        </div>
                    </article>

                    <?php if (count($ds_to_log) > 1): ?>
                        <h3 class="sc-also">Also to log <span><?= count($ds_to_log) - 1 ?></span></h3>
                        <div class="sc-rows">
                            <?php foreach (array_slice($ds_to_log, 1) as $bt): $bt_ts = strtotime($bt['defense_date']); ?>
                                <a class="sc-row" href="?phase=<?= htmlspecialchars($df_phase) ?>&view=schedule&batch=<?= (int)$bt['batch_id'] ?>">
                                    <span class="sc-date"><small><?= date('D', $bt_ts) ?></small><b><?= date('j', $bt_ts) ?></b><small><?= date('M', $bt_ts) ?></small></span>
                                    <span class="sc-row-id"><b><?= htmlspecialchars($bt['program']) ?></b>
                                        <small><?= $bt['year_level'] ? htmlspecialchars($bt['year_level']) . ', ' : '' ?>Section <?= htmlspecialchars($bt['label']) ?> &middot; <?= (int)$bt['group_count'] ?> group<?= (int)$bt['group_count'] === 1 ? '' : 's' ?></small></span>
                                    <span class="sc-row-go">Open <i data-lucide="chevron-right"></i></span>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            <?php endif; ?>

            <?php /* LEVEL 2 — one opened section: the same group card the queue below uses,
                     each carrying its slot and a tick. */ ?>
            <?php if ($ds_can_log && $ds_focus_batch): ?>
                <?php // Came here from Approvals' "Log slots and publish"? Then the way back is to
                      // Approvals — "All sections to log" alone left no road back to On your desk. ?>
                <div class="sc-backs">
                    <?php if (($_GET['from'] ?? '') === 'approvals'): ?>
                        <a class="sc-back" href="admin_batch_review.php?phase=<?= htmlspecialchars($df_phase) ?>"><i data-lucide="arrow-left"></i>Back to Approvals</a>
                        <a class="sc-back ghost" href="?phase=<?= htmlspecialchars($df_phase) ?>&view=schedule">All sections to log</a>
                    <?php else: ?>
                        <a class="sc-back" href="?phase=<?= htmlspecialchars($df_phase) ?>&view=schedule"><i data-lucide="arrow-left"></i>All sections to log</a>
                    <?php endif; ?>
                </div>

                <?php
                /* LOG A SECTION (2026-09-29, from the Claude Design concept). Same form, same
                   fields: finalize_batch + keep_mode + keep[] — dsSyncLog() still drives the count
                   and the button. What changed is how it reads: the section as a hero, the groups
                   as one list in slot order with a tick each, and the action in a floating bar. */
                $lg_b    = $ds_focus_batch;
                $lg_ts   = strtotime($lg_b['defense_date']);
                $lg_uids = array_column($ds_focus_rows, 'user_id');
                $lg_rdy  = fp_readiness($pdo, $lg_uids, $lg_b['phase']);
                // "Requested Sep 29, 2:51 AM" — formatted by MySQL (PHP's clock is Berlin's).
                $lg_req  = $pdo->prepare("SELECT DATE_FORMAT(focal_at, '%b %e, %l:%i %p') FROM defense_batches WHERE batch_id = ?");
                $lg_req->execute([(int)$lg_b['batch_id']]);
                $lg_req  = (string)$lg_req->fetchColumn();
                $lg_tones = ['#fde68a', '#ddd6fe', '#fed7aa', '#bae6fd', '#bbf7d0', '#fbcfe8'];
                ?>
                <section class="lg-hero">
                    <div class="lg-hero-id">
                        <span class="sc-eyebrow">Log a section</span>
                        <h1><?= htmlspecialchars($lg_b['program']) ?></h1>
                        <div class="bd-tags">
                            <span><?= $lg_b['year_level'] ? htmlspecialchars($lg_b['year_level']) . ', ' : '' ?>Section <?= htmlspecialchars($lg_b['label']) ?></span>
                            <span><?= ucfirst(df_phase_label($lg_b['phase'])) ?></span>
                        </div>
                        <div class="lg-chain">
                            <span><i data-lucide="check"></i><b><?= htmlspecialchars($lg_b['focal_name'] ?: '—') ?></b>Focal person</span>
                            <span><i data-lucide="check"></i><b><?= htmlspecialchars($lg_b['coord_name'] ?: '—') ?></b>Coordinator</span>
                            <span class="you"><i class="lg-dot"></i><b>You</b>log and publish</span>
                        </div>
                    </div>
                    <div class="bd-datecard">
                        <span class="bd-tile"><small><?= date('D', $lg_ts) ?></small><b><?= date('j', $lg_ts) ?></b><small><?= date('M', $lg_ts) ?></small></span>
                        <div><b><?= date('l, M j', $lg_ts) ?></b><small><?= $lg_req ? 'Requested ' . htmlspecialchars($lg_req) : '' ?></small></div>
                    </div>
                </section>

                <?php if ($lg_b['defense_date'] < $today): ?>
                    <div class="nx">
                        <?php $quill_pose = 'thinking'; include __DIR__ . '/components/quill_empty.php'; ?>
                        <h3>This date has already passed</h3>
                        <p>It cannot be logged now. Send the section back so the Focal Person can put it on a new one.</p>
                    </div>
                <?php else: ?>
                    <form method="POST" action="focal_handler.php" class="ds-log-form">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                        <input type="hidden" name="action" value="finalize_batch">
                        <input type="hidden" name="batch_id" value="<?= (int)$lg_b['batch_id'] ?>">
                        <?php /* An unchecked box posts nothing, so this marker is what tells the
                                 handler that an empty selection means "she unticked them all". */ ?>
                        <input type="hidden" name="keep_mode" value="1">

                        <section class="sc-card lg-groups">
                            <div class="sc-card-head">
                                <h2>Groups in slot order <span><?= count($ds_focus_rows) ?></span></h2>
                                <small>Uncheck a group to leave it off this date.</small>
                            </div>
                            <?php foreach ($ds_focus_rows as $gi => $b):
                                $roster = $ds_rosters[(int)$b['user_id']] ?? [];
                                $names  = ws_names_markup($roster, 'ds-names-' . (int)$b['booking_id']);
                                $rd     = $lg_rdy[(int)$b['user_id']] ?? ['done' => 0, 'total' => 0, 'ready' => false];
                                $gname  = $b['research_group_name'] ?: 'Unnamed group';
                            ?>
                                <div class="lg-item">
                                    <label class="lg-row">
                                        <input type="checkbox" class="ds-keep" name="keep[]" value="<?= (int)$b['user_id'] ?>" checked onchange="dsSyncLog(this.form)">
                                        <span class="bd-slot"><small>Slot</small><b><?= $b['slot_no'] ? (int)$b['slot_no'] : '–' ?></b></span>
                                        <span class="lg-av" style="background:<?= $lg_tones[$gi % count($lg_tones)] ?>"><?= htmlspecialchars(strtoupper(mb_substr($gname, 0, 1))) ?></span>
                                        <span class="lg-id"><b><?= htmlspecialchars($gname) ?></b>
                                            <small><?= $b['student_number'] ? 'ID ' . htmlspecialchars($b['student_number']) . ', ' : '' ?><?= max(1, count($roster)) ?> member<?= max(1, count($roster)) === 1 ? '' : 's' ?></small></span>
                                        <span class="lg-chk <?= $rd['ready'] ? 'ok' : '' ?>" title="Requirements approved in the Checklist">Checklist <?= (int)$rd['done'] ?>/<?= (int)$rd['total'] ?></span>
                                        <?= $names['button'] ?>
                                    </label>
                                    <?= $names['panel'] ?>
                                </div>
                            <?php endforeach; ?>
                        </section>

                        <?php /* The action belongs to the whole section, so it floats with you. */ ?>
                        <div class="ds-logbar lg-bar">
                            <div class="ds-logbar-text">
                                <span><strong id="dsLogCount"><?= count($ds_focus_rows) ?> of <?= count($ds_focus_rows) ?></strong>
                                    groups will be logged onto <?= date('l, F j', $lg_ts) ?></span>
                                <span class="ds-log-drop" hidden></span>
                            </div>
                            <button type="button" class="lg-send" onclick="dsOpen('dsSendBack')">Send back</button>
                            <button type="submit" class="lg-go ds-log-go"><i data-lucide="calendar-check"></i><span>Log and publish</span></button>
                        </div>
                    </form>
                <?php endif; ?>

                <?php // Send back lives outside the log form — forms do not nest. Same return_batch as Approvals. ?>
                <div class="ds-modal" id="dsSendBack" onclick="if(event.target===this)dsClose('dsSendBack')">
                    <div class="ds-modal-box">
                        <div class="ds-modal-head">
                            <div><h3>Send it back</h3><p>It goes back to <?= htmlspecialchars($lg_b['focal_name'] ?: 'the Focal Person') ?> with your note, for a new date.</p></div>
                            <button type="button" class="ds-x" onclick="dsClose('dsSendBack')"><i data-lucide="x" style="width:18px;height:18px;"></i></button>
                        </div>
                        <form method="POST" action="focal_handler.php" class="lg-sendform">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                            <input type="hidden" name="action" value="return_batch">
                            <input type="hidden" name="batch_id" value="<?= (int)$lg_b['batch_id'] ?>">
                            <textarea name="remarks" required placeholder="What needs changing? The Focal Person sees this."></textarea>
                            <button type="submit" class="lg-go"><i data-lucide="corner-up-left"></i>Send back</button>
                        </form>
                    </div>
                </div>
            <?php endif; ?>
        </div><!-- /#dsViewLog -->

        <?php if (!$ds_focus_batch): ?>
        <?php // COMING UP: the record's forward half, by day. Each row is a real booking and opens
              // the same detail window the record does. "All" opens every one of them. ?>
        <section class="sc-card sc-coming" id="dsUpcomingCard">
            <div class="sc-card-head">
                <h2>Coming up</h2>
                <?php if ($logged_upcoming): ?><button type="button" class="sc-link" onclick="dsOpenUpcoming()">All</button><?php endif; ?>
            </div>
            <?php if (empty($logged_upcoming)): ?>
                <div class="nx nx-sm">
                    <?php $quill_size = 60; include __DIR__ . '/components/quill_empty.php'; ?>
                    <h3>Nothing coming up</h3>
                    <p>No published date sits on or after today.</p>
                </div>
            <?php else:
                // The next three days that hold anything, up to three groups each.
                $sc_by_day = [];
                foreach ($logged_upcoming as $u) $sc_by_day[$u['defense_date']][] = $u;
                $sc_by_day = array_slice($sc_by_day, 0, 3, true);
                foreach ($sc_by_day as $d => $list): [$w, $tone] = $sc_days_word($d); ?>
                    <div class="sc-day">
                        <div class="sc-day-when <?= $tone ?>"><b><?= $w ?></b><small><?= $tone ? date('D, M j', strtotime($d)) : '' ?></small></div>
                        <div class="sc-day-list">
                            <?php foreach (array_slice($list, 0, 3) as $u): ?>
                                <button type="button" class="ds-uprow sc-up" data-bk="<?= (int)$u['booking_id'] ?>">
                                    <b><?= htmlspecialchars($u['research_group_name'] ?: $u['username']) ?></b>
                                    <small><?= htmlspecialchars($u['program'] ?: '') ?></small>
                                </button>
                            <?php endforeach; ?>
                            <?php if (count($list) > 3): ?>
                                <button type="button" class="sc-link sc-more" onclick="dsOpenUpcoming()">+<?= count($list) - 3 ?> more that day</button>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>
        <?php endif; ?>

        <?php /* THE RECORD. It used to be a loose bar of pills with a panel that unfolded under
                 it, wedged between the queue and the bottom of the page — three ways to reach the
                 same rows (pills, "Search all", and the Upcoming card beside it) and no obvious
                 home for any of them. It is now a view of its own behind the tab above, with its
                 filtering where filtering belongs: in its own header, next to the search. */ ?>
        <div id="dsViewRecord" hidden>
            <?php if (empty($booked_all)): ?>
                <div class="nx">
                    <?php $quill_pose = 'thinking'; include __DIR__ . '/components/quill_empty.php'; ?>
                    <h3>The record is empty</h3>
                    <p>A defense lands here the moment you log and publish its date. Nothing has
                       been through the whole chain yet.</p>
                </div>
            <?php else: ?>
                <div class="ds-rec-head">
                    <label class="ds-search">
                        <i data-lucide="search"></i>
                        <input type="text" id="dsRecQ" placeholder="Search a group or a leader&hellip;"
                               autocomplete="off" spellcheck="false">
                    </label>
                    <div class="ds-filter">
                        <button type="button" class="ds-fb on" data-rf="all">All <span><?= count($booked_all) ?></span></button>
                        <button type="button" class="ds-fb" data-rf="upcoming">Upcoming <span><?= count($logged_upcoming) ?></span></button>
                        <button type="button" class="ds-fb" data-rf="past">Past <span><?= count($logged_past) ?></span></button>
                    </div>
                </div>
                <div class="ds-card" id="dsRecList"></div>
            <?php endif; ?>
        </div>
        </div>

        <!-- Office calendar: open by default, close the exceptions -->
        <aside class="sc-card sc-cal">
            <div class="sc-card-head">
                <h2>Office calendar</h2>
                <div class="sc-cal-ctl">
                    <button type="button" class="ds-today-btn" id="dsToday">Today</button>
                    <div class="ds-cal-nav">
                        <button type="button" id="dsPrev" title="Previous month" aria-label="Previous month">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6" /></svg>
                        </button>
                        <button type="button" id="dsNext" title="Next month" aria-label="Next month">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6" /></svg>
                        </button>
                    </div>
                </div>
            </div>
            <div class="ds-cal-month" id="dsCalMonth">&mdash;</div>
            <div class="ds-grid" id="dsGrid"></div>
            <div class="ds-sum" id="dsCalSum"></div>
            <?php if ($ds_focus_batch): ?><div class="lg-calnote"><i></i><?= date('M j', strtotime($ds_focus_batch['defense_date'])) ?> is the date you&rsquo;re logging</div><?php endif; ?>
            <p class="sc-hint">Tap an open day to close it, or a closed day to reopen it. Each institution gets ten slots a day.</p>
            <div class="sc-cal-acts">
                <?php // The planner is the whole month with every group on it — kept, one tap away. ?>
                <button type="button" class="sc-pill" onclick="dsOpenPlanner()"><i data-lucide="calendar-range"></i>Open the planner</button>
                <!-- Bulk-closing the public holidays: without it nothing stops a booking on Christmas Day. -->
                <form method="POST" action="defense_handler.php"
                      onsubmit="return confirm('Close all Philippine public holidays? Days a group already booked are skipped.');">
                    <input type="hidden" name="action" value="import_holidays">
                    <input type="hidden" name="phase" value="<?= htmlspecialchars($df_phase) ?>">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                    <button type="submit" class="sc-pill">Close all PH public holidays</button>
                </form>
            </div>
        </aside>
    </div>

    <?php /* The one Quill the JS clones from. Every server-rendered empty state has its own,
             but on a page where nothing is empty there would be none to copy. */ ?>
    <div id="nxBird" hidden><?php include __DIR__ . '/components/quill_empty.php'; ?></div>

    <!-- close / reopen a date -->
    <div class="ds-modal" id="dsBlockModal" onclick="if(event.target===this)dsClose('dsBlockModal')">
        <div class="ds-modal-box">
            <div class="ds-modal-head">
                <span class="dd-tile" id="dsBlockTile" hidden></span>
                <div class="dd-headtext">
                    <h3 id="dsBlockTitle">Close this date</h3>
                    <p id="dsBlockSub">Students will not be able to book it.</p>
                </div>
                <button type="button" class="ds-x" onclick="dsClose('dsBlockModal')"><i data-lucide="x" style="width:18px;height:18px;"></i></button>
            </div>
            <div id="dsBlockBody"></div>
        </div>
    </div>

    <!-- booking detail + cancel -->
    <div class="ds-modal" id="dsBookingModal" onclick="if(event.target===this)dsClose('dsBookingModal')">
        <div class="ds-modal-box">
            <div class="ds-modal-head">
                <div>
                    <h3 id="dsBkGroup">Group</h3>
                    <p id="dsBkDate">—</p>
                </div>
                <button type="button" class="ds-x" onclick="dsClose('dsBookingModal')"><i data-lucide="x" style="width:18px;height:18px;"></i></button>
            </div>
            <div id="dsBkBody"></div>
        </div>
    </div>

    <!-- document preview: stays in the app rather than throwing the user at a new tab -->
    <div class="ds-modal" id="dsPreviewModal" onclick="if(event.target===this)dsClose('dsPreviewModal')">
        <div class="ds-modal-box wide">
            <div class="ds-modal-head">
                <div style="min-width:0;">
                    <h3 id="dsPrevTitle" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">Document</h3>
                    <p>Application for Research Defense (Form 002)</p>
                </div>
                <button type="button" class="ds-x" onclick="dsClose('dsPreviewModal')"><i data-lucide="x" style="width:18px;height:18px;"></i></button>
            </div>
            <div class="ds-prev-body" id="dsPrevBody"></div>
        </div>
    </div>

    <!-- every upcoming defense -->
    <div class="ds-modal" id="dsUpcomingModal" onclick="if(event.target===this)dsClose('dsUpcomingModal')">
        <div class="ds-modal-box wide">
            <div class="ds-modal-head">
                <div>
                    <h3>Upcoming defenses</h3>
                    <p><?= count($logged_upcoming) ?> confirmed from today onward, soonest first. Click one to open it.</p>
                </div>
                <button type="button" class="ds-x" onclick="dsClose('dsUpcomingModal')"><i data-lucide="x" style="width:18px;height:18px;"></i></button>
            </div>
            <div id="dsUpcomingBody"></div>
        </div>
    </div>

    <!-- full planner -->
    <div class="ds-modal" id="dsPlannerModal" onclick="if(event.target===this)dsClose('dsPlannerModal')">
        <div class="ds-modal-box planner">
            <div class="ds-modal-head">
                <div>
                    <h3>Defense planner</h3>
                    <p>Every defense and closure through <?= date('F Y', strtotime(df_window_end())) ?>. Click a group to open it.</p>
                </div>
                <button type="button" class="ds-x" onclick="dsClose('dsPlannerModal')"><i data-lucide="x" style="width:18px;height:18px;"></i></button>
            </div>
            <div class="pl-tabs" id="dsPlTabs"></div>
            <div class="pl-top">
                <span class="pl-month" id="dsPlannerMonth">-</span>
                <div class="pl-key">
                    <b><i class="k-ok"></i>Logged</b>
                    <b><i class="k-held"></i>Held</b>
                    <b><i class="k-shut"></i>Closed</b>
                </div>
                <div class="ds-cal-nav">
                    <button type="button" id="dsPlPrev" title="Previous month">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6" /></svg>
                    </button>
                    <button type="button" id="dsPlNext" title="Next month">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6" /></svg>
                    </button>
                </div>
            </div>
            <div class="pl-grid" id="dsPlannerGrid"></div>

        </div>
    </div>

    <script>
        var DS_CAL = <?= json_encode($cal, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        // ONE dataset behind three surfaces: the record pills, the Upcoming card and every detail
        // window. Each row carries its roster too, so "View names" works inside the detail window
        // without a second round trip.
        var DS_LOGGED = <?= json_encode(array_map(function ($r) use ($today, $ds_rosters) {
            $roster = $ds_rosters[(int)$r['user_id']] ?? [];
            return [
                'booking_id' => (int)$r['booking_id'],
                'group'      => $r['research_group_name'] ?: 'Unnamed group',
                'leader'     => $r['username'],
                'student_no' => $r['student_number'],
                'program'    => $r['program'],
                'department' => $r['department'],
                'date'       => $r['defense_date'] ? date('l, F j, Y', strtotime($r['defense_date'])) : '-',
                'short'      => $r['defense_date'] ? date('M j, Y', strtotime($r['defense_date'])) : '-',
                'day'        => $r['defense_date'] ? date('j', strtotime($r['defense_date'])) : '-',
                'mon'        => $r['defense_date'] ? date('M', strtotime($r['defense_date'])) : '',
                'weekday'    => $r['defense_date'] ? date('l', strtotime($r['defense_date'])) : '',
                'days'       => $r['defense_date'] ? (int)floor((strtotime($r['defense_date']) - strtotime($today)) / 86400) : 0,
                'decided_at' => $r['decided_at'] ? date('M j, Y g:i A', strtotime($r['decided_at'])) : '-',
                'decided_by' => $r['decided_by_name'] ?: '-',
                'file'       => $r['application_path'],
                'file_name'  => $r['application_filename'],
                'past'       => $r['defense_date'] < $today,
                'names'      => array_map(function ($m, $i) {
                    return [
                        'name'  => $m['username'],
                        'role'  => $i === 0 ? 'Group leader' : 'Member',
                        'sid'   => $m['student_number'],
                        'email' => $m['email'],
                        'pfp'   => $m['profile_pic'] ?: 'https://api.dicebear.com/9.x/bottts/svg?seed=' . urlencode($m['username']),
                    ];
                }, $roster, array_keys($roster)),
            ];
        }, $booked_all), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        var DS_TODAY = <?= json_encode($today) ?>;
        // The date being logged, when a section is open: the calendar opens on its month and
        // paints it violet, so she reads the day she is committing to.
        var DS_TARGET = <?= json_encode($ds_focus_batch['defense_date'] ?? null) ?>;
        var DS_MAX = <?= json_encode(df_window_end()) ?>;
        var DS_CSRF = <?= json_encode($csrf) ?>;
        var DS_PHASE = <?= json_encode($df_phase) ?>;
        var DS_SLOTS = <?= (int)DEFENSE_SLOTS_PER_SCHOOL_PER_DAY ?>;   // per school, not per day
        // "MCNP 3/10 · ISAP 7/10" — the two pools are independent, so one number would lie.
        function dsSplit(info, join) {
            return (info.per_school || []).map(function (p) {
                return p.tag + ' ' + p.used + '/' + DS_SLOTS;
            }).join(join || ' · ');
        }

        var MONTHS = ['January','February','March','April','May','June','July','August','September','October','November','December'];
        var DOW = ['S','M','T','W','T','F','S'];

        function dsClose(id) { document.getElementById(id).classList.remove('open'); }
        function dsOpen(id) {
            document.getElementById(id).classList.add('open');
            if (window.lucide) lucide.createIcons();
        }
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') ['dsBlockModal', 'dsBookingModal', 'dsPlannerModal', 'dsPreviewModal', 'dsUpcomingModal', 'dsSendBack']
                .forEach(function (id) { if (document.getElementById(id)) dsClose(id); });
        });

        // "Sections to log": keep the button honest about how many slots are actually going in.
        // The handler re-derives the same sets from keep[] + keep_mode, so this is only guidance.
        function dsSyncLog(form) {
            var boxes = form.querySelectorAll('.ds-keep');
            var kept = form.querySelectorAll('.ds-keep:checked').length;
            var dropped = boxes.length - kept;
            var go = form.querySelector('.ds-log-go');
            var label = go.querySelector('span');
            var drop = form.querySelector('.ds-log-drop');
            go.disabled = kept === 0;
            label.textContent = kept === 0 ? 'Tick at least one group' : 'Log and publish';
            var count = document.getElementById('dsLogCount');
            if (count) count.textContent = kept + ' of ' + boxes.length;
            drop.hidden = dropped === 0;
            drop.textContent = dropped + ' group' + (dropped === 1 ? '' : 's') + ' will be taken off this date.';
        }
        document.querySelectorAll('.ds-log-form').forEach(function (f) { dsSyncLog(f); });

        // Arriving from Schedule Approvals with ?batch=<id> — put that card in front of her.
        (function () {
            var m = window.location.search.match(/[?&]batch=(\d+)/);
            var el = m && document.getElementById('btch' + m[1]);
            if (el) el.scrollIntoView({ behavior: 'smooth', block: 'center' });
        })();

        function esc(s) {
            return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
                return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
            });
        }
        function hidden(name, val) { return '<input type="hidden" name="' + name + '" value="' + esc(val) + '">'; }

        // ---------------- mini calendar ----------------
        var view = DS_TARGET ? { y: +DS_TARGET.slice(0, 4), m: +DS_TARGET.slice(5, 7) - 1 }
                             : { y: +DS_TODAY.slice(0, 4), m: +DS_TODAY.slice(5, 7) - 1 };

        // Six rows, every month, no exceptions — the neighbouring months fill the corners. A
        // 5-week month next to a 6-week one made the card change height on every page, and the
        // whole column under it moved with it.
        function dsCellInfo(k) { return DS_CAL[k] || { state: 'out' }; }

        function renderCal() {
            var grid = document.getElementById('dsGrid');
            document.getElementById('dsCalMonth').innerHTML =
                '<b>' + MONTHS[view.m] + '</b><span>' + view.y + '</span>';
            grid.innerHTML = '';
            DOW.forEach(function (d) {
                var el = document.createElement('div');
                el.className = 'ds-dow';
                el.textContent = d;
                grid.appendChild(el);
            });

            var first = new Date(view.y, view.m, 1).getDay();
            var start = new Date(view.y, view.m, 1 - first);
            var sum = { open: 0, part: 0, full: 0, shut: 0 };

            for (var i = 0; i < 42; i++) {
                var dt = new Date(start.getFullYear(), start.getMonth(), start.getDate() + i);
                var k = dt.getFullYear() + '-' + String(dt.getMonth() + 1).padStart(2, '0') +
                        '-' + String(dt.getDate()).padStart(2, '0');
                var inMonth = dt.getMonth() === view.m;
                var info = dsCellInfo(k);
                var btn = document.createElement('button');
                btn.type = 'button';
                btn.dataset.date = k;
                var cls = 'ds-day';
                var used = info.slots_used || 0;

                if (!inMonth) {
                    cls += ' is-out';
                } else if (used > 0) {
                    // Full days read as taken; a partly-booked day is still bookable but worth
                    // flagging. FULL means both institutions are full — MCNP using its ten
                    // leaves ISAP's ten untouched, so `slots_left` here is the sum of the two.
                    var full = info.slots_left === 0;
                    cls += full ? ' is-taken' : ' is-pending';
                    if (full) sum.full++; else sum.part++;
                    btn.title = dsSplit(info) + ' — ' +
                        (info.groups || []).map(function (g) { return g.name + ' (' + g.phase + ')'; }).join(', ');
                } else if (info.state === 'blocked') {
                    cls += ' is-blocked';
                    sum.shut++;
                    btn.title = info.reason || 'Closed';
                } else if (info.state === 'past') {
                    cls += ' is-past';
                } else if (info.state === 'closed') {
                    cls += ' is-closed';
                    btn.title = 'Sunday — office closed';
                } else if (info.state === 'open') {
                    sum.open++;
                }
                if (info.is_today) cls += ' is-today';
                if (inMonth && k === DS_TARGET) cls += ' is-target';
                btn.className = cls;

                // The face of the day: the number, and under it one tick per institution showing
                // how much of that institution's ten is gone.
                var load = '';
                if (inMonth && used > 0 && info.per_school && info.per_school.length) {
                    load = '<span class="ds-load">' + info.per_school.map(function (p) {
                        var st = p.left === 0 ? ' f' : (p.used > 0 ? ' p' : '');
                        return '<i class="' + st.trim() + '" title="' + p.tag + ' ' + p.used + '/' +
                               (p.used + p.left) + '"></i>';
                    }).join('') + '</span>';
                }
                btn.innerHTML = dt.getDate() + load;

                if (inMonth) btn.addEventListener('click', function () { dsDayClick(this.dataset.date); });
                grid.appendChild(btn);
            }

            // Short labels on purpose: this sits in a 330px column and the long ones wrapped.
            document.getElementById('dsCalSum').innerHTML =
                [['var(--sh-hero-b, #4c1d95)', sum.full, 'Full',   'Both institutions are full'],
                 ['var(--warning)', sum.part, 'Partly', 'Booked, but still has room'],
                 ['var(--muted)', sum.shut, 'Closed', 'Closed by the office'],
                 ['transparent;box-shadow:inset 0 0 0 1.5px var(--muted)', sum.open, 'Open', 'Nothing booked yet']]
                .map(function (r) {
                    return '<b title="' + r[3] + '"><i style="background:' + r[0] + '"></i>' +
                           '<em>' + r[1] + '</em>' + r[2] + '</b>';
                }).join('');

            var maxD = new Date(DS_MAX + 'T00:00:00');
            var atToday = view.y === +DS_TODAY.slice(0, 4) && view.m === +DS_TODAY.slice(5, 7) - 1;
            document.getElementById('dsToday').disabled = atToday;
            document.getElementById('dsPrev').disabled = atToday ||
                new Date(view.y, view.m, 1) <= new Date(+DS_TODAY.slice(0, 4), +DS_TODAY.slice(5, 7) - 1, 1);
            document.getElementById('dsNext').disabled =
                new Date(view.y, view.m + 1, 0) >= new Date(maxD.getFullYear(), maxD.getMonth() + 1, 0);
        }
        // The date tile in the day window's header — shown for a booked day, hidden otherwise.
        function dsTile(d) {
            var t = document.getElementById('dsBlockTile');
            if (!t) return;
            t.hidden = !d;
            if (d) t.innerHTML = '<small>' + d.toLocaleDateString('en-US', { weekday: 'short' }) + '</small><b>' + d.getDate() + '</b><small>' + d.toLocaleDateString('en-US', { month: 'short' }) + '</small>';
        }
        function dsDayClick(k) {
            var info = DS_CAL[k] || {};
            var body = document.getElementById('dsBlockBody');
            var title = document.getElementById('dsBlockTitle');
            var sub = document.getElementById('dsBlockSub');
            var pretty = new Date(k + 'T00:00:00').toLocaleDateString(undefined, { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' });

            if (info.state === 'past' || info.state === 'closed' || info.state === 'out') {
                return; // nothing to do on a past day, a Sunday, or a day outside the window
            }

            dsTile(null);
            if ((info.slots_used || 0) > 0) {
                // The day as the office reads it: how full each institution's ten is (as ten
                // blocks, not a fraction to parse), how many are left, then who is on it in order.
                var dd = new Date(k + 'T00:00:00'), days = Math.round((dd - new Date(DS_TODAY + 'T00:00:00')) / 86400000);
                dsTile(dd);
                title.textContent = pretty;
                sub.innerHTML = days === 0 ? '<span class="dd-when today">Today</span>'
                              : days === 1 ? '<span class="dd-when soon">Tomorrow</span>'
                              : '<span class="dd-when">In ' + days + ' days</span>';
                var cap = '<div class="dd-cap"><div class="dd-bars">' + (info.per_school || []).map(function (p, i) {
                    var blocks = '';
                    for (var j = 0; j < DS_SLOTS; j++) blocks += '<i class="' + (j < p.used ? 'on s' + (i % 2) : '') + '"></i>';
                    return '<div class="dd-bar"><b>' + esc(p.tag) + '</b><span>' + blocks + '</span><small>' + p.used + '/' + DS_SLOTS + '</small></div>';
                }).join('') + '</div><div class="dd-left"><b>' + (info.slots_left || 0) + '</b><small>slot' + ((info.slots_left || 0) === 1 ? '' : 's') + ' still open</small></div></div>';
                var gs = (info.groups || []).slice().sort(function (a, b) { return (a.slot || 99) - (b.slot || 99); });
                body.innerHTML = cap + '<div class="dd-list">' + gs.map(function (g) {
                    var done = g.status === 'Scheduled';
                    return '<div class="dd-row"><span class="dd-slot"><small>Slot</small><b>' + (g.slot || '–') + '</b></span>' +
                        '<span class="dd-name">' + esc(g.name) + '</span>' +
                        '<span class="dd-st ' + (done ? 'ok' : 'held') + '">' + esc(g.status) + '</span></div>';
                }).join('') + '</div>' +
                    '<p class="dd-note"><i data-lucide="info"></i>Groups are heard in slot order. To free a slot, reject or cancel that booking first so the group is told what happened.</p>' +
                    '<button type="button" class="sc-pill" onclick="dsClose(\'dsBlockModal\'); dsOpenPlanner(\'' + k + '\')">See this week in the planner</button>';
                dsOpen('dsBlockModal');
                return;
            }

            if (info.state === 'blocked') {
                title.textContent = pretty;
                sub.textContent = 'This date is currently closed.';
                body.innerHTML =
                    '<div class="ds-kv"><span class="k">Reason</span><span class="v">' + esc(info.reason || 'Closed') + '</span></div>' +
                    '<div class="ds-kv"><span class="k">Source</span><span class="v">' + (info.blackout_by === 'holiday' ? 'Imported public holiday' : (info.blackout_by === 'event' ? 'From the office calendar' : 'Closed by the office')) + '</span></div>' +
                    '<form method="POST" action="defense_handler.php" style="margin-top:16px;">' +
                    hidden('action', 'unblock_date') + hidden('phase', DS_PHASE) +
                    hidden('blackout_id', info.blackout_id || '') + hidden('block_date', k) + hidden('csrf_token', DS_CSRF) +
                    '<button type="submit" class="btn btn-primary" style="width:100%;justify-content:center;">Reopen this date</button>' +
                    '<p style="font-size:11.5px;opacity:.6;margin:10px 0 0;line-height:1.5;">Reopening only lifts the block on defense bookings. The calendar entry itself stays.</p></form>';
                dsOpen('dsBlockModal');
                return;
            }

            // open -> offer to close it
            title.textContent = pretty;
            sub.textContent = 'Close this date so no group can book it.';
            body.innerHTML =
                '<form method="POST" action="defense_handler.php">' +
                hidden('action', 'block_date') + hidden('phase', DS_PHASE) +
                hidden('block_date', k) + hidden('csrf_token', DS_CSRF) +
                '<label style="display:block;font-size:12px;opacity:.7;margin-bottom:6px;">Reason (shown to students)</label>' +
                '<input type="text" name="reason" maxlength="150" placeholder="e.g. Faculty in-service day" ' +
                'style="width:100%;padding:10px 12px;border-radius:10px;border:1px solid var(--line);background:transparent;color:inherit;font-family:inherit;font-size:13px;">' +
                '<button type="submit" class="btn btn-danger" style="width:100%;justify-content:center;margin-top:14px;">Close this date</button></form>';
            dsOpen('dsBlockModal');
        }

        document.getElementById('dsPrev').addEventListener('click', function () {
            view.m--; if (view.m < 0) { view.m = 11; view.y--; } renderCal();
        });
        document.getElementById('dsToday').addEventListener('click', function () {
            view = { y: +DS_TODAY.slice(0, 4), m: +DS_TODAY.slice(5, 7) - 1 }; renderCal();
        });
        document.getElementById('dsNext').addEventListener('click', function () {
            view.m++; if (view.m > 11) { view.m = 0; view.y++; } renderCal();
        });

        // ---------------- booking detail + cancel ----------------
        // "How far away is this?" — the one thing a date alone does not answer.
        function dsWhen(days) {
            if (days === 0) return { text: 'Today', tone: ' today' };
            if (days === 1) return { text: 'Tomorrow', tone: ' soon' };
            if (days < 0) return { text: Math.abs(days) + 'd ago', tone: '' };
            return { text: 'in ' + days + 'd', tone: days <= 7 ? ' soon' : '' };
        }

        // One shared row, used by the Upcoming card's modal and by the record pills — they list the
        // same object, so they list it the same way, and both open the window below.
        function dsRowHtml(r, i) {
            var w = dsWhen(r.days);
            return '<button type="button" class="ds-uprow' + (r.past ? ' is-past' : '') + '" data-i="' + i + '">' +
                '<span class="ds-uprow-date"><span class="d">' + esc(r.day) + '</span><span class="m">' + esc(r.mon) + '</span></span>' +
                '<span class="ds-uprow-id"><strong>' + esc(r.group) + '</strong>' +
                '<span>' + esc(r.weekday) + (r.program ? ' &middot; ' + esc(r.program) : '') + '</span></span>' +
                '<span class="ds-when-chip' + w.tone + '">' + esc(w.text) + '</span></button>';
        }

        // The record card the office actually reads: the date as a date, the facts as facts, and
        // the proponents on it — a ticket is signed for a group, not for the leader whose name the
        // booking happens to be filed under. It used to be six unlabelled key/value rows.
        function dsOpenBooking(b) {
            var w = dsWhen(b.days);
            document.getElementById('dsBkGroup').textContent = b.group;
            document.getElementById('dsBkDate').textContent = b.past ? 'Defense day has passed' : 'Confirmed schedule';

            var html =
                '<div class="ds-bk-hero' + (b.past ? ' is-past' : '') + '">' +
                '<div class="ds-bk-daybox"><span class="d">' + esc(b.day) + '</span><span class="m">' + esc(b.mon) + '</span></div>' +
                '<div class="ds-bk-herotext"><strong>' + esc(b.date) + '</strong>' +
                '<span>Heard in slot order on the day &mdash; no clock time is set</span></div>' +
                '<span class="ds-when-chip' + w.tone + '">' + esc(w.text) + '</span></div>';

            html += '<div class="ds-bk-facts">' +
                '<div class="ds-bk-fact"><span>Group leader</span><strong>' + esc(b.leader) + '</strong></div>' +
                (b.student_no ? '<div class="ds-bk-fact"><span>School ID</span><strong>' + esc(b.student_no) + '</strong></div>' : '') +
                '<div class="ds-bk-fact"><span>Program</span><strong>' + esc(b.program || '—') + '</strong></div>' +
                '<div class="ds-bk-fact"><span>School</span><strong>' + esc(b.department || '—') + '</strong></div>' +
                '<div class="ds-bk-fact"><span>Logged</span><strong>' + esc(b.decided_at) + '</strong></div>' +
                '<div class="ds-bk-fact"><span>Logged by</span><strong>' + esc(b.decided_by) + '</strong></div>' +
                '</div>';

            // The roster, spelled out rather than hidden behind a toggle: in here there is room for
            // it, and it is the answer to the question the window is usually opened to ask.
            if (b.names && b.names.length) {
                html += '<div class="ds-bk-block"><div class="ds-bk-blocktitle">Members &amp; proponents (' + b.names.length + ')</div>' +
                    '<div class="ds-bk-roster">' +
                    b.names.map(function (m) {
                        var meta = m.role + (m.sid ? ' &middot; ' + esc(m.sid) : '') + (m.email ? ' &middot; ' + esc(m.email) : '');
                        return '<div class="ws-name-row"><img src="' + esc(m.pfp) + '" alt="">' +
                            '<div class="ws-name-text"><strong>' + esc(m.name) + '</strong><span>' + meta + '</span></div></div>';
                    }).join('') +
                    (b.names.length === 1 ? '<div class="ws-names-solo">No other members are registered under this group.</div>' : '') +
                    '</div></div>';
            }

            // A filename can hold a quote, so the preview is wired with a listener below rather
            // than an inline onclick — an escaped quote in an attribute comes back unescaped and
            // breaks out of the handler.
            if (b.file) {
                html += '<div class="ds-bk-block"><div class="ds-bk-blocktitle">Application document</div>' +
                    '<button type="button" class="ds-filebtn" id="dsBkFile" style="margin:0;">' +
                    '<i data-lucide="file-text" style="width:15px;height:15px;flex-shrink:0;"></i>' +
                    '<span>' + esc(b.file_name || 'Open the application') + '</span>' +
                    '<i data-lucide="eye" style="width:14px;height:14px;flex-shrink:0;opacity:.55;"></i></button></div>';
            }

            html +=
                '<button type="button" class="btn btn-secondary" style="width:100%;justify-content:center;margin-top:4px;" onclick="dsToggleReason(\'cancel-modal\')">' +
                '<i data-lucide="calendar-x" style="width:15px;height:15px;"></i> Cancel this schedule</button>' +
                '<div class="ds-reason-box" id="dsReason-cancel-modal">' +
                '<form method="POST" action="defense_handler.php">' +
                hidden('action', 'cancel') + hidden('phase', DS_PHASE) +
                hidden('booking_id', b.booking_id) + hidden('csrf_token', DS_CSRF) +
                '<textarea name="remarks" required placeholder="Why is this being cancelled? The group sees this message."></textarea>' +
                '<button type="submit" class="btn btn-danger">Cancel &amp; notify the group</button>' +
                '</form></div>' +
                '<p style="font-size:11.5px;opacity:.6;margin:12px 0 0;line-height:1.5;">Cancelling frees the date and sends the group back to pick a new one. Files they already uploaded are kept.</p>';
            document.getElementById('dsBkBody').innerHTML = html;
            var fileBtn = document.getElementById('dsBkFile');
            if (fileBtn) {
                fileBtn.addEventListener('click', function () { dsPreview(b.file, b.file_name || 'Document'); });
            }
            dsOpen('dsBookingModal');
        }

        // ---------------- upcoming ----------------
        // The preview rows on the card are real bookings, so they open the same window.
        document.querySelectorAll('.ds-uprow[data-bk]').forEach(function (el) {
            el.addEventListener('click', function () {
                var id = +this.dataset.bk;
                var row = DS_LOGGED.filter(function (r) { return r.booking_id === id; })[0];
                if (row) dsOpenBooking(row);
            });
        });

        function dsOpenUpcoming() {
            var rows = DS_LOGGED.filter(function (r) { return !r.past; })
                .sort(function (a, b) { return a.days - b.days; });
            var box = document.getElementById('dsUpcomingBody');
            // Under one header per day (Today / Tomorrow / In N days) instead of the same chip
            // repeated on every row. Rows keep data-i, which is what the click below reads.
            var html = '', lastDay = null;
            rows.forEach(function (r, i) {
                if (r.days !== lastDay) {
                    lastDay = r.days;
                    var n = rows.filter(function (x) { return x.days === r.days; }).length;
                    var chip = r.days === 0 ? ['Today', 'today'] : r.days === 1 ? ['Tomorrow', 'soon'] : ['In ' + r.days + ' days', ''];
                    html += '<div class="up-day"><span class="dd-when ' + chip[1] + '">' + chip[0] + '</span><span>' + esc(r.weekday) + ', ' + esc(r.mon) + ' ' + esc(r.day) +
                            '</span><i></i><small>' + n + ' defense' + (n === 1 ? '' : 's') + '</small></div>';
                }
                html += '<button type="button" class="ds-uprow up-row" data-i="' + i + '">' +
                    '<span class="up-date"><b>' + esc(r.day) + '</b><small>' + esc(r.mon) + '</small></span>' +
                    '<span class="up-id"><b>' + esc(r.group) + '</b><small>' + esc(r.program || '') + '</small></span>' +
                    '<i data-lucide="chevron-right"></i></button>';
            });
            box.innerHTML = rows.length ? html : NX_EMPTY('Nothing coming up', 'No published date sits on or after today.');
            box.querySelectorAll('.ds-uprow').forEach(function (el) {
                el.addEventListener('click', function () {
                    dsClose('dsUpcomingModal');
                    dsOpenBooking(rows[+this.dataset.i]);
                });
            });
            dsOpen('dsUpcomingModal');
        }

        // The same empty state the server renders, for the lists that are built here. The bird is
        // CLONED from the one already in the document rather than written out a second time —
        // two copies of that artwork would drift apart the first time it changes.
        function NX_EMPTY(title, body, pose) {
            var src = document.querySelector('#nxBird .quill-still') ||
                      document.querySelector('.quill-still');
            var bird = '';
            if (src) {
                var c = src.cloneNode(true);
                if (pose && c.tagName === 'IMG') { c.src = c.src.replace(/[a-z-]+\.svg(\?.*)?$/, pose + '.svg'); c.setAttribute('data-pose', pose); }
                bird = c.outerHTML;
            }
            return '<div class="nx">' + bird + '<h3>' + title + '</h3><p>' + body + '</p></div>';
        }

        // ---------------- the tabs ----------------
        // Client-side: every row is already on the page, and this screen is reached through a
        // ?view= of its own, so a second one in the URL would fight it.
        function dsTab(which) {
            document.querySelectorAll('.ds-tab').forEach(function (t) {
                t.classList.toggle('on', t.dataset.tab === which);
            });
            document.getElementById('dsViewLog').hidden    = which !== 'log';
            document.getElementById('dsViewRecord').hidden = which !== 'record';
            // Upcoming is the record's forward half. Beside the record itself it is the same
            // rows twice, so it steps aside there and keeps the calendar company on the queue.
            var up = document.getElementById('dsUpcomingCard');
            if (up) up.hidden = which === 'record';
        }
        document.querySelectorAll('.ds-tab').forEach(function (t) {
            t.addEventListener('click', function () { dsTab(this.dataset.tab); });
        });

        // ---------------- the record ----------------
        // One function, because there is now one list: the pills narrow it and the box searches
        // it, and both run over the same rows rather than each owning a copy of them.
        var dsRecF = 'all';

        function dsRecord() {
            var box = document.getElementById('dsRecList');
            if (!box) return;
            var q = (document.getElementById('dsRecQ').value || '').toLowerCase().trim();
            var rows = DS_LOGGED.filter(function (r) {
                if (dsRecF !== 'all' && (dsRecF === 'past') !== !!r.past) return false;
                return !q || (r.group + ' ' + r.leader).toLowerCase().indexOf(q) !== -1;
            });
            // The record reads backwards (newest first); what is coming reads forwards.
            rows.sort(function (a, b) { return dsRecF === 'upcoming' ? a.days - b.days : b.days - a.days; });

            box.innerHTML = rows.length ? rows.map(dsRowHtml).join('')
                : (q ? NX_EMPTY('No match', 'Nothing in the record is called \u201c' + esc(q) + '\u201d.', 'thinking')
                     : NX_EMPTY('Nothing in this list', 'Try another filter &mdash; All holds every logged defense.'));
            box.querySelectorAll('.ds-uprow').forEach(function (el) {
                el.addEventListener('click', function () { dsOpenBooking(rows[+this.dataset.i]); });
            });
            if (window.lucide) lucide.createIcons();
        }

        if (document.getElementById('dsRecList')) {
            document.querySelectorAll('.ds-fb').forEach(function (b) {
                b.addEventListener('click', function () {
                    document.querySelectorAll('.ds-fb').forEach(function (x) { x.classList.remove('on'); });
                    this.classList.add('on');
                    dsRecF = this.dataset.rf;
                    dsRecord();
                });
            });
            document.getElementById('dsRecQ').addEventListener('input', dsRecord);
            dsRecord();
        }
        // "View names" on a requested card — the roster is already rendered, so this only shows it.
        function wsToggleNames(id, btnEl) {
            var panel = document.getElementById(id);
            if (!panel) return;
            var open = panel.style.display === 'none';
            panel.style.display = open ? 'flex' : 'none';
            btnEl.classList.toggle('is-open', open);
            var n = panel.querySelectorAll('.ws-name-row').length;
            btnEl.innerHTML = '<i data-lucide="users"></i> ' + (open ? 'Hide names' : 'View names') +
                '<span class="ws-names-n">' + n + '</span>';
            if (window.lucide) lucide.createIcons();
        }

        // Collapsed requirements list. Each row inside is a link into the Checklist with that
        // requirement's review window already open, so nothing is decided from this screen.
        function dsToggleReqs(id) {
            var list = document.getElementById('dsReqs-' + id);
            var btn = document.getElementById('dsReqBtn-' + id);
            if (!list) return;
            var open = list.classList.toggle('open');
            if (btn) btn.setAttribute('aria-expanded', open ? 'true' : 'false');
        }

        function dsToggleReason(id) {
            var box = document.getElementById('dsReason-' + id);
            if (!box) return;
            box.style.display = box.style.display === 'block' ? 'none' : 'block';
            if (box.style.display === 'block') {
                var ta = box.querySelector('textarea');
                if (ta) ta.focus();
            }
        }

        // ---------------- document preview ----------------
        // Opening the upload in a new tab dumped staff out of the app; this keeps it in place.
        function dsPreview(path, name) {
            var body = document.getElementById('dsPrevBody');
            document.getElementById('dsPrevTitle').textContent = name;
            var ext = (name.split('.').pop() || '').toLowerCase();
            if (['jpg', 'jpeg', 'png', 'gif', 'webp'].indexOf(ext) !== -1) {
                body.innerHTML = '<img src="' + esc(path) + '" alt="' + esc(name) + '">';
            } else {
                body.innerHTML = '<iframe src="' + esc(path) + '"></iframe>';
            }
            dsOpen('dsPreviewModal');
        }

        // ---------------- planner: a real month grid ----------------
        var pView = { y: +DS_TODAY.slice(0, 4), m: +DS_TODAY.slice(5, 7) - 1 };

        function dsRenderPlanner() {
            document.getElementById('dsPlannerMonth').textContent = MONTHS[pView.m] + ' ' + pView.y;
            var tabs = document.getElementById('dsPlTabs');
            if (tabs) {
                var t0 = new Date(+DS_TODAY.slice(0, 4), +DS_TODAY.slice(5, 7) - 1, 1), tEnd = new Date(DS_MAX + 'T00:00:00'), th = '';
                for (var t = new Date(t0); t <= tEnd; t.setMonth(t.getMonth() + 1)) {
                    var on = t.getFullYear() === pView.y && t.getMonth() === pView.m;
                    th += '<button type="button" class="' + (on ? 'on' : '') + '" data-y="' + t.getFullYear() + '" data-m="' + t.getMonth() + '">' +
                          MONTHS[t.getMonth()].slice(0, 3) + (t.getMonth() === 0 || t.getFullYear() !== t0.getFullYear() && t.getMonth() === 0 ? ' ' + t.getFullYear() : '') + '</button>';
                }
                tabs.innerHTML = th;
                tabs.querySelectorAll('button').forEach(function (b) {
                    b.addEventListener('click', function () { pView = { y: +this.dataset.y, m: +this.dataset.m }; dsRenderPlanner(); });
                });
            }
            var grid = document.getElementById('dsPlannerGrid');
            grid.innerHTML = '';
            ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'].forEach(function (d) {
                var h = document.createElement('div');
                h.className = 'pl-dow';
                h.textContent = d;
                grid.appendChild(h);
            });

            // Six rows here too, for the same reason: paging a month should move the dates, not
            // the furniture.
            var first = new Date(pView.y, pView.m, 1).getDay();
            var start = new Date(pView.y, pView.m, 1 - first);

            for (var i = 0; i < 42; i++) {
                var dt = new Date(start.getFullYear(), start.getMonth(), start.getDate() + i);
                var k = dt.getFullYear() + '-' + String(dt.getMonth() + 1).padStart(2, '0') +
                        '-' + String(dt.getDate()).padStart(2, '0');
                var inMonth = dt.getMonth() === pView.m;
                var info = DS_CAL[k] || { state: 'out' };
                var cell = document.createElement('div');
                cell.className = 'pl-cell' + (inMonth ? '' : ' is-out') + (inMonth && k === pFocus ? ' is-focus' : '') +
                    (inMonth && info.is_today ? ' is-today' : '') +
                    (inMonth && (info.state === 'past' || k < DS_TODAY) ? ' is-past' : '') +
                    (inMonth && info.state === 'closed' ? ' is-sunday' : '');

                var chips = '', cap = '', more = '';
                if (inMonth && info.state === 'blocked') {
                    chips = '<span class="pl-chip c-blocked" title="' + esc(info.reason || 'Closed') + '">' +
                        esc(info.reason || 'Office closed') + '</span>';
                } else if (inMonth && (info.slots_used || 0) > 0) {
                    var gs = info.groups || [];
                    // Three at most. A day can legitimately hold twenty, and a cell that grows to
                    // fit twenty is not a calendar any more.
                    chips = gs.slice(0, 3).map(function (g) {
                        var done = g.status === 'Scheduled';
                        return '<button type="button" class="pl-chip ' + (done ? 'c-sched' : 'c-pend') + '"' +
                            (done && g.id ? ' data-b="' + g.id + '"' : '') +
                            ' title="' + esc(g.name) + ' — ' + esc(g.phase) + ' — ' + esc(g.status) + '">' +
                            (g.slot ? g.slot + '. ' : '') + esc(g.name) + '</button>';
                    }).join('');
                    if (gs.length > 3) more = '<span class="pl-more">+' + (gs.length - 3) + ' more</span>';
                }
                // How full the day is, per institution, as two small bars — read at a glance,
                // no "ISAP 2/10" to parse. On every bookable day, so an empty one reads as room.
                if (inMonth && info.per_school && info.per_school.length && info.state !== 'blocked' && info.state !== 'closed') {
                    cap = '<span class="pl-bars">' + info.per_school.map(function (p, i) {
                        return '<i class="s' + (i % 2) + '" title="' + esc(p.tag) + ' ' + p.used + '/' + DS_SLOTS + '"><em style="width:' +
                               Math.round(p.used / DS_SLOTS * 100) + '%"></em></i>';
                    }).join('') + '</span>';
                }

                cell.innerHTML = '<div class="pl-head"><span class="pl-num">' + dt.getDate() + '</span>' +
                                 cap + '</div><div class="pl-chips">' + chips + more + '</div>';
                grid.appendChild(cell);
            }

            grid.querySelectorAll('.pl-chip[data-b]').forEach(function (chip) {
                chip.addEventListener('click', function () {
                    var id = +this.dataset.b;
                    var row = DS_LOGGED.filter(function (r) { return r.booking_id === id; })[0];
                    if (row) { dsClose('dsPlannerModal'); dsOpenBooking(row); }
                });
            });

            var maxD = new Date(DS_MAX + 'T00:00:00');
            document.getElementById('dsPlPrev').disabled =
                new Date(pView.y, pView.m, 1) <= new Date(+DS_TODAY.slice(0, 4), +DS_TODAY.slice(5, 7) - 1, 1);
            document.getElementById('dsPlNext').disabled =
                new Date(pView.y, pView.m + 1, 0) >= new Date(maxD.getFullYear(), maxD.getMonth() + 1, 0);
        }
        // Opened from a day ("See this week in the planner") it lands on that day's month, ringed.
        var pFocus = null;
        function dsOpenPlanner(day) {
            pFocus = day || null;
            var d = day || DS_TODAY;
            pView = { y: +d.slice(0, 4), m: +d.slice(5, 7) - 1 };
            dsRenderPlanner();
            if (day) setTimeout(function () {
                var c = document.querySelector('#dsPlannerGrid .pl-cell.is-focus');
                if (c) c.scrollIntoView({ block: 'center', behavior: 'smooth' });
            }, 60);
            dsOpen('dsPlannerModal');
        }
        document.getElementById('dsPlPrev').addEventListener('click', function () {
            pView.m--; if (pView.m < 0) { pView.m = 11; pView.y--; } dsRenderPlanner();
        });
        document.getElementById('dsPlNext').addEventListener('click', function () {
            pView.m++; if (pView.m > 11) { pView.m = 0; pView.y++; } dsRenderPlanner();
        });

        renderCal();
        lucide.createIcons();

        var dsAlert = document.getElementById('dsAlert');
        if (dsAlert) setTimeout(function () { dsAlert.style.display = 'none'; }, 4500);
    </script>
</body>

</html>
