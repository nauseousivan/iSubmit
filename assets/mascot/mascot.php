<?php
/**
 * assets/mascot/mascot.php — the LIVE mascot: the iSubmit eagle (redesigned 2026-10-05).
 *
 * The MAIN eagle (no diploma, assets/mascot/eagle_svg.php) is drawn inline so her feathers move —
 * a slow flap at rest, the wing waved high when you tap or hover, like the old bird. Every other
 * pose is an <img> (assets/mascot/eagle/*.svg) that mascot.js swaps in when it is needed:
 *   covering-eyes  while a password field has focus      peeking  while "Show password" is on
 *   happy / oops   after a success / an error message    wink     the staff home, all caught up
 * (There is deliberately no "Hi!" bubble pose in use — the user chose the plain eagle as the face.)
 * One copy per page — mascot.js finds it by id (#quill-container keeps its old name so the pages'
 * sizing rules and window.Quill calls carry over untouched).
 *
 * Optional before the include: $mascot_idle (resting pose, default 'plain'),
 * $mascot_base (URL of the eagle folder, default '../assets/mascot/eagle/').
 */
require_once __DIR__ . '/eagle_svg.php';
$mascot_idle = preg_replace('/[^a-z-]/', '', $mascot_idle ?? 'plain') ?: 'plain';
$mascot_base = $mascot_base ?? '../assets/mascot/eagle/';
?>
<div id="quill-container" class="eagle-live" data-idle="<?= $mascot_idle ?>" data-base="<?= htmlspecialchars($mascot_base) ?>" data-pose="<?= $mascot_idle ?>">
    <?= eagle_plain_svg('eq', 'class="eagle-main"') ?>
    <img class="eagle-img" src="<?= htmlspecialchars($mascot_base . ($mascot_idle === 'plain' ? 'covering-eyes' : $mascot_idle)) ?>.svg" alt="" aria-hidden="true" draggable="false">
</div>
<?php unset($mascot_idle, $mascot_base); ?>
