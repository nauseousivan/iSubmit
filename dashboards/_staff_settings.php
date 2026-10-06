<?php
/**
 * Staff Settings (2026-09-29, from the Claude Design concept) — ONE partial for all three
 * shells, rendered as the host view #settingsDashboard (window.SH_SHELL_VIEWS).
 *
 * Host provides: $pdo, $currentUser, $sh_role_label (optional). Saves go to
 * staff_settings_handler.php as fetch() — no reload, no trip back to Home.
 *
 * Profile + password are the account (server). Light/Dark/Match device and the colour are
 * the device (localStorage via portal.js: PortalTheme.set / setAccent), and say so.
 * The concept's Notifications section is left out on purpose: nothing sends staff
 * notifications yet, and a switch that does nothing is worse than no switch.
 */
require_once __DIR__ . '/staff_settings_lib.php';
require_once __DIR__ . '/admin_accounts_lib.php';

$ss_role  = $_SESSION['role'] ?? '';
$ss_base  = ($ss_role === 'Research Director' && ($sh_role_label ?? '') === 'Secretary') ? 'Secretary' : ($ss_role === 'Admin' ? 'Administrator' : $ss_role);
$ss_scope = ss_scope_line($currentUser, $ss_base);
// A Focal Person's scope is a LIST of courses, not an institution (admin_accounts_lib.php): the
// card names how many, and a read-only section lists them — only the office assigns them.
$ss_courses = $ss_role === 'Focal Person' ? aa_focal_scope($pdo, (int)$currentUser['user_id']) : [];
if ($ss_role === 'Focal Person') {
    $ss_scope = 'Focal Person, ' . (count($ss_courses) ? count($ss_courses) . ' course' . (count($ss_courses) === 1 ? '' : 's') : 'no courses yet');
}
$ss_pic   = (string)($currentUser['profile_pic'] ?? '');
$ss_name  = (string)($currentUser['username'] ?? '');
$ss_urls  = ss_avatar_urls();
$ss_colours = [
    'lavender' => ['Lavender', '#6d28d9'], 'blue' => ['Blue', '#2563eb'], 'green' => ['Green', '#15803d'],
    'rose' => ['Rose', '#db2777'], 'amber' => ['Amber', '#c2410c'], 'teal' => ['Teal', '#0f766e'],
];
?>
<div class="container ss" id="settingsDashboard" style="display: none;">
    <style>
        .ss { max-width: 1180px; margin: 0 auto; padding: 8px 4px 40px; }
        .ss-eyebrow { font-size: 12.5px; font-weight: 700; color: var(--muted); }
        .ss-title { font-family: var(--sh-display, var(--font-ui)); font-size: 34px; font-weight: 700; letter-spacing: -.02em; color: var(--ink); margin: 2px 0 18px; }
        .ss-grid { display: grid; grid-template-columns: 280px minmax(0, 1fr); gap: 18px; align-items: start; }
        .ss-side { position: sticky; top: 12px; display: flex; flex-direction: column; gap: 12px; }
        .ss-card-me { position: relative; overflow: hidden; border-radius: 26px; padding: 26px 20px 22px; text-align: center; color: #fff;
                      background: linear-gradient(145deg, var(--sh-hero-a, #2e1065), var(--sh-hero-b, #4c1d95)); }
        .ss-card-me::after { content: ""; position: absolute; right: -60px; top: -70px; width: 200px; height: 200px; border-radius: 50%; background: rgba(255,255,255,.07); }
        .ss-me-av { width: 92px; height: 92px; border-radius: 50%; margin: 0 auto 12px; display: grid; place-items: center; overflow: hidden;
                    background: #fff; color: var(--sh-hero-b, #4c1d95); font-size: 30px; font-weight: 800; border: 4px solid rgba(255,255,255,.35); position: relative; z-index: 1; }
        .ss-me-av img { width: 100%; height: 100%; object-fit: cover; }
        .ss-me-name { font-family: var(--sh-display, var(--font-ui)); font-size: 19px; font-weight: 700; position: relative; z-index: 1; }
        .ss-me-scope, .ss-me-mail { font-size: 12px; opacity: .8; margin-top: 3px; position: relative; z-index: 1; word-break: break-all; }
        .ss-nav { display: flex; flex-direction: column; gap: 2px; }
        .ss-nav button { all: unset; box-sizing: border-box; cursor: pointer; display: flex; align-items: center; gap: 10px; padding: 11px 14px; border-radius: 14px;
                         font-size: 13.5px; font-weight: 600; color: var(--ink); }
        .ss-nav button:hover { background: var(--surface-2); }
        .ss-nav button.on { background: color-mix(in srgb, var(--accent) 16%, var(--surface)); font-weight: 750; }
        .ss-nav i, .ss-nav svg { width: 16px; height: 16px; color: var(--muted); }
        .ss-main { display: flex; flex-direction: column; gap: 14px; min-width: 0; }
        .ss-sec { background: var(--surface); border: 1px solid var(--line); border-radius: 24px; padding: 22px 24px; scroll-margin-top: 12px; }
        .ss-sec h2 { font-family: var(--sh-display, var(--font-ui)); font-size: 19px; font-weight: 700; color: var(--ink); margin: 0 0 4px; }
        .ss-sub { font-size: 12.5px; color: var(--muted); margin: 0 0 16px; }
        .ss-lab { display: block; font-size: 12.5px; font-weight: 700; color: var(--ink); margin: 0 0 7px; }
        .ss-in { width: 100%; max-width: 420px; box-sizing: border-box; padding: 11px 14px; border-radius: 14px; border: 1.5px solid transparent;
                 background: var(--surface-3); color: var(--ink); font: inherit; font-size: 13.5px; outline: none; }
        .ss-in:focus { border-color: var(--accent); background: var(--surface); }
        .ss-avs { display: flex; flex-wrap: wrap; gap: 10px; margin: 0 0 18px; }
        .ss-av { all: unset; cursor: pointer; width: 54px; height: 54px; border-radius: 50%; overflow: hidden; background: var(--surface-3);
                 border: 3px solid transparent; box-sizing: border-box; display: grid; place-items: center; transition: transform .15s; position: relative; }
        .ss-av:hover { transform: translateY(-2px); }
        .ss-av img { width: 100%; height: 100%; object-fit: cover; }
        .ss-av.on { border-color: var(--accent); box-shadow: 0 0 0 3px color-mix(in srgb, var(--accent) 22%, transparent); }
        .ss-av.up { border: 2px dashed color-mix(in srgb, var(--accent) 45%, var(--line)); color: var(--accent); }
        .ss-av.up i, .ss-av.up svg { width: 20px; height: 20px; }
        .ss-row { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
        .ss-btn { all: unset; box-sizing: border-box; cursor: pointer; padding: 11px 20px; border-radius: 999px; background: var(--accent); color: var(--accent-ink);
                  font-size: 13px; font-weight: 750; display: inline-flex; align-items: center; gap: 7px; }
        .ss-btn:hover { filter: brightness(1.08); }
        .ss-btn:disabled, .ss-btn[disabled] { cursor: default; background: var(--surface-3); color: var(--muted); filter: none; }
        .ss-state { font-size: 12.5px; color: var(--muted); }
        .ss-state.ok { color: color-mix(in srgb, var(--success) 70%, var(--ink)); }
        .ss-state.bad { color: color-mix(in srgb, var(--danger) 75%, var(--ink)); }
        .ss-modes { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; margin-bottom: 20px; }
        .ss-mode { all: unset; box-sizing: border-box; cursor: pointer; border: 1.5px solid var(--line); border-radius: 18px; padding: 10px; position: relative; }
        .ss-mode.active { border-color: var(--accent); box-shadow: 0 0 0 3px color-mix(in srgb, var(--accent) 18%, transparent); }
        .ss-mode b { display: block; font-size: 13px; font-weight: 700; color: var(--ink); margin: 10px 4px 2px; }
        .ss-mode .ss-tick { position: absolute; right: 12px; bottom: 12px; width: 20px; height: 20px; border-radius: 50%; background: var(--accent); color: var(--accent-ink);
                            display: none; place-items: center; }
        .ss-mode.active .ss-tick { display: grid; }
        .ss-tick i, .ss-tick svg { width: 12px; height: 12px; }
        .ss-pv { height: 76px; border-radius: 12px; overflow: hidden; display: flex; gap: 6px; padding: 8px; box-sizing: border-box; }
        .ss-pv .r { width: 16%; border-radius: 6px; }
        .ss-pv .c { flex: 1; display: flex; flex-direction: column; gap: 6px; }
        .ss-pv .c span { border-radius: 5px; height: 9px; }
        .ss-pv .c span:first-child { height: 18px; }
        .ss-pv.l { background: #f7f5fb; } .ss-pv.l .r { background: #fff; } .ss-pv.l .c span { background: #e7e2f3; }
        .ss-pv.d { background: #17131f; } .ss-pv.d .r { background: #221c30; } .ss-pv.d .c span { background: #342b4a; }
        .ss-pv .c span:first-child { background: var(--pv-accent, var(--accent)); }
        .ss-pv.a { background: linear-gradient(90deg, #f7f5fb 50%, #17131f 50%); }
        .ss-pv.a .r { background: #fff; } .ss-pv.a .c span { background: #bdb4d4; }
        .ss-cols { display: flex; flex-wrap: wrap; gap: 10px; }
        .ss-col { all: unset; box-sizing: border-box; cursor: pointer; display: inline-flex; align-items: center; gap: 9px; padding: 8px 14px 8px 8px;
                  border: 1.5px solid var(--line); border-radius: 999px; font-size: 13px; font-weight: 650; color: var(--ink); }
        .ss-col .dot { width: 24px; height: 24px; border-radius: 50%; display: grid; place-items: center; color: #fff; }
        .ss-col .dot i, .ss-col .dot svg { width: 13px; height: 13px; opacity: 0; }
        .ss-col.active { border-color: var(--accent); background: color-mix(in srgb, var(--accent) 8%, var(--surface)); }
        .ss-col.active .dot i, .ss-col.active .dot svg { opacity: 1; }
        .ss-note { font-size: 12px; color: var(--muted); margin: 14px 0 0; display: flex; gap: 6px; align-items: center; }
        .ss-note i, .ss-note svg { width: 14px; height: 14px; flex-shrink: 0; }
        .ss-pw { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; margin-bottom: 14px; }
        .ss-pw .ss-in { max-width: none; }
        .ss-meter { display: flex; align-items: center; gap: 12px; flex: 1; min-width: 220px; }
        .ss-bars { display: flex; gap: 5px; }
        .ss-bars span { width: 30px; height: 5px; border-radius: 3px; background: var(--surface-3); }
        .ss-bars.s1 span:nth-child(-n+1) { background: var(--danger); }
        .ss-bars.s2 span:nth-child(-n+2) { background: var(--warning); }
        .ss-bars.s3 span:nth-child(-n+3) { background: color-mix(in srgb, var(--success) 70%, var(--warning)); }
        .ss-bars.s4 span { background: var(--success); }
        .ss-toast { position: fixed; left: 50%; bottom: 28px; transform: translate(-50%, 20px); opacity: 0; pointer-events: none; z-index: 3000;
                    background: var(--ink); color: var(--surface); padding: 11px 18px; border-radius: 999px; font-size: 13px; font-weight: 650;
                    transition: opacity .25s, transform .25s; box-shadow: var(--shadow-3); }
        .ss-toast.show { opacity: 1; transform: translate(-50%, 0); }
        .ss-toast.bad { background: var(--danger); color: #fff; }
        .ss-courses { display: flex; flex-wrap: wrap; gap: 8px; }
        .ss-course { display: inline-flex; align-items: center; gap: 8px; padding: 8px 14px 8px 8px; border-radius: 999px; background: var(--surface-3);
                     font-size: 13px; font-weight: 650; color: var(--ink); }
        .ss-course b { font-size: 9.5px; font-weight: 800; letter-spacing: .05em; padding: 3px 7px; border-radius: 999px; background: var(--surface); color: var(--muted); }
        @media (max-width: 900px) {
            .ss-grid { grid-template-columns: 1fr; } .ss-side { position: static; }
            .ss-nav { flex-direction: row; overflow-x: auto; }
            .ss-pw { grid-template-columns: 1fr; }
        }
        @media (max-width: 560px) { .ss-modes { grid-template-columns: 1fr; } .ss-title { font-size: 28px; } .ss-sec { padding: 18px; } }
    </style>

    <div class="ss-eyebrow">Workspace</div>
    <h1 class="ss-title">Settings</h1>

    <div class="ss-grid">
        <aside class="ss-side">
            <div class="ss-card-me">
                <div class="ss-me-av" id="ssMeAv">
                    <?php if ($ss_pic !== ''): ?><img src="<?= htmlspecialchars($ss_pic) ?>" alt=""><?php else: ?><?= htmlspecialchars(ss_initials($ss_name)) ?><?php endif; ?>
                </div>
                <div class="ss-me-name" id="ssMeName"><?= htmlspecialchars($ss_name) ?></div>
                <div class="ss-me-scope"><?= htmlspecialchars($ss_scope) ?></div>
                <div class="ss-me-mail"><?= htmlspecialchars((string)($currentUser['email'] ?? '')) ?></div>
            </div>
            <nav class="ss-nav" id="ssNav">
                <button type="button" class="on" data-to="ssProfile"><i data-lucide="user"></i> Profile</button>
                <button type="button" data-to="ssLook"><i data-lucide="palette"></i> Appearance</button>
                <button type="button" data-to="ssPass"><i data-lucide="lock"></i> Password</button>
                <?php if ($ss_role === 'Focal Person'): ?><button type="button" data-to="ssCourses"><i data-lucide="book-open"></i> Your courses</button><?php endif; ?>
            </nav>
        </aside>

        <div class="ss-main">
            <!-- PROFILE -->
            <section class="ss-sec" id="ssProfile">
                <h2>Profile</h2>
                <p class="ss-sub">How you appear to the rest of the office.</p>
                <form id="ssProfileForm" onsubmit="ssSaveProfile(event)" enctype="multipart/form-data">
                    <label class="ss-lab" for="ssName">Display name</label>
                    <input class="ss-in" id="ssName" name="username" value="<?= htmlspecialchars($ss_name) ?>" maxlength="80" required autocomplete="name">

                    <label class="ss-lab" style="margin-top:18px;">Avatar</label>
                    <div class="ss-avs" id="ssAvs">
                        <?php foreach ($ss_urls as $u): ?>
                            <button type="button" class="ss-av <?= $u === $ss_pic ? 'on' : '' ?>" data-url="<?= htmlspecialchars($u) ?>" onclick="ssPickAv(this)" aria-label="Choose this avatar">
                                <img src="<?= htmlspecialchars($u) ?>" alt="" loading="lazy">
                            </button>
                        <?php endforeach; ?>
                        <?php if ($ss_pic !== '' && !in_array($ss_pic, $ss_urls, true)): ?>
                            <button type="button" class="ss-av on" data-url="" data-current="1" onclick="ssPickAv(this)" title="Your current picture" aria-label="Keep your current picture">
                                <img src="<?= htmlspecialchars($ss_pic) ?>" alt="">
                            </button>
                        <?php endif; ?>
                        <label class="ss-av up" title="Upload a photo (JPG, PNG, WEBP or GIF, up to 3 MB)">
                            <i data-lucide="image-plus"></i>
                            <input type="file" name="photo" id="ssPhoto" accept="image/jpeg,image/png,image/webp,image/gif" hidden onchange="ssPickPhoto(this)">
                        </label>
                    </div>
                    <input type="hidden" name="avatar" id="ssAvatar" value="">

                    <div class="ss-row">
                        <button type="submit" class="ss-btn" id="ssProfileBtn" disabled>Save profile</button>
                        <span class="ss-state" id="ssProfileState">Nothing to save</span>
                    </div>
                </form>
            </section>

            <!-- APPEARANCE -->
            <section class="ss-sec" id="ssLook">
                <h2>Appearance</h2>
                <p class="ss-sub">Changes apply straight away, across every screen.</p>
                <div class="ss-modes">
                    <button type="button" class="ss-mode" data-theme-choice="theme-light" onclick="PortalTheme.set('theme-light')">
                        <div class="ss-pv l"><div class="r"></div><div class="c"><span></span><span></span><span style="width:60%"></span></div></div>
                        <b>Light</b><span class="ss-tick"><i data-lucide="check"></i></span>
                    </button>
                    <button type="button" class="ss-mode" data-theme-choice="theme-dark" onclick="PortalTheme.set('theme-dark')">
                        <div class="ss-pv d"><div class="r"></div><div class="c"><span></span><span></span><span style="width:60%"></span></div></div>
                        <b>Dark</b><span class="ss-tick"><i data-lucide="check"></i></span>
                    </button>
                    <button type="button" class="ss-mode" data-theme-choice="theme-auto" onclick="PortalTheme.set('theme-auto')">
                        <div class="ss-pv a"><div class="r"></div><div class="c"><span></span><span></span><span style="width:60%"></span></div></div>
                        <b>Match device</b><span class="ss-tick"><i data-lucide="check"></i></span>
                    </button>
                </div>
                <label class="ss-lab">Colour</label>
                <div class="ss-cols">
                    <?php foreach ($ss_colours as $k => [$label, $hex]): ?>
                        <button type="button" class="ss-col" data-accent-choice="<?= $k ?>" onclick="PortalTheme.setAccent('<?= $k ?>')">
                            <span class="dot" style="background:<?= $hex ?>"><i data-lucide="check"></i></span><?= $label ?>
                        </button>
                    <?php endforeach; ?>
                </div>
                <p class="ss-note"><i data-lucide="monitor-smartphone"></i> Saved on this device &mdash; your phone and your office computer can each have their own.</p>
            </section>

            <!-- PASSWORD -->
            <section class="ss-sec" id="ssPass">
                <h2>Password</h2>
                <p class="ss-sub">You stay signed in here after changing it.</p>
                <form id="ssPassForm" onsubmit="ssSavePass(event)">
                    <div class="ss-pw">
                        <div><label class="ss-lab" for="ssPw0">Current password</label><input class="ss-in" type="password" id="ssPw0" name="current_password" autocomplete="current-password" required></div>
                        <div><label class="ss-lab" for="ssPw1">New password</label><input class="ss-in" type="password" id="ssPw1" name="new_password" autocomplete="new-password" minlength="8" required oninput="ssMeter()"></div>
                        <div><label class="ss-lab" for="ssPw2">Confirm new password</label><input class="ss-in" type="password" id="ssPw2" name="confirm_password" autocomplete="new-password" required oninput="ssMeter()"></div>
                    </div>
                    <div class="ss-row">
                        <div class="ss-meter"><div class="ss-bars" id="ssBars"><span></span><span></span><span></span><span></span></div><span class="ss-state" id="ssPwState">Type a new password</span></div>
                        <button type="submit" class="ss-btn" id="ssPassBtn">Change password</button>
                    </div>
                </form>
            </section>

            <?php if ($ss_role === 'Focal Person'): ?>
            <!-- YOUR COURSES (Focal Person) -->
            <section class="ss-sec" id="ssCourses">
                <h2>Your courses</h2>
                <p class="ss-sub">The groups you check and schedule come from these. Only the research office can change them.</p>
                <?php if (!$ss_courses): ?>
                    <p class="ss-note"><i data-lucide="info"></i> No courses are assigned to you yet. Ask the research office to add them.</p>
                <?php else: ?>
                    <div class="ss-courses">
                        <?php foreach ($ss_courses as $c): ?>
                            <span class="ss-course"><b><?= htmlspecialchars(aa_school_tag((string)$c['department'])) ?></b><?= htmlspecialchars((string)$c['program']) ?></span>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
            <?php endif; ?>
        </div>
    </div>
    <div class="ss-toast" id="ssToast" role="status" aria-live="polite"></div>
</div>
<script>
(function () {
    var CSRF = <?= json_encode($_SESSION['csrf_token'] ?? '') ?>;
    var startName = <?= json_encode($ss_name) ?>;
    var dirty = { name: false, av: false };

    function toast(msg, bad) {
        var t = document.getElementById('ssToast');
        t.textContent = msg; t.classList.toggle('bad', !!bad); t.classList.add('show');
        clearTimeout(t._h); t._h = setTimeout(function () { t.classList.remove('show'); }, 2800);
    }
    function paintProfileState() {
        var any = dirty.name || dirty.av, btn = document.getElementById('ssProfileBtn'), st = document.getElementById('ssProfileState');
        btn.disabled = !any; st.className = 'ss-state'; st.textContent = any ? 'Unsaved changes' : 'Nothing to save';
    }
    document.getElementById('ssName').addEventListener('input', function () {
        dirty.name = this.value.trim() !== startName; paintProfileState();
    });
    window.ssPickAv = function (b) {
        document.querySelectorAll('#ssAvs .ss-av').forEach(function (x) { x.classList.remove('on'); });
        b.classList.add('on');
        document.getElementById('ssAvatar').value = b.dataset.url || '';
        var f = document.getElementById('ssPhoto'); if (f) f.value = '';
        dirty.av = !b.dataset.current; paintProfileState();
    };
    window.ssPickPhoto = function (inp) {
        var file = inp.files && inp.files[0]; if (!file) return;
        if (file.size > 3 * 1024 * 1024) { toast('That photo is over 3 MB.', true); inp.value = ''; return; }
        document.querySelectorAll('#ssAvs .ss-av').forEach(function (x) { x.classList.remove('on'); });
        var lab = inp.closest('.ss-av'); lab.classList.add('on');
        var r = new FileReader();
        r.onload = function () { lab.style.backgroundImage = 'url(' + r.result + ')'; lab.style.backgroundSize = 'cover'; lab.querySelector('svg, i') && (lab.querySelector('svg, i').style.opacity = 0); };
        r.readAsDataURL(file);
        document.getElementById('ssAvatar').value = '';
        dirty.av = true; paintProfileState();
    };
    function avatarMarkup(pic, initials) {
        return pic ? '<img src="' + pic.replace(/"/g, '&quot;') + '" alt="">' : initials;
    }
    window.ssSaveProfile = function (e) {
        e.preventDefault();
        var btn = document.getElementById('ssProfileBtn'), st = document.getElementById('ssProfileState');
        var fd = new FormData(document.getElementById('ssProfileForm'));
        fd.append('action', 'save_profile'); fd.append('csrf_token', CSRF);
        btn.disabled = true; st.className = 'ss-state'; st.textContent = 'Saving…';
        fetch('staff_settings_handler.php', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d.ok) { st.className = 'ss-state bad'; st.textContent = d.message; btn.disabled = false; toast(d.message, true); return; }
                startName = d.username; dirty = { name: false, av: false };
                st.className = 'ss-state ok'; st.textContent = 'Saved';
                // Everywhere this shell shows who is signed in.
                document.getElementById('ssMeAv').innerHTML = avatarMarkup(d.pic, d.initials);
                document.getElementById('ssMeName').textContent = d.username;
                document.querySelectorAll('.sh-avatar').forEach(function (a) { a.innerHTML = avatarMarkup(d.pic, d.initials); a.classList.toggle('has-pic', !!d.pic); });
                document.querySelectorAll('.dock-dropdown-name').forEach(function (n) { n.textContent = d.username; });
                toast('Profile saved');
            })
            .catch(function () { st.className = 'ss-state bad'; st.textContent = 'Could not reach the server.'; btn.disabled = false; });
    };
    window.ssMeter = function () {
        var p = document.getElementById('ssPw1').value, c = document.getElementById('ssPw2').value;
        var s = 0;
        if (p.length >= 8) s++;
        if (p.length >= 12) s++;
        if (/[A-Z]/.test(p) && /[a-z]/.test(p)) s++;
        if (/\d/.test(p) && /[^A-Za-z0-9]/.test(p)) s++;
        if (p && p.length < 8) s = 1;
        document.getElementById('ssBars').className = 'ss-bars' + (p ? ' s' + Math.max(1, s) : '');
        var st = document.getElementById('ssPwState');
        st.className = 'ss-state';
        if (!p) st.textContent = 'Type a new password';
        else if (p.length < 8) st.textContent = 'At least 8 characters';
        else if (c && c !== p) { st.className = 'ss-state bad'; st.textContent = 'The two do not match yet'; }
        else st.textContent = ['', 'Weak', 'Fair', 'Good', 'Strong'][Math.max(1, s)];
    };
    window.ssSavePass = function (e) {
        e.preventDefault();
        var fd = new FormData(document.getElementById('ssPassForm'));
        fd.append('action', 'change_password'); fd.append('csrf_token', CSRF);
        var btn = document.getElementById('ssPassBtn'); btn.disabled = true;
        fetch('staff_settings_handler.php', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                btn.disabled = false; toast(d.message, !d.ok);
                if (d.ok) { document.getElementById('ssPassForm').reset(); ssMeter(); }
            })
            .catch(function () { btn.disabled = false; toast('Could not reach the server.', true); });
    };
    // Side nav: scroll to a section, and light the one in view.
    var main = document.querySelector('.main-workspace-content');
    document.querySelectorAll('#ssNav button').forEach(function (b) {
        b.addEventListener('click', function () {
            var t = document.getElementById(b.dataset.to);
            if (t) t.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
    });
    if ('IntersectionObserver' in window) {
        var io = new IntersectionObserver(function (es) {
            es.forEach(function (en) {
                if (!en.isIntersecting) return;
                document.querySelectorAll('#ssNav button').forEach(function (b) { b.classList.toggle('on', b.dataset.to === en.target.id); });
            });
        }, { root: main || null, rootMargin: '0px 0px -60% 0px' });
        ['ssProfile', 'ssLook', 'ssPass', 'ssCourses'].forEach(function (id) { var el = document.getElementById(id); if (el) io.observe(el); });
    }
    if (window.PortalTheme) PortalTheme.apply();
})();
</script>
