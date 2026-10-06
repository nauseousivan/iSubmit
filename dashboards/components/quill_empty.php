<?php
/**
 * dashboards/components/quill_empty.php — the eagle in an empty state (redesigned 2026-10-05).
 *
 * The mascot of record is now the iSubmit eagle, one SVG per pose in assets/mascot/eagle/
 * (the old inline "Quill" artwork and its stray-shape PNG are retired). An <img>, so it carries
 * no ids or gradient defs into the page — safe to repeat, and safe to clone (admin_defense_schedule
 * builds JS empty states by cloning #nxBird .quill-still).
 *
 * The pose says what KIND of empty it is (the office's mascot guide):
 *   sleeping   nothing is waiting on you              (the default)
 *   thinking   nothing here yet / nothing matches a search
 *   wink       you're all caught up / good news
 *   happy      done — everyone handled
 * Still, no animation: an empty state is furniture, not a show.
 *
 * Optional: $quill_size (px, default 96), $quill_pose (default 'sleeping').
 */
$q_size = isset($quill_size) ? (int)$quill_size : 96;
$q_pose = preg_replace('/[^a-z-]/', '', $quill_pose ?? 'sleeping') ?: 'sleeping';
// Consumed, not inherited: this file is included more than once per page and a value left set by
// the first caller would silently apply to every one after it.
unset($quill_size, $quill_pose);
?>
<img class="quill-still" src="../assets/mascot/eagle/<?= $q_pose ?>.svg" width="<?= $q_size ?>" height="<?= $q_size ?>"
     alt="" aria-hidden="true" draggable="false" data-pose="<?= $q_pose ?>" style="object-fit: contain;">
