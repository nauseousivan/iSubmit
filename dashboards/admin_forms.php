<?php
// dashboards/admin_forms.php — the blank forms students download (2026-10-05; redesigned the same day). ADMIN ONLY.
//
// A document library: one compact ROW per form (config/forms.php rd_form_catalogue()), filtered by
// phase and searchable. Each row shows what students get right now for MCNP and ISAP — ONE cell
// when both schools get the same file (most forms), two when they differ. Everything you DO with a
// form happens in its side panel: preview the current files, drop in a new one (that school / both),
// and the version timeline, where any earlier upload — or the file that came with the system — can
// be put back. Writes: admin_forms_handler.php (unchanged contract: upload / restore / builtin).
// Maintenance only: students are not told a form changed; they simply get the current one.

require_once '../config/db.php';
if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'Admin') exit('Access Denied');

$cat = rd_form_catalogue();
$hist = [];
foreach ($pdo->query("SELECT t.*, u.username AS by_name, DATE_FORMAT(t.uploaded_at, '%b %e, %Y') AS on_day,
                             DATE_FORMAT(t.uploaded_at, '%b %e, %Y · %l:%i %p') AS on_at
                      FROM form_templates t LEFT JOIN users u ON u.user_id = t.uploaded_by
                      ORDER BY t.template_id DESC")->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $hist[$r['slot']][$r['institution']][] = $r;
}
$root = dirname(__DIR__);
$size = fn($b) => $b >= 1048576 ? round($b / 1048576, 1) . ' MB' : max(1, round($b / 1024)) . ' KB';
$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

// Everything a row and its panel need, per form and school.
$forms = [];
$yours = 0;
foreach ($cat as $slot => $c) {
    $sides = [];
    foreach (RD_FORM_INSTITUTIONS as $inst) {
        $cur = rd_form_current($pdo, $slot, $inst);
        $vers = $hist[$slot][$inst] ?? [];
        $curV = null;
        foreach ($vers as $v) if ($cur['row'] && (int)$v['template_id'] === (int)$cur['row']['template_id']) $curV = $v;
        $abs = $root . '/' . $cur['path'];
        $sides[$inst] = [
            'path' => $cur['path'], 'uploaded' => $cur['uploaded'], 'versions' => $vers, 'cur' => $curV,
            'name' => $curV['original_name'] ?? basename($cur['path']),
            'ext'  => strtolower(pathinfo($cur['path'], PATHINFO_EXTENSION)),
            'size' => is_file($abs) ? $size(filesize($abs)) : 'missing',
            'url'  => '../' . $cur['path'] . ($cur['uploaded'] ? '?v=' . (int)$cur['row']['template_id'] : ''),
            'default' => basename($c['default'][$inst === 'MCNP' ? 0 : 1]),
        ];
        if ($cur['uploaded']) $yours++;
    }
    $forms[$slot] = $c + ['sides' => $sides, 'same' => $sides['MCNP']['path'] === $sides['ISAP']['path'],
                          'phases' => array_map('trim', explode('·', $c['phase']))];
}
$phases = ['Proposal', 'Statistics', 'Final', 'Plagiarism'];
$phaseN = array_fill_keys($phases, 0);
foreach ($forms as $f) foreach ($f['phases'] as $p) if (isset($phaseN[$p])) $phaseN[$p]++;
$split = count(array_filter($forms, fn($f) => !$f['same']));

$csrf = htmlspecialchars($_SESSION['csrf_token']);
$flash = $_SESSION['message'] ?? ''; $flash_type = $_SESSION['message_type'] ?? 'success';
unset($_SESSION['message'], $_SESSION['message_type']);

/** The small file chip used in rows and the panel. */
function fm_chip(array $s, string $who, callable $h): string
{
    $img = in_array($s['ext'], ['jpg', 'jpeg', 'png'], true);
    return '<span class="fm-chip"><span class="fm-type ' . ($img ? 'img' : 'pdf') . '">' . ($img ? 'IMG' : 'PDF') . '</span>'
         . '<span class="fm-chip-t"><b title="' . $h($s['name']) . '">' . $h($s['name']) . '</b>'
         . '<small>' . ($who !== '' ? '<em>' . $who . '</em> · ' : '')
         . ($s['uploaded'] ? '<i class="fm-dot"></i>Updated ' . $h($s['cur']['on_day'] ?? '') : 'Built-in') . ' · ' . $h($s['size']) . '</small></span></span>';
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
    <title>Forms</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Outfit:wght@500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/portal.css">
    <script src="../assets/js/portal.js"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        [hidden] { display: none !important; }
        body.admin-module { padding: 0; }
        .fm { --disp: "Outfit", var(--font-ui); padding: 24px 28px 56px; max-width: 1180px; margin: 0 auto; }
        .fm i, .fm svg { flex-shrink: 0; }

        /* ---- head */
        .fm-top { display: flex; align-items: flex-end; justify-content: space-between; gap: 18px; flex-wrap: wrap; margin-bottom: 18px; }
        .fm-eyebrow { font-size: 12.5px; font-weight: 700; color: var(--muted); }
        .fm-title { font-family: var(--disp); font-size: 34px; font-weight: 700; letter-spacing: -.02em; color: var(--ink); margin: 2px 0 4px; }
        .fm-sub { font-size: 13.5px; color: var(--muted); margin: 0; max-width: 560px; line-height: 1.5; }
        .fm-stats { display: flex; gap: 8px; flex-wrap: wrap; }
        .fm-stat { display: flex; flex-direction: column; padding: 10px 16px; border-radius: 16px; background: var(--surface); border: 1px solid var(--line); min-width: 96px; }
        .fm-stat b { font-family: var(--disp); font-size: 22px; font-weight: 700; color: var(--ink); line-height: 1.1; }
        .fm-stat small { font-size: 11.5px; color: var(--muted); font-weight: 600; }
        .fm-flash { padding: 11px 16px; border-radius: 14px; margin-bottom: 16px; font-size: 13px; font-weight: 600; display: flex; gap: 8px; align-items: center;
                    background: color-mix(in srgb, var(--success) 14%, var(--surface)); color: color-mix(in srgb, var(--success) 65%, var(--ink)); }
        .fm-flash.bad { background: color-mix(in srgb, var(--danger) 12%, var(--surface)); color: color-mix(in srgb, var(--danger) 65%, var(--ink)); }
        .fm-flash i, .fm-flash svg { width: 16px; height: 16px; }

        /* ---- toolbar: phase pills + search */
        .fm-bar { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; margin-bottom: 12px; }
        .fm-pills { display: inline-flex; gap: 4px; padding: 4px; border-radius: 999px; background: var(--surface-2); }
        .fm-pills button { all: unset; cursor: pointer; padding: 7px 14px; border-radius: 999px; font-size: 13px; font-weight: 700; color: var(--muted); display: inline-flex; gap: 6px; align-items: center; }
        .fm-pills button span { font-size: 11.5px; opacity: .7; }
        .fm-pills button:hover { color: var(--ink); }
        .fm-pills button.on { background: var(--surface); color: var(--ink); box-shadow: 0 1px 3px color-mix(in srgb, var(--ink) 12%, transparent); }
        .fm-search { display: flex; align-items: center; gap: 8px; height: 40px; padding: 0 14px; border-radius: 999px; background: var(--surface); border: 1px solid var(--line); min-width: 240px; }
        .fm-search input { all: unset; flex: 1; font-size: 13.5px; color: var(--ink); }
        .fm-search i, .fm-search svg { width: 15px; height: 15px; color: var(--muted); }
        .fm-search:focus-within { border-color: var(--accent); }

        /* ---- the list */
        .fm-list { background: var(--surface); border: 1px solid var(--line); border-radius: 22px; overflow: hidden; }
        .fm-cols, .fm-row { display: grid; grid-template-columns: minmax(240px, 1.15fr) minmax(0, 1fr) minmax(0, 1fr) 44px; gap: 14px; align-items: center; padding: 0 18px; }
        .fm-cols { height: 40px; font-size: 11px; font-weight: 800; letter-spacing: .08em; color: var(--muted); text-transform: uppercase; background: var(--surface-2); }
        .fm-row { min-height: 76px; border-top: 1px solid var(--line); cursor: pointer; transition: background .15s; }
        .fm-cols + .fm-row { border-top: 0; }
        .fm-row:hover { background: color-mix(in srgb, var(--accent) 4%, var(--surface)); }
        .fm-row.flash { animation: fmFlash 2.4s ease-out; }
        @keyframes fmFlash { 0%, 30% { background: color-mix(in srgb, var(--accent) 14%, var(--surface)); } 100% { background: transparent; } }
        .fm-form { display: flex; align-items: center; gap: 12px; min-width: 0; padding: 12px 0; }
        .fm-doc { width: 42px; height: 48px; border-radius: 10px; flex-shrink: 0; position: relative; display: grid; place-items: center;
                  background: color-mix(in srgb, var(--accent) 9%, var(--surface)); color: var(--accent); }
        .fm-doc::after { content: ''; position: absolute; top: 0; right: 0; width: 12px; height: 12px; border-bottom-left-radius: 6px;
                         background: color-mix(in srgb, var(--accent) 22%, var(--surface)); }
        .fm-doc i, .fm-doc svg { width: 18px; height: 18px; }
        .fm-form-t { min-width: 0; }
        .fm-form-t b { display: block; font-size: 14px; font-weight: 800; color: var(--ink); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .fm-form-t small { display: flex; gap: 6px; align-items: center; flex-wrap: wrap; margin-top: 3px; }
        .fm-tag { font-size: 11px; font-weight: 700; padding: 1px 8px; border-radius: 999px; background: var(--surface-2); color: var(--muted); }
        .fm-tag.code { background: color-mix(in srgb, var(--accent) 10%, var(--surface)); color: var(--accent); }
        .fm-cell { min-width: 0; }
        .fm-cell.both { grid-column: span 2; }
        .fm-go { width: 34px; height: 34px; border-radius: 50%; display: grid; place-items: center; color: var(--muted); transition: background .15s, color .15s; }
        .fm-row:hover .fm-go { background: var(--accent); color: var(--accent-ink); }
        .fm-go i, .fm-go svg { width: 16px; height: 16px; }

        .fm-chip { display: flex; align-items: center; gap: 10px; min-width: 0; }
        .fm-type { width: 34px; height: 34px; border-radius: 9px; flex-shrink: 0; display: grid; place-items: center; font-size: 9.5px; font-weight: 900; letter-spacing: .04em; }
        .fm-type.pdf { background: color-mix(in srgb, #e5484d 13%, var(--surface)); color: #d23b40; }
        .fm-type.img { background: color-mix(in srgb, #2a78d6 13%, var(--surface)); color: #2a78d6; }
        .fm-chip-t { min-width: 0; }
        .fm-chip-t b { display: block; font-size: 13px; font-weight: 700; color: var(--ink); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .fm-chip-t small { display: flex; align-items: center; gap: 4px; font-size: 11.5px; color: var(--muted); white-space: nowrap; overflow: hidden; }
        .fm-chip-t em { font-style: normal; font-weight: 800; color: var(--ink); }
        .fm-dot { width: 6px; height: 6px; border-radius: 50%; background: var(--success); display: inline-block; }
        .fm-empty { padding: 40px 20px; text-align: center; color: var(--muted); font-size: 13.5px; }

        @media (max-width: 860px) {
            .fm-cols { display: none; }
            .fm-row { grid-template-columns: 1fr 34px; padding: 12px 16px; gap: 8px; }
            .fm-form { grid-column: 1; padding: 0; }
            .fm-cell, .fm-cell.both { grid-column: 1; }
            .fm-go { grid-column: 2; grid-row: 1; }
        }

        /* ---- the side panel */
        .fm-scrim { position: fixed; inset: 0; background: color-mix(in srgb, #0b0614 40%, transparent); z-index: 50; opacity: 0; pointer-events: none; transition: opacity .25s; }
        .fm-scrim.on { opacity: 1; pointer-events: auto; }
        .fm-panel { position: fixed; top: 0; right: 0; bottom: 0; width: min(520px, 100vw); z-index: 51; background: var(--surface); border-left: 1px solid var(--line);
                    box-shadow: -24px 0 60px -30px rgba(0, 0, 0, .45); transform: translateX(104%); visibility: hidden;
                    transition: transform .32s cubic-bezier(.2, .9, .3, 1), visibility 0s linear .32s;   /* closed = gone, shadow and all */
                    display: flex; flex-direction: column; }
        .fm-panel.on { transform: none; visibility: visible; transition: transform .32s cubic-bezier(.2, .9, .3, 1); }
        .fm-ph { padding: 20px 22px 14px; border-bottom: 1px solid var(--line); display: flex; gap: 12px; align-items: flex-start; }
        .fm-ph-t { flex: 1; min-width: 0; }
        .fm-ph-t h2 { font-family: var(--disp); font-size: 21px; font-weight: 700; color: var(--ink); margin: 0 0 4px; letter-spacing: -.01em; }
        .fm-ph-t p { font-size: 12.5px; color: var(--muted); margin: 6px 0 0; line-height: 1.45; }
        .fm-x { all: unset; cursor: pointer; width: 34px; height: 34px; border-radius: 50%; display: grid; place-items: center; color: var(--muted); flex-shrink: 0; }
        .fm-x:hover { background: var(--surface-2); color: var(--ink); }
        .fm-x i, .fm-x svg { width: 18px; height: 18px; }
        .fm-pb { flex: 1; overflow-y: auto; padding: 18px 22px 28px; }
        .fm-sec { font-size: 11px; font-weight: 800; letter-spacing: .08em; text-transform: uppercase; color: var(--muted); margin: 0 0 10px; }
        .fm-sec + .fm-sec, .fm-block + .fm-sec { margin-top: 22px; }
        .fm-now { display: grid; gap: 8px; }
        .fm-now-row { display: flex; align-items: center; gap: 10px; padding: 10px 12px; border-radius: 14px; background: var(--surface-2); min-width: 0; }
        .fm-now-row .fm-chip { flex: 1; }
        .fm-link { display: inline-flex; align-items: center; gap: 5px; font-size: 12.5px; font-weight: 700; color: var(--accent); text-decoration: none; flex-shrink: 0; padding: 6px 10px; border-radius: 999px; }
        .fm-link:hover { background: color-mix(in srgb, var(--accent) 10%, var(--surface)); }
        .fm-link i, .fm-link svg { width: 14px; height: 14px; }

        .fm-drop { position: relative; border: 2px dashed color-mix(in srgb, var(--accent) 35%, var(--line)); border-radius: 18px; padding: 22px 16px; text-align: center;
                   background: color-mix(in srgb, var(--accent) 4%, var(--surface)); transition: border-color .15s, background .15s; cursor: pointer; }
        .fm-drop:hover, .fm-drop.over { border-color: var(--accent); background: color-mix(in srgb, var(--accent) 9%, var(--surface)); }
        .fm-drop input { position: absolute; inset: 0; opacity: 0; cursor: pointer; }
        .fm-drop-ic { width: 44px; height: 44px; border-radius: 14px; margin: 0 auto 8px; display: grid; place-items: center; background: var(--surface); color: var(--accent); }
        .fm-drop-ic i, .fm-drop-ic svg { width: 20px; height: 20px; }
        .fm-drop b { display: block; font-size: 13.5px; color: var(--ink); }
        .fm-drop small { font-size: 12px; color: var(--muted); }
        .fm-drop.has { border-style: solid; }
        .fm-picked { display: flex; align-items: center; gap: 10px; text-align: left; }
        .fm-picked .fm-chip { flex: 1; }
        .fm-field { margin-top: 14px; }
        .fm-field > span { display: block; font-size: 12.5px; font-weight: 700; color: var(--ink); margin-bottom: 6px; }
        .fm-seg { display: flex; gap: 4px; padding: 4px; border-radius: 14px; background: var(--surface-2); }
        .fm-seg label { flex: 1; text-align: center; cursor: pointer; padding: 9px 6px; border-radius: 10px; font-size: 13px; font-weight: 700; color: var(--muted); }
        .fm-seg input { position: absolute; opacity: 0; pointer-events: none; }
        .fm-seg label:has(input:checked) { background: var(--surface); color: var(--ink); box-shadow: 0 1px 3px color-mix(in srgb, var(--ink) 12%, transparent); }
        .fm-seg label:has(input:focus-visible) { outline: 2px solid var(--accent); }
        .fm-input { width: 100%; height: 40px; box-sizing: border-box; border-radius: 12px; border: 1px solid var(--line); background: var(--surface-2); color: var(--ink); padding: 0 12px; font: inherit; font-size: 13.5px; }
        .fm-input:focus { outline: none; border-color: var(--accent); }
        .fm-submit { all: unset; box-sizing: border-box; cursor: pointer; width: 100%; margin-top: 16px; height: 46px; border-radius: 14px; background: var(--accent); color: var(--accent-ink);
                     font-size: 14px; font-weight: 800; display: flex; align-items: center; justify-content: center; gap: 8px; }
        .fm-submit:hover { filter: brightness(1.08); }
        .fm-submit:disabled { opacity: .45; cursor: not-allowed; filter: none; }
        .fm-submit i, .fm-submit svg { width: 16px; height: 16px; }
        .fm-hint { font-size: 11.5px; color: var(--muted); margin: 8px 0 0; text-align: center; }

        .fm-tl { position: relative; padding-left: 18px; }
        .fm-tl::before { content: ''; position: absolute; left: 5px; top: 6px; bottom: 6px; width: 2px; border-radius: 2px; background: var(--line); }
        .fm-tl-i { position: relative; display: flex; align-items: center; gap: 10px; padding: 8px 0; min-width: 0; }
        .fm-tl-i::before { content: ''; position: absolute; left: -17px; top: 50%; width: 10px; height: 10px; margin-top: -5px; border-radius: 50%;
                           background: var(--surface); border: 2px solid var(--line); }
        .fm-tl-i.cur::before { background: var(--success); border-color: var(--success); }
        .fm-tl-t { flex: 1; min-width: 0; }
        .fm-tl-t b { display: block; font-size: 13px; font-weight: 700; color: var(--ink); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .fm-tl-t small { font-size: 11.5px; color: var(--muted); }
        .fm-tl-i form { margin: 0; }
        .fm-use { all: unset; cursor: pointer; font-size: 12px; font-weight: 700; color: var(--accent); padding: 5px 10px; border-radius: 999px; }
        .fm-use:hover { background: color-mix(in srgb, var(--accent) 10%, var(--surface)); }
        .fm-inuse { font-size: 10.5px; font-weight: 800; letter-spacing: .06em; color: color-mix(in srgb, var(--success) 72%, var(--ink));
                    background: color-mix(in srgb, var(--success) 13%, var(--surface)); padding: 3px 8px; border-radius: 999px; }
        .fm-tl-school { font-size: 12px; font-weight: 800; color: var(--ink); margin: 14px 0 2px; }
        .fm-tl-school:first-child { margin-top: 0; }
        @media (prefers-reduced-motion: reduce) { .fm-panel, .fm-scrim { transition: none; } .fm-row.flash { animation: none; } }
    </style>
</head>
<body class="admin-module">
<div class="fm">
    <div class="fm-top">
        <div>
            <div class="fm-eyebrow">System</div>
            <h1 class="fm-title">Forms</h1>
            <p class="fm-sub">The blank forms students download. When the office changes a format, upload the new file and students get it straight away.</p>
        </div>
        <div class="fm-stats">
            <div class="fm-stat"><b><?= count($forms) ?></b><small>forms</small></div>
            <div class="fm-stat"><b><?= $split ?></b><small>differ by school</small></div>
            <div class="fm-stat"><b><?= $yours ?></b><small>updated by you</small></div>
        </div>
    </div>

    <?php if ($flash): ?>
        <div class="fm-flash <?= $flash_type === 'error' ? 'bad' : '' ?>"><i data-lucide="<?= $flash_type === 'error' ? 'alert-circle' : 'check-circle' ?>"></i> <?= $h($flash) ?></div>
    <?php endif; ?>

    <div class="fm-bar">
        <div class="fm-pills" role="tablist" aria-label="Phase">
            <button type="button" class="on" data-ph="">All <span><?= count($forms) ?></span></button>
            <?php foreach ($phases as $p): ?><button type="button" data-ph="<?= $p ?>"><?= $p ?> <span><?= $phaseN[$p] ?></span></button><?php endforeach; ?>
        </div>
        <label class="fm-search"><i data-lucide="search"></i><input type="search" id="fmQ" placeholder="Find a form" autocomplete="off"></label>
    </div>

    <div class="fm-list" id="fmList">
        <div class="fm-cols"><span>Form</span><span>MCNP</span><span>ISAP</span><span></span></div>
        <?php foreach ($forms as $slot => $f): $m = $f['sides']['MCNP']; $i = $f['sides']['ISAP']; ?>
            <div class="fm-row" id="form-<?= $h($slot) ?>" data-slot="<?= $h($slot) ?>" data-ph="<?= $h(implode(' ', $f['phases'])) ?>"
                 data-q="<?= $h(strtolower($f['label'] . ' ' . $f['code'] . ' ' . $f['phase'] . ' ' . $m['name'] . ' ' . $i['name'])) ?>" tabindex="0" role="button" aria-label="Open <?= $h($f['label']) ?>">
                <div class="fm-form">
                    <span class="fm-doc"><i data-lucide="file-text"></i></span>
                    <div class="fm-form-t">
                        <b><?= $h($f['label']) ?></b>
                        <small><?php if ($f['code']): ?><span class="fm-tag code"><?= $h($f['code']) ?></span><?php endif; ?><?php foreach ($f['phases'] as $p): ?><span class="fm-tag"><?= $h($p) ?></span><?php endforeach; ?></small>
                    </div>
                </div>
                <?php if ($f['same']): ?>
                    <div class="fm-cell both"><?= fm_chip($m, 'Both schools', $h) ?></div>
                <?php else: ?>
                    <div class="fm-cell"><?= fm_chip($m, 'MCNP', $h) ?></div>
                    <div class="fm-cell"><?= fm_chip($i, 'ISAP', $h) ?></div>
                <?php endif; ?>
                <span class="fm-go"><i data-lucide="chevron-right"></i></span>
            </div>
        <?php endforeach; ?>
        <div class="fm-empty" id="fmNone" hidden>No form matches that.</div>
    </div>
</div>

<div class="fm-scrim" id="fmScrim"></div>
<?php foreach ($forms as $slot => $f): ?>
    <aside class="fm-panel" id="panel-<?= $h($slot) ?>" aria-label="<?= $h($f['label']) ?>" aria-hidden="true">
        <div class="fm-ph">
            <span class="fm-doc"><i data-lucide="file-text"></i></span>
            <div class="fm-ph-t">
                <h2><?= $h($f['label']) ?></h2>
                <small style="display:flex;gap:6px;flex-wrap:wrap"><?php if ($f['code']): ?><span class="fm-tag code"><?= $h($f['code']) ?></span><?php endif; ?><?php foreach ($f['phases'] as $p): ?><span class="fm-tag"><?= $h($p) ?></span><?php endforeach; ?></small>
                <p><i data-lucide="map-pin" style="width:12px;height:12px;vertical-align:-1px"></i> <?= $h($f['where']) ?></p>
            </div>
            <button type="button" class="fm-x" data-close aria-label="Close"><i data-lucide="x"></i></button>
        </div>
        <div class="fm-pb">
            <div class="fm-sec">What students get now</div>
            <div class="fm-now fm-block">
                <?php foreach ($f['same'] ? ['Both schools' => $f['sides']['MCNP']] : ['MCNP' => $f['sides']['MCNP'], 'ISAP' => $f['sides']['ISAP']] as $who => $s): ?>
                    <div class="fm-now-row"><?= fm_chip($s, $h($who), $h) ?><a class="fm-link" href="<?= $h($s['url']) ?>" target="_blank" rel="noopener"><i data-lucide="eye"></i> Preview</a></div>
                <?php endforeach; ?>
            </div>

            <div class="fm-sec">Upload a new version</div>
            <form class="fm-block" method="POST" action="admin_forms_handler.php" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                <input type="hidden" name="action" value="upload">
                <input type="hidden" name="slot" value="<?= $h($slot) ?>">
                <div class="fm-drop" data-types="<?= $h(implode(',', $f['types'])) ?>">
                    <input type="file" name="form_file" accept="<?= $h(implode(',', array_map(fn($t) => '.' . $t, $f['types']))) ?>" required>
                    <div class="fm-drop-empty">
                        <div class="fm-drop-ic"><i data-lucide="upload-cloud"></i></div>
                        <b>Drop the new file here, or click to browse</b>
                        <small><?= strtoupper(implode(', ', array_unique(array_map(fn($t) => $t === 'jpeg' ? 'jpg' : $t, $f['types'])))) ?> · 15 MB at most</small>
                    </div>
                    <div class="fm-picked" hidden></div>
                </div>
                <div class="fm-field">
                    <span>Which school gets it</span>
                    <div class="fm-seg">
                        <label><input type="radio" name="institution" value="MCNP"> MCNP</label>
                        <label><input type="radio" name="institution" value="ISAP"> ISAP</label>
                        <label><input type="radio" name="institution" value="BOTH" checked> Both</label>
                    </div>
                </div>
                <label class="fm-field"><span>Note <small style="color:var(--muted);font-weight:500">— optional, e.g. "2026 revision"</small></span>
                    <input class="fm-input" type="text" name="note" maxlength="200" autocomplete="off"></label>
                <button type="submit" class="fm-submit" disabled><i data-lucide="check"></i> Upload and use it</button>
                <p class="fm-hint">The current file stays in the versions below — you can switch back anytime.</p>
            </form>

            <div class="fm-sec">Versions</div>
            <div>
                <?php foreach (RD_FORM_INSTITUTIONS as $inst): $s = $f['sides'][$inst]; ?>
                    <div class="fm-tl-school"><?= $inst ?></div>
                    <div class="fm-tl">
                        <?php foreach ($s['versions'] as $v): $cur = (int)$v['is_current'] === 1; ?>
                            <div class="fm-tl-i <?= $cur ? 'cur' : '' ?>">
                                <div class="fm-tl-t"><b title="<?= $h($v['original_name']) ?>"><?= $h($v['original_name'] ?: basename($v['file_path'])) ?></b>
                                    <small><?= $h($v['on_at']) ?><?= $v['by_name'] ? ' · ' . $h($v['by_name']) : '' ?><?= $v['note'] ? ' · ' . $h($v['note']) : '' ?></small></div>
                                <a class="fm-link" href="../<?= $h($v['file_path']) ?>?v=<?= (int)$v['template_id'] ?>" target="_blank" rel="noopener">View</a>
                                <?php if ($cur): ?><span class="fm-inuse">IN USE</span><?php else: ?>
                                    <form method="POST" action="admin_forms_handler.php">
                                        <input type="hidden" name="csrf_token" value="<?= $csrf ?>"><input type="hidden" name="action" value="restore">
                                        <input type="hidden" name="slot" value="<?= $h($slot) ?>"><input type="hidden" name="template_id" value="<?= (int)$v['template_id'] ?>">
                                        <button type="submit" class="fm-use">Use this</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                        <div class="fm-tl-i <?= $s['uploaded'] ? '' : 'cur' ?>">
                            <div class="fm-tl-t"><b>Came with the system</b><small><?= $h($s['default']) ?></small></div>
                            <?php if (!$s['uploaded']): ?><span class="fm-inuse">IN USE</span><?php else: ?>
                                <form method="POST" action="admin_forms_handler.php">
                                    <input type="hidden" name="csrf_token" value="<?= $csrf ?>"><input type="hidden" name="action" value="builtin">
                                    <input type="hidden" name="slot" value="<?= $h($slot) ?>"><input type="hidden" name="institution" value="<?= $inst ?>">
                                    <button type="submit" class="fm-use">Use this</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </aside>
<?php endforeach; ?>

<script>
(function () {
    var list = document.getElementById('fmList'), scrim = document.getElementById('fmScrim'), q = document.getElementById('fmQ'), ph = '';
    var open = null;

    // ---- filter: phase pills + search
    function filter() {
        var t = q.value.trim().toLowerCase(), n = 0;
        list.querySelectorAll('.fm-row').forEach(function (r) {
            var ok = (!ph || (' ' + r.dataset.ph + ' ').indexOf(' ' + ph + ' ') !== -1) && (!t || r.dataset.q.indexOf(t) !== -1);
            r.hidden = !ok; if (ok) n++;
        });
        document.getElementById('fmNone').hidden = n > 0;
    }
    document.querySelectorAll('.fm-pills button').forEach(function (b) {
        b.addEventListener('click', function () {
            document.querySelectorAll('.fm-pills button').forEach(function (x) { x.classList.toggle('on', x === b); });
            ph = b.dataset.ph; filter();
        });
    });
    q.addEventListener('input', filter);

    // ---- the side panel
    function show(slot) {
        var p = document.getElementById('panel-' + slot);
        if (!p) return;
        if (open) hide();
        open = p; p.classList.add('on'); p.setAttribute('aria-hidden', 'false'); scrim.classList.add('on');
        setTimeout(function () { var x = p.querySelector('[data-close]'); if (x) x.focus(); }, 50);
    }
    function hide() {
        if (!open) return;
        open.classList.remove('on'); open.setAttribute('aria-hidden', 'true'); scrim.classList.remove('on'); open = null;
    }
    list.addEventListener('click', function (e) { var r = e.target.closest('.fm-row'); if (r) show(r.dataset.slot); });
    list.addEventListener('keydown', function (e) { var r = e.target.closest('.fm-row'); if (r && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); show(r.dataset.slot); } });
    scrim.addEventListener('click', hide);
    document.querySelectorAll('[data-close]').forEach(function (b) { b.addEventListener('click', hide); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') hide(); });

    // ---- drop zone: drag a file in, see what was picked, check the type before sending
    function kb(n) { return n >= 1048576 ? (n / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(n / 1024)) + ' KB'; }
    document.querySelectorAll('.fm-drop').forEach(function (d) {
        var input = d.querySelector('input[type=file]'), form = d.closest('form'), btn = form.querySelector('.fm-submit');
        var types = d.dataset.types.split(','), empty = d.querySelector('.fm-drop-empty'), picked = d.querySelector('.fm-picked');
        function paint() {
            var f = input.files && input.files[0];
            if (!f) { picked.hidden = true; empty.hidden = false; d.classList.remove('has'); btn.disabled = true; return; }
            var ext = (f.name.split('.').pop() || '').toLowerCase(), ok = types.indexOf(ext) !== -1 && f.size <= 15 * 1024 * 1024, img = /^(jpe?g|png)$/.test(ext);
            empty.hidden = true; picked.hidden = false; d.classList.add('has');
            picked.innerHTML = '<span class="fm-chip"><span class="fm-type ' + (img ? 'img' : 'pdf') + '">' + (img ? 'IMG' : 'PDF') + '</span><span class="fm-chip-t"><b></b><small>' +
                (ok ? 'Ready · ' + kb(f.size) : '<span style="color:var(--danger)">' + (f.size > 15728640 ? 'Too large (15 MB at most)' : 'This form takes ' + types.join(', ').toUpperCase() + ' only') + '</span>') +
                '</small></span></span><span class="fm-link">Change</span>';
            picked.querySelector('b').textContent = f.name;
            btn.disabled = !ok;
        }
        input.addEventListener('change', paint);
        ['dragenter', 'dragover'].forEach(function (t) { d.addEventListener(t, function (e) { e.preventDefault(); d.classList.add('over'); }); });
        ['dragleave', 'drop'].forEach(function (t) { d.addEventListener(t, function () { d.classList.remove('over'); }); });
        d.addEventListener('drop', function (e) {
            e.preventDefault();
            if (e.dataTransfer && e.dataTransfer.files.length) { input.files = e.dataTransfer.files; paint(); }
        });
        form.addEventListener('submit', function () { btn.disabled = true; btn.lastChild.textContent = ' Uploading…'; });
    });

    // ---- coming back from a save: point at the form that changed
    var hash = (location.hash || '').replace('#form-', '');
    if (hash) { var r = document.getElementById('form-' + hash); if (r) { r.scrollIntoView({ block: 'center' }); r.classList.add('flash'); } }
    if (window.lucide) lucide.createIcons();
})();
</script>
</body>
</html>
