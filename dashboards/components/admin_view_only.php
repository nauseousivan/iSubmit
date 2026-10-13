<?php
// components/admin_view_only.php — the Admin's "View only" marker (2026-09-30).
//
// Included right after <body> on the workflow screens the Admin may open. Renders nothing for
// anyone else. The RULE is the POST guard in config/db.php; this only tells her so, and dims the
// buttons of POST forms so they do not look live. Buttons that act through fetch() stay as they
// are and are refused by the same guard with the same sentence.
if (($_SESSION['role'] ?? '') !== 'Admin') return;
?>
<style>
    .avo-pill { position: fixed; right: 16px; bottom: 16px; z-index: 9999; display: inline-flex; align-items: center; gap: 7px;
                padding: 9px 14px; border-radius: 999px; font: 700 12.5px/1 var(--font-ui, system-ui, sans-serif);
                background: var(--ink, #2e1065); color: var(--surface, #fff); box-shadow: 0 8px 24px rgba(0,0,0,.18); pointer-events: none; }
    .avo-pill svg, .avo-pill i { width: 14px; height: 14px; }
    form[method="post" i] button:not([type="button"]), form[method="post" i] input[type="submit"] { opacity: .45; cursor: not-allowed; }
</style>
<div class="avo-pill" title="The Admin account can view the research workflow but not act in it.">
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>
    View only
</div>
