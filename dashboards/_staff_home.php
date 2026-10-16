<?php
/**
 * Staff home, 2026-09-29 redesign — the rail + the home + one hub page per destination.
 *
 * Replaces _master_overview.php and the ~22-button side nav for the shells that include it
 * (Director first; Coordinator + Statistician follow once it is approved). The module screens
 * are untouched: a card calls openOverlay() exactly as the old nav button did, and keeps the
 * `nav-item-btn` class + onclick shape that portal.js's refresh-restore looks for.
 *
 * Needs from the host: $pdo, $sh_counts (nav_badge_counts.php keys), $currentUser,
 * $current_pfp, $sh_role_label, and optionally $message. Emits the rail <nav>, then OPENS
 * <main> — the host puts its own views (Settings, Calendar) after the include and closes
 * </main>. Those host views are listed in window.SH_SHELL_VIEWS (set BEFORE the include) so
 * shShow() can hide them.
 */
require_once __DIR__ . '/staff_home_lib.php';
require_once __DIR__ . '/stats_pipeline_lib.php';   // the Statistics board (sp_rows)
require_once __DIR__ . '/plag_pipeline_lib.php';    // the Plagiarism board (pp_rows) — its twin
require_once __DIR__ . '/admin_accounts_lib.php';   // aa_scope_sql — the board is institution-scoped

if (!function_exists('mo_activity_icon')) {
    /** Same rules as _master_overview.php's — the event decides the icon, a verdict wins. */
    function mo_activity_icon(string $cls, string $title): string
    {
        if ($cls === 'success') return 'circle-check';
        if ($cls === 'warning') return 'rotate-ccw';
        $t = strtolower($title);
        foreach ([
            'file-up'       => ['submitted', 'uploaded', 'upload', 're-upload'],
            'calendar-days' => ['defense date', 'schedule', 'scheduled', 'slot', 'endorsed', 'booking'],
            'banknote'      => ['payment', 'paid', 'fee'],
            'shield-check'  => ['plagiar', 'turnitin', 'manuscript'],
            'sigma'         => ['statistic', 'treatment', 'coded data'],
            'user-plus'     => ['registered', 'account', 'member'],
            'message-square'=> ['message', 'replied'],
        ] as $icon => $needles) {
            foreach ($needles as $needle) {
                if (strpos($t, $needle) !== false) return $icon;
            }
        }
        return 'file-text';
    }
}

$sh_role   = $_SESSION['role'] ?? '';
$sh_model  = sh_model($sh_role);
$sh_counts = $sh_counts ?? [];

// ---- scope: everything on this page is institution-scoped (aa_scope_sql on the student `u`).
// Director/Secretary and the admin Statistician get '1'; a Coordinator or a per-institution
// Statistician sees only her own institution's groups, activity and board — and nothing at all
// while her institution is unset (fails closed, like every other list).
[$sh_sc_sql, $sh_sc_params] = aa_scope_sql($pdo, $sh_role, (int)($_SESSION['user_id'] ?? 0), 'u');

// ---- groups: the Groups card + the finder in the top bar ---------------------------------
$sh_groups = $pdo->prepare("
    SELECT u.user_id, u.username, u.student_number, u.research_group_name, u.department, u.program,
           u.year_level, u.profile_pic, u.email, la.last_activity
    FROM users u
    LEFT JOIN (SELECT user_id, MAX(created_at) AS last_activity FROM activity_logs GROUP BY user_id) la
           ON la.user_id = u.user_id
    WHERE u.role = 'Student' AND u.research_group_name IS NOT NULL AND u.research_group_name <> ''
      AND u.leader_id IS NULL AND $sh_sc_sql
    ORDER BY (la.last_activity IS NULL) ASC, la.last_activity DESC, u.research_group_name ASC
");
$sh_groups->execute($sh_sc_params);
$sh_groups = $sh_groups->fetchAll(PDO::FETCH_ASSOC);
$sh_group_count = count($sh_groups);

$sh_approved_docs = $pdo->prepare("
    SELECT COUNT(*) FROM uploads up
    INNER JOIN (SELECT user_id, item_id, MAX(uploaded_at) AS md FROM uploads GROUP BY user_id, item_id) l
      ON up.user_id = l.user_id AND up.item_id = l.item_id AND up.uploaded_at = l.md
    JOIN users u ON u.user_id = up.user_id
    WHERE up.verification_status = 'Approved' AND $sh_sc_sql");
$sh_approved_docs->execute($sh_sc_params);
$sh_approved_docs = (int)$sh_approved_docs->fetchColumn();

// ---- activity: Home shows the latest, each hub shows its own phase -----------------------
$sh_acts = $pdo->prepare("
    SELECT al.user_id, al.title, al.description, al.status_type, al.created_at,
           u.username, u.research_group_name, up.item_id,
           -- MySQL decides 'today': PHP runs Europe/Berlin and would call a 1 AM Manila event yesterday.
           (DATE(al.created_at) = CURDATE())                        AS is_today,
           (DATE(al.created_at) = CURDATE() - INTERVAL 1 DAY)       AS is_yday,
           DATE_FORMAT(al.created_at, '%l:%i %p')                   AS hhmm,
           DATE_FORMAT(al.created_at, '%b %e')                      AS md
    FROM activity_logs al
    JOIN users u ON al.user_id = u.user_id
    LEFT JOIN uploads up ON up.upload_id = al.upload_id
    WHERE $sh_sc_sql
    ORDER BY al.created_at DESC
    LIMIT 250
");
$sh_acts->execute($sh_sc_params);
$sh_acts = $sh_acts->fetchAll(PDO::FETCH_ASSOC);
foreach ($sh_acts as &$a) {
    $st = strtolower(trim($a['status_type'] ?? ''));
    $a['cls'] = in_array($st, ['approved', 'success'], true) ? 'success'
             : (in_array($st, ['revision requested', 'warning'], true) ? 'warning' : 'info');
    $a['phase'] = sh_activity_phase($a['item_id'] !== null ? (int)$a['item_id'] : null, (string)$a['title'], (string)$a['description']);
}
unset($a);
$sh_modal_acts = array_slice($sh_acts, 0, 60);   // the "See all" modal, as before

// ---- totals -----------------------------------------------------------------------------
$sh_phase_n = [];
foreach ($sh_model as $k => $p) $sh_phase_n[$k] = sh_phase_total($p, $sh_counts);
$sh_waiting = 0;
foreach (sh_workflow_keys($sh_role) as $k) $sh_waiting += $sh_phase_n[$k];
// The Stages switcher opens on where the work is; with nothing waiting, on Proposal.
$sh_stage = sh_workflow_keys($sh_role)[0];
foreach (sh_workflow_keys($sh_role) as $k) if ($sh_phase_n[$k] > $sh_phase_n[$sh_stage]) $sh_stage = $k;

// ---- the Statistics board: every request, one pill per group, by stage -------------------------
// Scoped exactly like Progress, from the same rows (sp_rows), so the board and the screen agree.
[$sb_sql, $sb_params] = [$sh_sc_sql, $sh_sc_params];
// Only a rail with a Statistics / Plagiarism hub draws these boards (the Focal Person's and the
// Admin's have neither), so only such a rail pays for their queries.
$sb_rows   = isset($sh_model['stats']) ? sp_rows($pdo, $sb_sql, $sb_params, $sh_role) : [];
$sb_stages = sp_stages();
$sb_cols   = array_fill(0, count($sb_stages), []);
foreach ($sb_rows as $r) $sb_cols[(int)$r['at']][] = $r;
$sb_you    = count(array_filter($sb_rows, fn($r) => $r['owner'] === 'you'));
// Two desks act on this ladder (2026-10-16): the Statistician and the Secretary (a Research
// Director). For either, 'you' is their own desk and 'office' the other one; everyone else reads.
$sb_mine   = sp_actor_of($sh_role) !== '';
$sb_other  = sp_actor_name(sp_actor_of($sh_role) === 'stat' ? 'sec' : 'stat');
// ---- the Plagiarism board: every notified group, one pill each, in the column of its step.
// Same scope as everything else here; pp_step() decides column and tone (plag_pipeline_lib.php).
$pb_rows = isset($sh_model['plag']) ? pp_rows($pdo, $sh_sc_sql, $sh_sc_params) : [];
$pb_cols = array_fill(0, count(pp_board_cols()), []);
foreach ($pb_rows as $r) { $r['pp'] = pp_step((string)$r['status']); $pb_cols[$r['pp']['board']][] = $r; }
// Inside a column the office's move leads, then the group's, then the finished ones.
foreach ($pb_cols as &$col) usort($col, fn($a, $b) => (['you' => 0, 'back' => 1, 'them' => 2, 'done' => 3][$a['pp']['tone']])
                                                  <=> (['you' => 0, 'back' => 1, 'them' => 2, 'done' => 3][$b['pp']['tone']]));
unset($col);
$pb_need = pp_step_counts($pb_rows);
$pb_you  = array_sum($pb_need);
// The History panel on the Statistics page: the payment log's three totals and its latest few
// entries. Scoped like the log itself (aa_scope_sql on the student).
$hp_tot = $pdo->prepare("SELECT SUM(pl.action IN ('accepted','received','released','cleared')) ok, SUM(pl.action IN ('rejected','returned','revision')) no, SUM(pl.action = 'nudged') rem
                         FROM payment_logs pl LEFT JOIN users u ON u.user_id = pl.student_id WHERE $sb_sql");
$hp_tot->execute($sb_params);
$hp_tot = $hp_tot->fetch(PDO::FETCH_ASSOC) ?: ['ok' => 0, 'no' => 0, 'rem' => 0];
$hp_last = $pdo->prepare("SELECT pl.action, pl.control_no, u.research_group_name,
                                 (DATE(pl.created_at) = CURDATE()) is_today, (DATE(pl.created_at) = CURDATE() - INTERVAL 1 DAY) is_yday,
                                 DATE_FORMAT(pl.created_at, '%l:%i %p') hhmm, DATE_FORMAT(pl.created_at, '%b %e') md
                          FROM payment_logs pl LEFT JOIN users u ON u.user_id = pl.student_id
                          WHERE $sb_sql AND pl.action IN ('accepted','received','released','cleared','rejected','returned','revision','nudged')
                          ORDER BY pl.created_at DESC LIMIT 5");
$hp_last->execute($sb_params);
$hp_last = $hp_last->fetchAll(PDO::FETCH_ASSOC);
// The same panel for Plagiarism (2026-09-29): its decision log — moved forward (counted exactly as the
// History screen counts it, cleared included, so the two numbers agree), sent back, and how many of them
// were cleared (Plagiarism has no reminders, so Cleared takes that slot) — and the latest five. Scoped.
$pp_hist_words = ['released' => ['Notified', 'ok'], 'received' => ['Manuscript confirmed', 'ok'], 'accepted' => ['Payment logged', 'ok'],
                  'cleared' => ['Cleared', 'done'], 'returned' => ['Manuscript sent back', 'no'], 'rejected' => ['Payment document sent back', 'no'],
                  'revision' => ['Needs revision', 'no'], 'report' => ['Report replaced', 'ok']];
$pp_tot = $pdo->prepare("SELECT SUM(pl.action IN ('released','received','accepted','cleared')) ok, SUM(pl.action IN ('returned','rejected','revision')) no,
                                SUM(pl.action = 'cleared') done
                         FROM plagiarism_payment_logs pl LEFT JOIN users u ON u.user_id = pl.student_id WHERE $sb_sql");
$pp_tot->execute($sb_params);
$pp_tot = $pp_tot->fetch(PDO::FETCH_ASSOC) ?: ['ok' => 0, 'no' => 0, 'done' => 0];
$pp_last = $pdo->prepare("SELECT pl.action, pl.control_no, u.research_group_name,
                                (DATE(pl.created_at) = CURDATE()) is_today, (DATE(pl.created_at) = CURDATE() - INTERVAL 1 DAY) is_yday,
                                DATE_FORMAT(pl.created_at, '%l:%i %p') hhmm, DATE_FORMAT(pl.created_at, '%b %e') md
                         FROM plagiarism_payment_logs pl LEFT JOIN users u ON u.user_id = pl.student_id
                         WHERE $sb_sql ORDER BY pl.created_at DESC, pl.log_id DESC LIMIT 5");
$pp_last->execute($sb_params);
$pp_last = $pp_last->fetchAll(PDO::FETCH_ASSOC);

// The Admin's Accounts page (2026-09-30): its right-hand panel is the latest accounts made, staff
// and student, with who made each and where it stands — the one "recent" that is hers.
$sh_new_accts = [];
if ($sh_role === 'Admin') {
    $sh_new_accts = $pdo->query("
        SELECT u.username, u.role, u.account_status, c.username AS creator,
               (DATE(u.created_at) = CURDATE()) is_today, (DATE(u.created_at) = CURDATE() - INTERVAL 1 DAY) is_yday,
               DATE_FORMAT(u.created_at, '%l:%i %p') hhmm, DATE_FORMAT(u.created_at, '%b %e') md
        FROM users u LEFT JOIN users c ON c.user_id = u.created_by
        ORDER BY u.created_at DESC, u.user_id DESC LIMIT 6
    ")->fetchAll(PDO::FETCH_ASSOC);
}

// The Focal Person's hubs (2026-10-01): one sections board per phase, built by the host with
// fp_board() (focal_lib.php) — the same rows her badges are counted from — and rolled up here by
// course for the "By course" panel under the step cards.
$fb_boards  = $fb_boards ?? [];
$fb_courses = [];
foreach ($fb_boards as $ph => $rows) {
    foreach ($rows as $r) {
        $c = $fb_courses[$ph][$r['course_key']] ?? ['program' => $r['program'], 'department' => $r['department'],
                                                    'sections' => 0, 'groups' => 0, 'pending' => 0, 'done' => 0, 'total' => 0];
        $c['sections']++;
        foreach (['groups', 'pending', 'done', 'total'] as $f) $c[$f] += $r[$f];
        $fb_courses[$ph][$r['course_key']] = $c;
    }
}

$sh_message = $message ?? '';
$sh_initials = strtoupper(implode('', array_map(fn($w) => mb_substr($w, 0, 1),
                 array_slice(preg_split('/\s+/', trim($currentUser['username'] ?? 'S')), 0, 2))));

/** One step card. Same markup on Home and on the hub; the live refresh finds it by data-key. */
function sh_card(string $phase, int $i, array $step, array $counts, bool $numbered): void
{
    $n   = sh_count($step, $counts);
    $hot = $n > 0;
    $click = isset($step['view'])
        ? "shOpenView('" . $step['view'] . "')"
        : "openOverlay('" . $step['url'] . "', this)";
    ?>
    <button type="button" class="sh-card nav-item-btn <?= $hot ? 'is-hot' : '' ?>" style="--c:<?= $i ?>"
            data-phase="<?= $phase ?>"
            <?php if ($step['key'] !== null): ?>data-key="<?= $step['key'] ?>"
            data-zero="<?= htmlspecialchars($step['zero']) ?>" data-one="<?= htmlspecialchars($step['one']) ?>" data-many="<?= htmlspecialchars($step['many']) ?>"<?php endif; ?>
            <?php if (isset($step['win'])): ?>data-win-title="<?= htmlspecialchars($step['win']) ?>" data-win-icon="<?= $step['icon'] ?>"<?php endif; ?>
            onclick="<?= $click ?>">
        <span class="sh-card-top">
            <span class="sh-card-ico"><i data-lucide="<?= $step['icon'] ?>"></i></span>
            <?php if ($numbered): ?><span class="sh-card-step">Step <?= $i + 1 ?></span><?php endif; ?>
            <span class="sh-card-n" <?= $hot ? '' : 'hidden' ?>><?= $n ?></span>
        </span>
        <span class="sh-card-title"><?= htmlspecialchars($step['title']) ?></span>
        <span class="sh-card-line"><?= htmlspecialchars(sh_line($step, $n)) ?></span>
    </button>
    <?php
}

/** The Focal Person's BY COURSE panel for one phase: how far each course's groups have got, and what
 *  is waiting. A row opens the Requirements desk already inside that course. On her Home (under the
 *  Stages cards, switching with them) and on each hub (under the step cards). */
function fb_courses_panel(string $k, array $courses, string $attr = ''): void
{
    ?>
    <section class="sh-panel fb-courses" <?= $attr ?>>
        <div class="sh-panel-head"><h3>By course</h3></div>
        <div class="sh-acts">
            <?php foreach ($courses as $ck => $c):
                $pct = $c['total'] > 0 ? (int)round($c['done'] / $c['total'] * 100) : 0;
                $url = 'focal_desk.php?' . http_build_query(['view' => 'requirements', 'phase' => $k, 'course' => $ck]); ?>
                <button type="button" class="sh-act fb-course" onclick="shOpenDesk(<?= htmlspecialchars(json_encode($url), ENT_QUOTES) ?>)">
                    <span class="sh-act-ico"><b><?= htmlspecialchars(aa_school_tag($c['department'])) ?></b></span>
                    <span class="sh-act-body">
                        <span class="sh-act-title"><?= htmlspecialchars($c['program']) ?></span>
                        <span class="sh-act-group"><?= $c['sections'] ?> section<?= $c['sections'] === 1 ? '' : 's' ?> &middot; <?= $c['groups'] ?> group<?= $c['groups'] === 1 ? '' : 's' ?> &middot; <?= $pct ?>% of requirements approved</span>
                        <span class="fb-bar"><span style="width:<?= $pct ?>%"></span></span>
                    </span>
                    <?php if ($c['pending']): ?><span class="fb-need"><?= $c['pending'] ?> to review</span>
                    <?php else: ?><i data-lucide="chevron-right" class="fb-go"></i><?php endif; ?>
                </button>
            <?php endforeach; ?>
        </div>
    </section>
    <?php
}

/** One Recent row. Clicking it opens that group's profile — the question a row raises. */
function sh_act_row(array $a): void
{
    $when = $a['is_today'] ? trim($a['hhmm']) : ($a['is_yday'] ? 'Yesterday' : $a['md']);
    ?>
    <button type="button" class="sh-act" onclick="shOpenGroup(<?= (int)$a['user_id'] ?>)">
        <span class="sh-act-ico <?= $a['cls'] ?>"><i data-lucide="<?= mo_activity_icon($a['cls'], (string)$a['title']) ?>"></i></span>
        <span class="sh-act-body">
            <span class="sh-act-title"><?= htmlspecialchars($a['title']) ?></span>
            <span class="sh-act-group"><?= htmlspecialchars($a['research_group_name']) ?></span>
        </span>
        <span class="sh-act-when"><?= $when ?></span>
    </button>
    <?php
}
?>
<?php include __DIR__ . '/components/announcement.php'; /* 2026-10-01: the Admin's banner, on all four staff shells */ ?>
<nav class="sh-rail" aria-label="Primary navigation">
    <?php // No logo here: the mascot + wordmark live in the hero, and two birds read as a stutter. ?>

    <button type="button" class="dock-btn sh-rail-btn active" data-view="home" onclick="shShow('home')">
        <span class="sh-rail-pill"><i data-lucide="house"></i></span><span class="sh-rail-lab">Home</span>
    </button>
    <?php foreach ($sh_model as $k => $p): ?>
        <button type="button" class="dock-btn sh-rail-btn" data-view="hub-<?= $k ?>" onclick="shShow('hub-<?= $k ?>')">
            <span class="sh-rail-pill"><i data-lucide="<?= $p['icon'] ?>"></i>
                <span class="sh-rail-n" data-phase-n="<?= $k ?>" <?= $sh_phase_n[$k] ? '' : 'hidden' ?>><?= $sh_phase_n[$k] ?></span></span>
            <span class="sh-rail-lab"><?= htmlspecialchars($p['label']) ?></span>
        </button>
    <?php endforeach; ?>
</nav>

<?php // Opened here, closed by the HOST after its own views (Settings, Calendar). ?>
<main class="main-workspace-content sh-main">
<div class="sh-top is-home">
    <?php // Who is signed in, the way the student header greets a student: a quiet time-aware
          // line over the role. The wordmark moved into the hero, beside the mascot. ?>
    <div class="sh-brand">
        <span class="sh-greet sh-anim" style="--i:0" id="shGreet"><?php
            $sh_h = (int)(new DateTime('now', new DateTimeZone('Asia/Manila')))->format('G');
            echo $sh_h < 12 ? 'Good morning' : ($sh_h < 18 ? 'Good afternoon' : 'Good evening');
        ?></span>
        <span class="sh-role sh-anim" style="--i:1"><?= htmlspecialchars($sh_role_label ?? $sh_role) ?></span>
    </div>
    <div class="sh-top-right">
        <div class="sh-search" id="shSearch">
            <i data-lucide="search"></i>
            <input type="search" id="shSearchInput" placeholder="Find a group, a student, a course" autocomplete="off"
                   oninput="shFind(this.value)" onfocus="shFind(this.value)" onkeydown="shFindKey(event)">
            <div class="sh-search-menu" id="shSearchMenu" hidden></div>
        </div>
        <?php // A host with its own notification feed (the Focal Person, 2026-10-01) sets $sh_bell and
              // defines shBell() — the drawer and the unread count are the host's. ?>
        <?php if (!empty($sh_bell)): ?>
            <button type="button" class="sh-icon-btn sh-bell" onclick="shBell()" title="Notifications" aria-label="Notifications">
                <i data-lucide="bell"></i><span class="sh-bell-n" id="shBellN" hidden></span>
            </button>
        <?php endif; ?>
        <button type="button" class="sh-icon-btn" onclick="togglePortalTheme()" title="Light / dark">
            <i data-lucide="moon" class="sh-when-light"></i><i data-lucide="sun" class="sh-when-dark"></i>
        </button>
        <div class="sh-me">
            <?php $sh_pic = (string)($currentUser['profile_pic'] ?? ''); // the picture chosen in Settings, else initials ?>
            <button type="button" class="sh-avatar<?= $sh_pic !== '' ? ' has-pic' : '' ?>" onclick="toggleDockMenu('shMeMenu', event)" aria-label="Account menu"><?php if ($sh_pic !== ''): ?><img src="<?= htmlspecialchars($sh_pic) ?>" alt=""><?php else: ?><?= htmlspecialchars($sh_initials) ?><?php endif; ?></button>
            <div class="dock-dropdown sh-me-menu" id="shMeMenu" onclick="event.stopPropagation()">
                <div class="dock-dropdown-head">
                    <div class="dock-dropdown-name"><?= htmlspecialchars($currentUser['username'] ?? '') ?></div>
                    <div class="dock-dropdown-role"><?= htmlspecialchars($sh_role_label ?? $sh_role) ?></div>
                </div>
                <a onclick="shOpenView('settings'); toggleDockMenu('shMeMenu')"><i data-lucide="settings-2"></i> Settings</a>
                <?php if ($sh_has_calendar ?? true): ?><a class="nav-item-btn" data-win-title="Office calendar" data-win-icon="calendar-days" onclick="openOverlay('office_calendar.php', this); toggleDockMenu('shMeMenu')"><i data-lucide="calendar-days"></i> Calendar</a><?php endif; ?>
                <a href="../auth/logout.php" class="danger"><i data-lucide="log-out"></i> Log out</a>
            </div>
        </div>
    </div>
</div>

<?php if ($sh_message !== ''): ?>
    <div class="sh-flash" id="shFlash"><i data-lucide="check-circle"></i> <?= htmlspecialchars($sh_message) ?></div>
<?php endif; ?>

<!-- ============================== HOME ============================== -->
<div class="sh-view" id="masterDashboard">
    <section class="sh-hero sh-anim" style="--i:2">
        <?php // The LIVE mascot — the login screen's eagle (assets/mascot): the main eagle when there is work,
              // winks "you're all caught up" when there is none, wiggles on hover or tap. Never
              // coverEyes(): that is the password reaction, and nothing here is a password. One copy
              // per page — mascot.js binds it by id. The 20s refresh below keeps the pose in step. ?>
        <div class="sh-hero-bird"><?php $mascot_idle = $sh_waiting ? 'plain' : 'wink'; include __DIR__ . '/../assets/mascot/mascot.php'; ?></div>
        <div class="sh-hero-copy">
            <h1 id="shHeroTitle"><?= $sh_waiting ? ($sh_waiting . ($sh_waiting === 1 ? ' thing needs you' : ' things need you')) : 'You&rsquo;re all caught up' ?></h1>
            <?php // Mirrored by heroSub() in the script below — the 20s refresh rewrites this line. ?>
            <p id="shHeroSub"><?php
                $sh_lab = htmlspecialchars($sh_model[$sh_stage]['label']);
                if (!$sh_waiting) echo 'Nothing needs your review. New submissions will show up here.';
                elseif ($sh_waiting === 1) echo 'It&rsquo;s in ' . $sh_lab . '.';
                elseif ($sh_phase_n[$sh_stage] === $sh_waiting) echo 'All of it is in ' . $sh_lab . '.';
                else echo 'Most of it is in ' . $sh_lab . '. Start there.';
            ?></p>
            <div class="sh-hero-pills">
                <span class="sh-pill"><i data-lucide="users"></i><?= $sh_group_count ?> research groups</span>
                <span class="sh-pill"><i data-lucide="check"></i><?= $sh_approved_docs ?> approved documents</span>
                <?php foreach ($sh_pills_extra ?? [] as [$pi, $pt]): // host extras, e.g. the Focal Person's next date ?>
                    <span class="sh-pill"><i data-lucide="<?= htmlspecialchars($pi) ?>"></i><?= htmlspecialchars($pt) ?></span>
                <?php endforeach; ?>
                <button type="button" class="sh-pill sh-pill-go" id="shHeroGo" onclick="shShow('hub-' + shHeroPhase)" <?= $sh_waiting ? '' : 'hidden' ?>>
                    Open <span id="shHeroGoLabel"><?= htmlspecialchars($sh_model[$sh_stage]['label']) ?></span> <i data-lucide="arrow-right"></i>
                </button>
            </div>
        </div>
        <div class="sh-hero-clock">
            <div class="sh-clock-time" id="shClockTime">--:--</div>
            <div class="sh-clock-date" id="shClockDate">&nbsp;</div>
        </div>
    </section>

    <div class="sh-grid">
        <section class="sh-stages sh-anim" style="--i:3">
            <div class="sh-head">
                <h2>Stages</h2>
                <div class="sh-seg" role="tablist">
                    <?php foreach (sh_workflow_keys($sh_role) as $k): ?>
                        <button type="button" role="tab" class="sh-seg-btn <?= $k === $sh_stage ? 'active' : '' ?>" data-stage="<?= $k ?>" onclick="shStage('<?= $k ?>')">
                            <?= htmlspecialchars($sh_model[$k]['label']) ?>
                            <span class="sh-seg-n" data-phase-n="<?= $k ?>" <?= $sh_phase_n[$k] ? '' : 'hidden' ?>><?= $sh_phase_n[$k] ?></span>
                        </button>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php foreach (sh_workflow_keys($sh_role) as $k): ?>
                <div class="sh-cards" data-stage-cards="<?= $k ?>" <?= $k === $sh_stage ? '' : 'hidden' ?>>
                    <?php foreach ($sh_model[$k]['steps'] as $i => $s) { if ($k === 'stats' && $s['title'] === 'History') continue; sh_card($k, $i, $s, $sh_counts, $k !== 'accounts'); } ?>
                </div>
                <?php if (!empty($fb_courses[$k])) fb_courses_panel($k, $fb_courses[$k], 'data-stage-cards="' . $k . '"' . ($k === $sh_stage ? '' : ' hidden')); ?>
            <?php endforeach; ?>
        </section>

        <aside class="sh-side sh-anim" style="--i:4">
            <?php // No Groups card: the top-bar search finds any group, and a Recent row opens
                  // its group too — a third way in was one too many. ?>
            <section class="sh-panel">
                <div class="sh-panel-head">
                    <h3>Recent</h3>
                    <button type="button" class="sh-link" onclick="openActivityLogsModal()">See all</button>
                </div>
                <div class="sh-acts">
                    <?php $sh_i = 0; foreach ($sh_acts as $a): if ($sh_i++ >= 5) break; sh_act_row($a); endforeach; ?>
                    <?php if (!$sh_acts): ?><p class="sh-none">No activity yet.</p><?php endif; ?>
                </div>
            </section>
        </aside>
    </div>
</div>

<!-- ============================== HUBS ============================== -->
<?php foreach ($sh_model as $k => $p):
    $sh_numbered = in_array($k, sh_workflow_keys($sh_role), true);
    $sh_hub_acts = array_values(array_filter($sh_acts, fn($a) => $a['phase'] === $k));
?>
<div class="sh-view" id="hub-<?= $k ?>" hidden>
    <header class="sh-hub-head sh-anim" style="--i:0">
        <h1><?= htmlspecialchars($p['label']) ?></h1>
        <p><?= htmlspecialchars($p['sub']) ?></p>
    </header>
    <div class="sh-grid">
        <section class="sh-anim" style="--i:1">
            <?php if ($k === 'stats'): ?>
                <?php // EVERY REQUEST, AT A GLANCE: one pill per group, in the column of the stage it is
                      // standing on. Solid = the statistician's move, outline = the group's, red = sent
                      // back, green = released. A pill opens Progress already searched to that group. ?>
                <section class="sb-board">
                    <div class="sb-top">
                        <div>
                            <h2>Every request, at a glance</h2>
                            <p>Each circle is one group. Solid ones are waiting on <?= $sb_mine ? 'you' : 'the office' ?>.</p>
                        </div>
                        <?php if ($sb_you): ?>
                            <button type="button" class="sb-need" onclick="shOpenProgress('')"><?= $sb_you ?> <?= $sb_mine ? 'need you' : 'with the office' ?> <i data-lucide="chevron-right"></i></button>
                        <?php endif; ?>
                    </div>
                    <div class="sb-cols sb-cols-<?= count($sb_stages) ?>">
                        <?php foreach ($sb_stages as $si => $st): $list = $sb_cols[$si]; ?>
                            <div class="sb-col">
                                <span class="sb-n"><?= count($list) ?></span>
                                <b><?= $st['label'] ?></b>
                                <div class="sb-pills">
                                    <?php foreach (array_slice($list, 0, 4) as $r):
                                        $nm = preg_replace('/ Group$/', '', (string)$r['research_group_name']);
                                        $cl = $r['owner'] === 'done' ? 'done' : ($r['flag'] ? 'back' : $r['owner']); ?>
                                        <button type="button" class="sb-pill <?= $cl ?>" title="<?= htmlspecialchars($r['research_group_name'] . ' — ' . $r['do']) ?>"
                                                onclick="shOpenProgress(<?= htmlspecialchars(json_encode((string)$r['research_group_name']), ENT_QUOTES) ?>)">
                                            <i><?= htmlspecialchars(strtoupper(mb_substr($nm, 0, 1))) ?></i><span><?= htmlspecialchars($nm) ?></span>
                                        </button>
                                    <?php endforeach; ?>
                                    <?php if (count($list) > 4): ?><button type="button" class="sb-more" onclick="shOpenProgress('')">+<?= count($list) - 4 ?> more</button><?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="sb-legend">
                        <span><i class="you"></i><?= $sb_mine ? 'Your move' : 'With the office' ?></span>
                        <?php if ($sb_mine): ?><span><i class="office"></i>With <?= $sb_other ?></span><?php endif; ?>
                        <span><i class="them"></i>Waiting on the group</span>
                        <span><i class="back"></i>Sent back</span>
                        <span><i class="done"></i>Released</span>
                    </div>
                </section>
            <?php endif; ?>
            <?php if ($k === 'plag'): ?>
                <?php // EVERY SCAN, AT A GLANCE — the twin of the Statistics board: one pill per group
                      // in the column of the step it is on. Solid = the office's move, outline = the
                      // group's, red = sent back / needs revision, green = cleared. A pill opens that
                      // step's screen with the group already selected. ?>
                <section class="sb-board">
                    <div class="sb-top">
                        <div>
                            <h2>Every scan, at a glance</h2>
                            <p>Each chip is one group. Solid ones are waiting on you.</p>
                        </div>
                        <?php if ($pb_you): $pb_first = $pb_need['manuscript'] ? 'manuscript' : ($pb_need['payment'] ? 'payment' : 'result'); ?>
                            <button type="button" class="sb-need" onclick="shOpenPlag('<?= $pb_first ?>', 0)"><?= $pb_you ?> need you <i data-lucide="chevron-right"></i></button>
                        <?php endif; ?>
                    </div>
                    <div class="sb-cols sb-cols-4">
                        <?php foreach (pp_board_cols() as $ci => $clabel): $list = $pb_cols[$ci]; ?>
                            <div class="sb-col">
                                <span class="sb-n"><?= count($list) ?></span>
                                <b><?= $clabel ?></b>
                                <div class="sb-pills">
                                    <?php foreach (array_slice($list, 0, 4) as $r):
                                        $nm = preg_replace('/ Group$/', '', (string)$r['research_group_name']); ?>
                                        <button type="button" class="sb-pill <?= $r['pp']['tone'] ?>" title="<?= htmlspecialchars($r['research_group_name'] . ' — ' . $r['do']) ?>"
                                                onclick="shOpenPlag('<?= $r['pp']['step'] ?>', <?= (int)$r['user_id'] ?>)">
                                            <i><?= htmlspecialchars(strtoupper(mb_substr($nm, 0, 1))) ?></i><span><?= htmlspecialchars($nm) ?></span>
                                        </button>
                                    <?php endforeach; ?>
                                    <?php if (count($list) > 4): ?><button type="button" class="sb-more" onclick="shOpenPlag('<?= ['manuscript', 'payment', 'result', 'result'][$ci] ?>', 0)">+<?= count($list) - 4 ?> more</button><?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="sb-legend">
                        <span><i class="you"></i>Your move</span>
                        <span><i class="them"></i>Waiting on the group</span>
                        <span><i class="back"></i>Sent back</span>
                        <span><i class="done"></i>Cleared</span>
                    </div>
                </section>
            <?php endif; ?>
            <?php if (isset($fb_boards[$k])):
                // EVERY SECTION, AT A GLANCE (Focal Person, 2026-10-01) — the third twin of the
                // Statistics and Plagiarism boards. One pill per room that holds groups, in the column
                // of where its date has got to. Solid = her move (files to review, groups ready for a
                // day), outline = someone else's, red = a date sent back, green = published. A pill
                // opens that room in the desk: its groups while it is collecting, its date otherwise.
                $fb_rows = $fb_boards[$k];
                $fb_cols = array_fill(0, count(fp_board_cols()), []);
                foreach ($fb_rows as $r) $fb_cols[$r['col']][] = $r;
                $fb_you  = count(array_filter($fb_rows, fn($r) => $r['tone'] === 'you' || $r['tone'] === 'back'));
                $fb_first = null;
                foreach ($fb_rows as $r) if ($r['tone'] === 'back' || $r['tone'] === 'you') { $fb_first = $fb_first ?? $r; }
            ?>
                <section class="sb-board fb-board">
                    <div class="sb-top">
                        <div>
                            <h2>Every section, at a glance</h2>
                            <p><?= $fb_rows ? 'Each chip is one room. Solid ones are waiting on you.' : 'Rooms appear here as soon as groups register into them.' ?></p>
                        </div>
                        <?php if ($fb_first): ?>
                            <button type="button" class="sb-need" onclick="shOpenDesk(<?= htmlspecialchars(json_encode($fb_first['href']), ENT_QUOTES) ?>)"><?= $fb_you ?> need<?= $fb_you === 1 ? 's' : '' ?> you <i data-lucide="chevron-right"></i></button>
                        <?php endif; ?>
                    </div>
                    <div class="sb-cols sb-cols-5">
                        <?php foreach (fp_board_cols() as $ci => $clabel): $list = $fb_cols[$ci]; ?>
                            <div class="sb-col">
                                <span class="sb-n"><?= count($list) ?></span>
                                <b><?= $clabel ?></b>
                                <div class="sb-pills">
                                    <?php foreach (array_slice($list, 0, 4) as $r): ?>
                                        <button type="button" class="sb-pill <?= $r['tone'] ?>"
                                                title="<?= htmlspecialchars($r['program'] . ' · ' . html_entity_decode(fp_section_name($r)) . ' — ' . $r['do']) ?>"
                                                onclick="shOpenDesk(<?= htmlspecialchars(json_encode($r['href']), ENT_QUOTES) ?>)">
                                            <i><?= (int)$r['groups'] ?></i><span><?= htmlspecialchars($r['short']) ?></span>
                                        </button>
                                    <?php endforeach; ?>
                                    <?php if (count($list) > 4): ?><button type="button" class="sb-more" onclick="shOpenDesk('focal_desk.php?view=<?= $ci === 0 ? 'requirements' : 'schedule' ?>&phase=<?= $k ?>')">+<?= count($list) - 4 ?> more</button><?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="sb-legend">
                        <span><i class="you"></i>Your move</span>
                        <span><i class="them"></i>Someone else&rsquo;s</span>
                        <span><i class="back"></i>Sent back</span>
                        <span><i class="done"></i>Published</span>
                        <span class="fb-legend-n"><b>3</b> = groups in the room</span>
                    </div>
                </section>
            <?php endif; ?>
            <div class="sh-cards sh-cards-hub">
                <?php foreach ($p['steps'] as $i => $s) {
                    if ($k === 'stats' && $s['title'] === 'History') continue;   // it is the panel on the right
                    sh_card($k, $i, $s, $sh_counts, $sh_numbered && $k !== 'accounts');
                } ?>
            </div>
            <?php if (!empty($fb_courses[$k])) fb_courses_panel($k, $fb_courses[$k]); ?>
        </section>
        <?php if ($sh_numbered): ?>
        <aside class="sh-side sh-anim" style="--i:2">
            <?php if ($k === 'stats'): ?>
            <?php // HISTORY, as a panel the size Recent was. The whole head opens the History window,
                  // and it is a real nav card (nav-item-btn + openOverlay) so refresh-restore finds it. ?>
            <section class="sh-panel sh-hist">
                <button type="button" class="sh-hist-head nav-item-btn" data-phase="stats" data-win-title="Payment History" data-win-icon="history"
                        onclick="openOverlay('admin_module_dynamic.php?phase=stats&view=history', this)">
                    <span class="sh-hist-ico"><i data-lucide="history"></i></span>
                    <span><b>History</b><small>Every payment decision, by month</small></span>
                    <i data-lucide="chevron-right" class="sh-hist-go"></i>
                </button>
                <div class="sh-hist-nums">
                    <span class="ok"><b><?= (int)$hp_tot['ok'] ?></b>moved forward</span>
                    <span class="no"><b><?= (int)$hp_tot['no'] ?></b>sent back</span>
                    <span class="rem"><b><?= (int)$hp_tot['rem'] ?></b>reminders</span>
                </div>
                <div class="sh-acts">
                    <?php foreach ($hp_last as $h):
                        $hk = in_array($h['action'], ['accepted','received','released','cleared'], true) ? 'ok' : ($h['action'] === 'nudged' ? 'rem' : 'no');
                        $hc = ['ok' => 'success', 'no' => 'warning', 'rem' => 'info'][$hk]; ?>
                        <div class="sh-act sh-act-static">
                            <span class="sh-act-ico <?= $hc ?>"><i data-lucide="<?= ['ok' => 'check', 'no' => 'rotate-ccw', 'rem' => 'bell'][$hk] ?>"></i></span>
                            <span class="sh-act-body">
                                <span class="sh-act-title"><?= htmlspecialchars($h['research_group_name'] ?: 'Unknown group') ?></span>
                                <span class="sh-act-group"><?= ['ok' => 'Moved forward', 'no' => 'Sent back', 'rem' => 'Reminder sent'][$hk] ?><?= $h['control_no'] ? ' &middot; ' . htmlspecialchars($h['control_no']) : '' ?></span>
                            </span>
                            <span class="sh-act-when"><?= $h['is_today'] ? trim($h['hhmm']) : ($h['is_yday'] ? 'Yesterday' : $h['md']) ?></span>
                        </div>
                    <?php endforeach; ?>
                    <?php if (!$hp_last): ?><p class="sh-none">No decisions yet.</p><?php endif; ?>
                </div>
            </section>
            <?php elseif ($k === 'plag'): ?>
            <?php // HISTORY, the same panel Statistics has: the head opens the History window (a real nav
                  // card, so refresh-restore finds it), and Recent lives INSIDE that screen now. ?>
            <section class="sh-panel sh-hist">
                <button type="button" class="sh-hist-head nav-item-btn" data-phase="plag" data-win-title="Plagiarism History" data-win-icon="history"
                        onclick="openOverlay('admin_module_dynamic.php?phase=plag&view=payments&arch=1', this)">
                    <span class="sh-hist-ico"><i data-lucide="history"></i></span>
                    <span><b>History</b><small>Every manuscript decision, by month</small></span>
                    <i data-lucide="chevron-right" class="sh-hist-go"></i>
                </button>
                <div class="sh-hist-nums">
                    <span class="ok"><b><?= (int)$pp_tot['ok'] ?></b>moved forward</span>
                    <span class="no"><b><?= (int)$pp_tot['no'] ?></b>sent back</span>
                    <span class="ok"><b><?= (int)$pp_tot['done'] ?></b>cleared</span>
                </div>
                <div class="sh-acts">
                    <?php foreach ($pp_last as $h):
                        [$hw, $hk] = $pp_hist_words[$h['action']] ?? [ucfirst((string)$h['action']), 'ok'];
                        $hc = $hk === 'no' ? 'warning' : 'success'; ?>
                        <div class="sh-act sh-act-static">
                            <span class="sh-act-ico <?= $hc ?>"><i data-lucide="<?= ['ok' => 'check', 'done' => 'shield-check', 'no' => 'rotate-ccw'][$hk] ?>"></i></span>
                            <span class="sh-act-body">
                                <span class="sh-act-title"><?= htmlspecialchars($h['research_group_name'] ?: 'Unknown group') ?></span>
                                <span class="sh-act-group"><?= $hw ?><?= $h['control_no'] ? ' &middot; ' . htmlspecialchars($h['control_no']) : '' ?></span>
                            </span>
                            <span class="sh-act-when"><?= $h['is_today'] ? trim($h['hhmm']) : ($h['is_yday'] ? 'Yesterday' : $h['md']) ?></span>
                        </div>
                    <?php endforeach; ?>
                    <?php if (!$pp_last): ?><p class="sh-none">No decisions yet.</p><?php endif; ?>
                </div>
            </section>
            <?php elseif ($k === 'accounts'): ?>
            <section class="sh-panel">
                <div class="sh-panel-head"><h3>New accounts</h3></div>
                <div class="sh-acts">
                    <?php foreach ($sh_new_accts as $na):
                        $nc = ['pending' => 'warning', 'rejected' => 'warning', 'disabled' => 'warning'][$na['account_status']] ?? 'success';
                        $nw = ['pending' => 'Waiting for approval', 'rejected' => 'Not approved', 'disabled' => 'Disabled'][$na['account_status']] ?? 'Active'; ?>
                        <div class="sh-act sh-act-static">
                            <span class="sh-act-ico <?= $nc ?>"><i data-lucide="<?= $na['role'] === 'Student' ? 'graduation-cap' : 'user-cog' ?>"></i></span>
                            <span class="sh-act-body">
                                <span class="sh-act-title"><?= htmlspecialchars($na['username']) ?></span>
                                <span class="sh-act-group"><?= htmlspecialchars($na['role']) ?> &middot; <?= $nw ?><?= $na['creator'] ? ' &middot; by ' . htmlspecialchars($na['creator']) : '' ?></span>
                            </span>
                            <span class="sh-act-when"><?= $na['is_today'] ? trim($na['hhmm']) : ($na['is_yday'] ? 'Yesterday' : $na['md']) ?></span>
                        </div>
                    <?php endforeach; ?>
                    <?php if (!$sh_new_accts): ?><p class="sh-none">No accounts yet.</p><?php endif; ?>
                </div>
            </section>
            <?php else: ?>
            <section class="sh-panel">
                <div class="sh-panel-head"><h3>Recent in <?= htmlspecialchars($p['label']) ?></h3></div>
                <div class="sh-acts">
                    <?php foreach (array_slice($sh_hub_acts, 0, 6) as $a) sh_act_row($a); ?>
                    <?php if (!$sh_hub_acts): ?><p class="sh-none">Nothing has happened here yet.</p><?php endif; ?>
                </div>
            </section>
            <?php endif; ?>
        </aside>
        <?php endif; ?>
    </div>
</div>
<?php endforeach; ?>

<!-- GROUP PROFILE MODAL (unchanged behaviour: group_profile_data.php) -->
<div id="groupProfileModal" class="sh-modal" hidden onclick="if (event.target === this) closeGroupProfileModal()">
    <div class="sh-modal-box">
        <div class="sh-modal-head">
            <div>
                <h3 id="gpModalTitle">Group profile</h3>
                <p>Roster, phase progress and every submission</p>
            </div>
            <button type="button" class="sh-icon-btn" onclick="closeGroupProfileModal()" aria-label="Close"><i data-lucide="x"></i></button>
        </div>
        <div id="gpModalBody" class="sh-modal-body"><p class="sh-none">Loading&hellip;</p></div>
    </div>
</div>

<!-- ALL ACTIVITY MODAL -->
<div id="activityLogsModal" class="sh-modal" hidden onclick="if (event.target === this) closeActivityLogsModal()">
    <div class="sh-modal-box sh-modal-wide">
        <div class="sh-modal-head">
            <div>
                <h3>All activity</h3>
                <p>The latest <?= count($sh_modal_acts) ?> events across every phase</p>
            </div>
            <button type="button" class="sh-icon-btn" onclick="closeActivityLogsModal()" aria-label="Close"><i data-lucide="x"></i></button>
        </div>
        <div class="sh-modal-tools">
            <div class="sh-search sh-search-inline"><i data-lucide="search"></i>
                <input type="search" id="modalLogSearch" placeholder="Search groups or titles" oninput="filterModalLogs()"></div>
            <select id="modalLogStatusFilter" onchange="filterModalLogs()">
                <option value="all">Every status</option>
                <option value="Approved">Approved</option>
                <option value="Revision Requested">Sent back</option>
                <option value="other">Updates</option>
            </select>
            <select id="modalLogFormFilter" onchange="filterModalLogs()">
                <option value="all">Every phase</option>
                <?php foreach (sh_workflow_keys($sh_role) as $k): ?><option value="<?= $k ?>"><?= htmlspecialchars($sh_model[$k]['label']) ?></option><?php endforeach; ?>
                <option value="other">Other</option>
            </select>
        </div>
        <div id="modalLogsContainer" class="sh-modal-body sh-acts"></div>
        <div class="sh-modal-foot" id="modalLogCount">&nbsp;</div>
    </div>
</div>

<script src="../assets/mascot/mascot.js"></script>
<script>
(function () {
    var SH_GROUPS = <?= json_encode(array_map(fn($g) => [
        'id' => (int)$g['user_id'], 'name' => $g['research_group_name'], 'leader' => $g['username'],
        'program' => $g['program'], 'sid' => $g['student_number'],
        'pic' => $g['profile_pic'] ?: 'https://api.dicebear.com/9.x/bottts/svg?seed=' . urlencode($g['username']),
    ], $sh_groups)) ?>;
    var SH_LABELS = <?= json_encode(array_map(fn($p) => $p['label'], $sh_model)) ?>;
    var SH_WORKFLOW = <?= json_encode(sh_workflow_keys($sh_role)) ?>;
    var SH_PHASE_KEYS = <?= json_encode(array_map(fn($p) => array_values(array_filter(array_column($p['steps'], 'key'))), $sh_model)) ?>;
    var allRecentActivities = <?= json_encode($sh_modal_acts) ?>;
    window.shHeroPhase = <?= json_encode($sh_stage) ?>;

    // The modals live in <main>, which is its own stacking layer UNDER the rail — a backdrop
    // there left the rail and the avatar sharp. On <body> they cover the whole screen.
    ['groupProfileModal', 'activityLogsModal'].forEach(function (id) {
        var m = document.getElementById(id); if (m) document.body.appendChild(m);
    });

    function esc(t) { return String(t == null ? '' : t).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;'); }

    // ---- views: Home, a hub, or a host view (Settings / Calendar) --------------------------
    // Remembered per tab like the module windows (portal.js), so a refresh lands where you were.
    var VIEW_KEY = 'rd-shell-view';
    function paintRail(view) {
        document.querySelectorAll('.sh-rail-btn').forEach(function (b) {
            b.classList.toggle('active', b.getAttribute('data-view') === view);
        });
    }
    // Apple-style entrance: the view's blocks rise in, staggered by --i. Replayed on every switch
    // so a hub arrives the way Home does, not as an instant swap.
    function enter(el) {
        if (!el) return;
        el.classList.remove('sh-enter'); void el.offsetWidth; el.classList.add('sh-enter');
    }
    function setView(id) {
        var all = ['masterDashboard'].concat(Array.prototype.map.call(document.querySelectorAll('.sh-view[id^="hub-"]'), function (v) { return v.id; }))
                                     .concat(window.SH_SHELL_VIEWS || []);
        all.forEach(function (v) {
            var el = document.getElementById(v);
            if (!el) return;
            var on = (v === id);
            if (el.classList.contains('sh-view')) el.hidden = !on; else el.style.display = on ? 'block' : 'none';
        });
        var main = document.querySelector('.main-workspace-content'); if (main) main.scrollTop = 0;
        enter(document.getElementById(id));
        // The greeting is Home's; every other view has its own title. Search, theme and the
        // avatar stay. Coming back Home, the greeting rises in again with the rest.
        var top = document.querySelector('.sh-top'), home = (id === 'masterDashboard');
        if (top) { top.classList.toggle('is-home', home); if (home) enter(top); }
    }
    enter(document.getElementById('masterDashboard'));
    enter(document.querySelector('.sh-top'));

    // Greeting follows the device clock in Manila, like the student header.
    function greet() {
        var h = parseInt(new Date().toLocaleString('en-US', { timeZone: 'Asia/Manila', hour: 'numeric', hour12: false }), 10) % 24;
        var g = document.getElementById('shGreet');
        if (g) g.textContent = h < 12 ? 'Good morning' : (h < 18 ? 'Good afternoon' : 'Good evening');
    }
    greet(); setInterval(greet, 300000);

    // The mascot waves when you reach for it (hover), as well as on tap (mascot.js). Once per
    // visit of the pointer — a bird waving continuously while you read is noise.
    var bird = document.querySelector('.sh-hero-bird');
    if (bird) bird.addEventListener('mouseenter', function () {
        if (window.Quill && window.Quill.state === 'idle') window.Quill.wave();
    });
    window.shShow = function (view) {
        if (window.PortalWindows) PortalWindows.collapseAll();
        setView(view === 'home' ? 'masterDashboard' : view);
        // A host view belongs to Workspace on the rail.
        paintRail((window.SH_SHELL_VIEWS || []).indexOf(view) !== -1 ? 'hub-workspace' : view);
        try { if (view === 'home') sessionStorage.removeItem(VIEW_KEY); else sessionStorage.setItem(VIEW_KEY, view); } catch (e) {}
    };
    window.shOpenView = function (name) {
        var id = name + 'Dashboard';
        shShow(id);
    };
    // Restore WITHOUT collapseAll(): that would forget the window portal.js is about to reopen.
    (function () {
        var v = null; try { v = sessionStorage.getItem(VIEW_KEY); } catch (e) {}
        if (v && document.getElementById(v)) {
            setView(v);
            paintRail((window.SH_SHELL_VIEWS || []).indexOf(v) !== -1 ? 'hub-workspace' : v);
        }
    })();
    // A module window opening lights its phase on the rail (portal.js dispatches this).
    document.addEventListener('portal:open', function (e) {
        var btn = e.detail && e.detail.btn;
        var phase = btn && btn.getAttribute && btn.getAttribute('data-phase');
        if (phase) paintRail('hub-' + phase);
    });

    window.shOpenProgress = function (q) {
        var btn = document.querySelector('.sh-card[data-win-title="Progress"]');
        if (!btn) return;
        var key = 'admin_module_dynamic.php?phase=stats&view=pipeline';
        openOverlay(key, btn);
        if (q) {
            var f = document.querySelector('.app-window.shown iframe');
            if (f) f.src = key + '&search=' + encodeURIComponent(q);
        }
    };
    // A board pill opens its step's screen with that group selected (?g=). The card is passed as
    // the window's button so the title, icon and refresh-restore are the card's own.
    window.shOpenPlag = function (step, uid) {
        var titles = { manuscript: 'Confirm manuscripts', payment: 'Log payments', result: 'Release results' };
        var btn = document.querySelector('.sh-card[data-win-title="' + titles[step] + '"]');
        if (!btn) return;
        var key = 'admin_module_dynamic.php?phase=plag&view=' + step;
        openOverlay(key, btn);
        if (uid) {
            var f = document.querySelector('.app-window.shown iframe');
            if (f) f.src = key + '&g=' + encodeURIComponent(uid);
        }
    };
    // The Focal Person's desk (2026-10-01): a focal_desk.php URL opens in the window of the step
    // card it belongs to (Requirements / Defense dates / Sections), so the title, icon and
    // refresh-restore are that card's, then walks the frame to the exact place — a course, a room.
    window.shOpenDesk = function (url) {
        var q = {};
        (url.split('?')[1] || '').split('&').forEach(function (kv) {
            var a = kv.split('='); if (a[0]) q[a[0]] = decodeURIComponent((a[1] || '').replace(/\+/g, ' '));
        });
        var key = q.view === 'sections' ? 'focal_desk.php?view=sections'
                : q.view === 'archive' ? 'focal_desk.php?view=archive'
                : 'focal_desk.php?view=' + (q.view === 'schedule' ? 'schedule' : 'requirements') + '&phase=' + (q.phase === 'final' ? 'final' : 'proposal');
        var btn = null;
        document.querySelectorAll('.sh-card.nav-item-btn').forEach(function (b) {
            if (!btn && (b.getAttribute('onclick') || '').indexOf("'" + key + "'") !== -1) btn = b;
        });
        if (!btn) return;
        openOverlay(key, btn);
        if (url !== key) { var f = document.querySelector('.app-window.shown iframe'); if (f) f.src = url; }
    };
    window.shStage = function (k) {
        document.querySelectorAll('.sh-seg-btn').forEach(function (b) { b.classList.toggle('active', b.getAttribute('data-stage') === k); });
        document.querySelectorAll('[data-stage-cards]').forEach(function (c) { c.hidden = c.getAttribute('data-stage-cards') !== k; });
    };

    // ---- clock (Manila) --------------------------------------------------------------------
    function tick() {
        var now = new Date();
        var t = now.toLocaleString('en-US', { timeZone: 'Asia/Manila', hour: 'numeric', minute: '2-digit' }).split(' ');
        var el = document.getElementById('shClockTime'); if (el) el.textContent = t[0];
        var d = document.getElementById('shClockDate');
        if (d) d.textContent = (t[1] || '') + ', ' + now.toLocaleString('en-US', { timeZone: 'Asia/Manila', weekday: 'long', month: 'short', day: 'numeric' });
    }
    tick(); setInterval(tick, 15000);

    var flash = document.getElementById('shFlash');
    if (flash) setTimeout(function () { flash.style.opacity = '0'; setTimeout(function () { flash.remove(); }, 400); }, 3500);

    // ---- live counts: every card, rail badge, stage tab and the hero, from one fetch --------
    function line(card, n) {
        var tpl = n === 0 ? card.getAttribute('data-zero') : (n === 1 ? card.getAttribute('data-one') : card.getAttribute('data-many'));
        return (tpl || '').replace('{n}', n);
    }
    window.shApplyCounts = function (data) {
        document.querySelectorAll('.sh-card[data-key]').forEach(function (card) {
            var n = parseInt(data[card.getAttribute('data-key')] || 0, 10);
            card.classList.toggle('is-hot', n > 0);
            var b = card.querySelector('.sh-card-n'); b.textContent = n; b.hidden = n === 0;
            card.querySelector('.sh-card-line').textContent = line(card, n);
        });
        var totals = {}, waiting = 0, best = SH_WORKFLOW[0];
        Object.keys(SH_PHASE_KEYS).forEach(function (p) {
            totals[p] = SH_PHASE_KEYS[p].reduce(function (s, k) { return s + parseInt(data[k] || 0, 10); }, 0);
        });
        SH_WORKFLOW.forEach(function (p) { waiting += totals[p]; if (totals[p] > totals[best]) best = p; });
        document.querySelectorAll('[data-phase-n]').forEach(function (el) {
            var n = totals[el.getAttribute('data-phase-n')] || 0; el.textContent = n; el.hidden = n === 0;
        });
        window.shHeroPhase = best;
        document.getElementById('shHeroTitle').innerHTML = waiting ? (waiting + (waiting === 1 ? ' thing needs you' : ' things need you')) : 'You&rsquo;re all caught up';
        if (window.Quill && window.Quill.container) {   // the eagle follows: the main eagle with work, a wink without
            window.Quill.home = window.Quill.rest = waiting ? 'plain' : 'wink';
            if (window.Quill.state === 'idle') window.Quill.idle();
        }
        document.getElementById('shHeroSub').textContent =
            !waiting ? 'Nothing needs your review. New submissions will show up here.'
            : waiting === 1 ? 'It’s in ' + SH_LABELS[best] + '.'
            : totals[best] === waiting ? 'All of it is in ' + SH_LABELS[best] + '.'
            : 'Most of it is in ' + SH_LABELS[best] + '. Start there.';
        document.getElementById('shHeroGo').hidden = !waiting;
        document.getElementById('shHeroGoLabel').textContent = SH_LABELS[best];
    };
    function refresh() {
        fetch('nav_badge_counts.php').then(function (r) { return r.ok ? r.json() : null; })
            .then(function (d) { if (d) shApplyCounts(d); }).catch(function () {});
    }
    setInterval(refresh, 20000);
    document.addEventListener('visibilitychange', function () { if (!document.hidden) refresh(); });

    // ---- group finder (top bar) ------------------------------------------------------------
    var menu = document.getElementById('shSearchMenu'), findIdx = -1;
    window.shFind = function (q) {
        q = (q || '').toLowerCase().trim();
        var hits = SH_GROUPS.filter(function (g) {
            return !q || (g.name + ' ' + g.leader + ' ' + g.program + ' ' + (g.sid || '')).toLowerCase().indexOf(q) !== -1;
        }).slice(0, 7);
        findIdx = -1;
        menu.innerHTML = hits.length ? hits.map(function (g) {
            return '<button type="button" class="sh-find-row" onclick="shOpenGroup(' + g.id + ')">' +
                '<img src="' + esc(g.pic) + '" alt=""><span><b>' + esc(g.name) + '</b><small>' + esc(g.leader) + ' &middot; ' + esc(g.program) + '</small></span></button>';
        }).join('') : '<p class="sh-none">No group matches &ldquo;' + esc(q) + '&rdquo;</p>';
        menu.hidden = false;
    };
    window.shFindKey = function (e) {
        var rows = menu.querySelectorAll('.sh-find-row');
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            e.preventDefault(); if (!rows.length) return;
            findIdx = (findIdx + (e.key === 'ArrowDown' ? 1 : -1) + rows.length) % rows.length;
            rows.forEach(function (r, i) { r.classList.toggle('on', i === findIdx); });
        } else if (e.key === 'Enter' && rows.length) { e.preventDefault(); rows[Math.max(findIdx, 0)].click(); }
        else if (e.key === 'Escape') { menu.hidden = true; e.target.blur(); }
    };
    window.shFocusFind = function () { var i = document.getElementById('shSearchInput'); i.focus(); shFind(i.value); };
    document.addEventListener('click', function (e) { if (!document.getElementById('shSearch').contains(e.target)) menu.hidden = true; });

    // ---- group profile modal (same data endpoint as before) --------------------------------
    // moIcon stays at this scope: the "See all" modal and the profile timeline both call it.
    function moIcon(cls, title) {
        if (cls === 'success') return 'circle-check';
        if (cls === 'warning') return 'rotate-ccw';
        var t = (title || '').toLowerCase();
        var map = [
            ['file-up', ['submitted', 'uploaded', 'upload', 're-upload']],
            ['calendar-days', ['defense date', 'schedule', 'scheduled', 'slot', 'endorsed', 'booking']],
            ['banknote', ['payment', 'paid', 'fee']],
            ['shield-check', ['plagiar', 'turnitin', 'manuscript']],
            ['sigma', ['statistic', 'treatment', 'coded data']],
            ['user-plus', ['registered', 'account', 'member']],
            ['message-square', ['message', 'replied']]
        ];
        for (var i = 0; i < map.length; i++) for (var j = 0; j < map[i][1].length; j++) if (t.indexOf(map[i][1][j]) !== -1) return map[i][0];
        return 'file-text';
    }
    function fmt(s) { if (!s) return ''; var d = new Date(s.replace(' ', 'T')); return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) + ' · ' + d.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' }); }
    function clsOf(st) { st = (st || '').toLowerCase(); return (st === 'approved' || st === 'success') ? 'success' : ((st === 'revision requested' || st === 'warning') ? 'warning' : 'info'); }

    window.shOpenGroup = function (uid) {
        menu.hidden = true;
        window.selectedGroupUserId = uid;
        openGroupProfileModal();
    };
    window.openGroupProfileModal = function () {
        var uid = window.selectedGroupUserId, modal = document.getElementById('groupProfileModal'), body = document.getElementById('gpModalBody');
        if (!uid) return;
        modal.hidden = false;
        body.innerHTML = '<p class="sh-none">Loading&hellip;</p>';
        fetch('group_profile_data.php?user_id=' + encodeURIComponent(uid))
            .then(function (r) { return r.json(); })
            .then(function (data) { if (data.error) { body.innerHTML = '<p class="sh-none">' + esc(data.error) + '</p>'; return; } renderGroup(data); })
            .catch(function () { body.innerHTML = '<p class="sh-none">Could not load this profile.</p>'; });
    };
    window.closeGroupProfileModal = function () { document.getElementById('groupProfileModal').hidden = true; };

    function renderGroup(data) {
        var g = data.group;
        document.getElementById('gpModalTitle').textContent = g.research_group_name;
        var roster = data.roster.length ? data.roster.map(function (m) { return '<span class="sh-roster">' + esc(m) + '</span>'; }).join('') : '<span class="sh-none-inline">Only the leader is listed.</span>';
        var phases = ['proposal', 'stats', 'final', 'plag'].map(function (k) {
            var p = data.phases[k]; if (!p) return '';
            return '<div class="sh-prog"><div class="sh-prog-top"><span>' + esc(p.label) + '</span><span>' + p.approved + '/' + p.total + '</span></div>' +
                '<div class="sh-prog-bar"><span style="width:' + p.pct + '%"></span></div></div>';
        }).join('');
        var tl = data.timeline.length ? data.timeline.map(function (a) {
            var c = clsOf(a.status_type);
            return '<div class="sh-act sh-act-static"><span class="sh-act-ico ' + c + '"><i data-lucide="' + moIcon(c, a.title) + '"></i></span>' +
                '<span class="sh-act-body"><span class="sh-act-title">' + esc(a.title) + '</span><span class="sh-act-group">' + esc(a.description) + '</span></span>' +
                '<span class="sh-act-when">' + fmt(a.created_at) + '</span></div>';
        }).join('') : '<p class="sh-none">Nothing recorded yet.</p>';
        document.getElementById('gpModalBody').innerHTML =
            '<div class="sh-gp-id"><img src="' + esc(g.profile_pic || ('https://api.dicebear.com/9.x/bottts/svg?seed=' + encodeURIComponent(g.username))) + '" alt="">' +
            '<div><h4>' + esc(g.research_title || 'Untitled research') + '</h4>' +
            '<p>' + esc(g.username) + ' &middot; ' + esc(g.email) + '</p>' +
            '<p class="sh-muted">' + esc(g.program) + ' &middot; ' + esc(g.department) + (g.year_level ? ' &middot; ' + esc(g.year_level) : '') + (g.student_number ? ' &middot; ID ' + esc(g.student_number) : '') + '</p></div></div>' +
            // No Coordinator / Statistician / Director / Payment chips: those read the retired
            // `approvals` table, so every group said "Pending / Unpaid" wherever it really was.
            // The phase bars below are the live answer.
            '<h5>Team</h5><div class="sh-roster-row">' + roster + '</div>' +
            '<h5>Progress</h5><div class="sh-prog-grid">' + phases + '</div>' +
            '<h5>Timeline</h5><div class="sh-acts">' + tl + '</div>';
        if (window.lucide) lucide.createIcons();
    }

    // ---- all activity modal ----------------------------------------------------------------
    window.openActivityLogsModal = function () {
        document.getElementById('activityLogsModal').hidden = false;
        document.getElementById('modalLogSearch').value = '';
        document.getElementById('modalLogStatusFilter').value = 'all';
        document.getElementById('modalLogFormFilter').value = 'all';
        filterModalLogs();
    };
    window.closeActivityLogsModal = function () { document.getElementById('activityLogsModal').hidden = true; };
    window.filterModalLogs = function () {
        var q = document.getElementById('modalLogSearch').value.toLowerCase().trim();
        var sv = document.getElementById('modalLogStatusFilter').value, fv = document.getElementById('modalLogFormFilter').value;
        var box = document.getElementById('modalLogsContainer'), html = '', n = 0;
        allRecentActivities.forEach(function (a) {
            var c = a.cls;
            var okQ = !q || ((a.title || '') + ' ' + (a.description || '') + ' ' + (a.research_group_name || '') + ' ' + (a.username || '')).toLowerCase().indexOf(q) !== -1;
            var okS = sv === 'all' || (sv === 'Approved' && c === 'success') || (sv === 'Revision Requested' && c === 'warning') || (sv === 'other' && c === 'info');
            var okF = fv === 'all' || a.phase === fv;
            if (!(okQ && okS && okF)) return;
            n++;
            html += '<button type="button" class="sh-act" onclick="shOpenGroup(' + parseInt(a.user_id, 10) + ')">' +
                '<span class="sh-act-ico ' + c + '"><i data-lucide="' + moIcon(c, a.title) + '"></i></span>' +
                '<span class="sh-act-body"><span class="sh-act-title">' + esc(a.title) + '</span>' +
                '<span class="sh-act-group">' + esc(a.research_group_name) + (a.description ? ' &middot; ' + esc(a.description) : '') + '</span></span>' +
                '<span class="sh-act-when">' + fmt(a.created_at) + '</span></button>';
        });
        box.innerHTML = n ? html : '<p class="sh-none">Nothing matches.</p>';
        document.getElementById('modalLogCount').textContent = 'Showing ' + n + ' of ' + allRecentActivities.length;
        if (window.lucide) lucide.createIcons();
    };
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        closeGroupProfileModal(); closeActivityLogsModal();
    });
})();
</script>
