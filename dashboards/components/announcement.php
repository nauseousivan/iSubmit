<?php
// components/announcement.php — the Admin's announcement banner (2026-10-01).
//
// Set in dashboards/admin_settings.php. Included once near the top of every portal (the staff
// home, student.php, focal.php) and the login page. Self-contained on purpose — inline styles,
// no theme tokens — because it sits on BOTH design systems (portal.css and theme.css).
// Floating, so it never shifts a page's layout. Closing it is remembered per browser against the
// announcement's id, which changes whenever the text does, so a new message shows again.
if (!function_exists('rd_setting') || rd_setting('announcement_active') !== '1') return;
$rd_ann_text = trim((string)rd_setting('announcement_text', ''));
if ($rd_ann_text === '') return;
$rd_ann_id  = (string)rd_setting('announcement_id', '1');
$rd_ann_lvl = (string)rd_setting('announcement_level', 'info');
[$rd_ann_bg, $rd_ann_fg, $rd_ann_ic] = [
    'warning' => ['#fffbeb', '#92400e', '#d97706'],
    'success' => ['#ecfdf5', '#065f46', '#059669'],
][$rd_ann_lvl] ?? ['#f5f3ff', '#3b0764', '#7c3aed'];
?>
<div id="rdAnnounce" data-id="<?= htmlspecialchars($rd_ann_id) ?>" role="status" hidden
     style="position:fixed;top:12px;left:50%;transform:translateX(-50%);z-index:9500;width:min(680px,calc(100vw - 24px));box-sizing:border-box;
            display:flex;gap:12px;align-items:flex-start;padding:13px 14px 13px 16px;border-radius:16px;background:<?= $rd_ann_bg ?>;color:<?= $rd_ann_fg ?>;
            box-shadow:0 14px 34px -14px rgba(15,23,42,.35),0 0 0 1px rgba(15,23,42,.06);font:600 13.5px/1.5 Inter,system-ui,sans-serif;">
    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="<?= $rd_ann_ic ?>" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;margin-top:1px">
        <path d="m3 11 18-5v12L3 14v-3z"/><path d="M11.6 16.8a3 3 0 1 1-5.8-1.6"/></svg>
    <div style="flex:1;min-width:0;white-space:pre-line;word-break:break-word;"><?= htmlspecialchars($rd_ann_text) ?></div>
    <button type="button" aria-label="Close announcement"
            onclick="try{localStorage.setItem('rd-announce-closed',this.parentNode.dataset.id)}catch(e){}this.parentNode.hidden=true"
            style="all:unset;cursor:pointer;flex-shrink:0;width:26px;height:26px;border-radius:50%;display:grid;place-items:center;color:<?= $rd_ann_fg ?>;opacity:.7;">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
    </button>
</div>
<script>
(function () {
    var el = document.getElementById('rdAnnounce'), closed = null;
    try { closed = localStorage.getItem('rd-announce-closed'); } catch (e) {}
    if (el && closed !== el.dataset.id) el.hidden = false;
})();
</script>
