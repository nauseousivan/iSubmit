<?php
require_once '../config/db.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Student') { exit("Access Denied"); }

$user_id = $_SESSION['user_id'];
// Determine the effective user_id for data retrieval (leader's ID if current user is a member)
$stmt_leader_check = $pdo->prepare("SELECT leader_id FROM users WHERE user_id = ?");
$stmt_leader_check->execute([$user_id]);
$leader_id_for_current_user = $stmt_leader_check->fetchColumn();
$effective_user_id = $leader_id_for_current_user ?? $user_id; // Use leader_id if exists, otherwise current user_id

// Checklist item (item_id = 4)
$item_stmt = $pdo->prepare("SELECT * FROM checklist_items WHERE item_id = 4");
$item_stmt->execute();
$checklist_item = $item_stmt->fetch() ?: ['item_id' => 4, 'item_name' => 'Research Manuscript (Turnitin Scan)', 'description' => 'Full chapter manuscript submitted for Turnitin originality/plagiarism scan clearance.', 'required_file_type' => 'pdf'];

// Latest submission — single-stage decision now: Pending -> Approved/Revision Requested directly,
// no more Under Review forwarding step for this module.
$stmt = $pdo->prepare("SELECT upload_id, verification_status, remarks, file_path, original_filename, uploaded_at FROM uploads WHERE user_id = ? AND item_id = 4 ORDER BY uploaded_at DESC LIMIT 1");
$stmt->execute([$effective_user_id]);
$upload_data = $stmt->fetch();
$has_upload = (bool) $upload_data;
$plag_status = $upload_data['verification_status'] ?? '';

// Submission history for the timeline panel (read-only) — same pattern as Statistics/Proposal.
$hist_stmt = $pdo->prepare("SELECT upload_id, verification_status, remarks, file_path, original_filename, uploaded_at FROM uploads WHERE user_id = ? AND item_id = 4 AND verification_status IN ('Under Review', 'Approved', 'Revision Requested') ORDER BY uploaded_at DESC");
$hist_stmt->execute([$effective_user_id]);
$upload_history = $hist_stmt->fetchAll();

// Control number (satellite table, persists across re-uploads)
$pc_stmt = $pdo->prepare("SELECT formatted_control_no FROM plagiarism_checks WHERE user_id = ?");
$pc_stmt->execute([$effective_user_id]);
$plag_control_no = $pc_stmt->fetchColumn() ?: '';

// Turnitin report (item_id = 40) — staff-uploaded, versioned in the same `uploads` table as
// everything else, so the report is downloadable regardless of the manuscript's current status
// (e.g. still visible while Revision Requested, to explain what needs fixing).
$report_stmt = $pdo->prepare("SELECT upload_id, file_path, original_filename, verification_status, remarks, uploaded_at FROM uploads WHERE user_id = ? AND item_id = 40 ORDER BY uploaded_at DESC");
$report_stmt->execute([$effective_user_id]);
$report_history = $report_stmt->fetchAll();
$latest_report = $report_history[0] ?? null;

// Handle messages from parent
$message = $_GET['msg'] ?? '';
$message_type = $_GET['type'] ?? '';

// Wallet-card status mapping
$card_status = 'no-upload';
$status_text = 'No Upload';
if ($has_upload) {
    if ($plag_status === 'Approved') { $card_status = 'approved'; $status_text = 'Approved'; }
    elseif ($plag_status === 'Revision Requested') { $card_status = 'revision'; $status_text = 'Revision Needed'; }
    else { $card_status = 'pending'; $status_text = 'In Review'; }
}

// 3-step tracker (sister-card visual to Statistics' Data Upload/Finance Payment/Requirements/
// Release tracker, reworded for plagiarism and with the Finance step cut — no payment here).
$current_step = 1;
if ($card_status === 'pending') $current_step = 2;
elseif ($card_status === 'approved') $current_step = 3;
$step_completed_3 = ($card_status === 'approved');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Plagiarism Verification Scan Hub</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;800&family=JetBrains+Mono:wght@400;500;700&display=swap" rel="stylesheet">
    <link href="../assets/css/dashboard-cards.css" rel="stylesheet">
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        :root, body.theme-default, body.theme-blue {
            /* Neutral slate accent (Apple-style), matching Statistics — no more blue headings */
            --bg-beige: #ffffff; --mcnp-teal: #0f172a; --text-muted: #475569; --mcnp-hover: #1e293b;
            --border-line: #e2e8f0; --bg-white: #ffffff; --text-dark: #0f172a;
            --ui-sans: 'Inter', system-ui, -apple-system, sans-serif;
        }
        body.theme-red { --bg-beige: #fef2f2; --mcnp-teal: #b91c1c; --text-muted: #7f1d1d; --mcnp-hover: #7f1d1d; --border-line: #fee2e2; --bg-white: #ffffff; --text-dark: #450a0a; }
        body.theme-pink, body.theme-rose { --bg-beige: #fde8f5; --mcnp-teal: #c56ba8; --text-muted: #9f628d; --mcnp-hover: #ac5e94; --border-line: #f3c7dc; --bg-white: #ffffff; --text-dark: #4c2346; }
        body.theme-green { --bg-beige: #e8f6ea; --mcnp-teal: #4a9e7b; --text-muted: #6d8b75; --mcnp-hover: #3a8565; --border-line: #c9dec9; --bg-white: #ffffff; --text-dark: #2f4a33; }
        body.theme-purple, body.theme-lavender { --bg-beige: #f5f3ff; --mcnp-teal: #6d28d9; --text-muted: #9c9284; --mcnp-hover: #4c1d95; --border-line: #ddd6fe; --bg-white: #ffffff; --text-dark: #4c1d95; }
        body.theme-orange, body.theme-amber { --bg-beige: #fffbeb; --mcnp-teal: #b45309; --text-muted: #9c9284; --mcnp-hover: #78350f; --border-line: #fde68a; --bg-white: #ffffff; --text-dark: #78350f; }
        body.theme-dark { --bg-beige: #1a1d21; --mcnp-teal: #38bdf8; --text-muted: #b0ada8; --mcnp-hover: #0284c7; --border-line: #3a3f45; --bg-white: #24282d; --text-dark: #e0e0e0; }

        body { font-family: var(--ui-sans); background-color: var(--bg-beige); color: var(--text-dark); padding: 15px; margin: 0; }
        body::-webkit-scrollbar { display: none; }
        body { -ms-overflow-style: none; scrollbar-width: none; }
        p { font-size: 14px; color: var(--text-muted); }

        .alert { padding: 16px; border-radius: 12px; margin-bottom: 20px; font-size: 14px; font-weight: 600; border-left: 4px solid; line-height: 1.4; }
        .alert.success { background: #e6f4ea; color: #059669; border-left-color: #059669; }
        .alert.error { background: #fef2f2; color: #dc2626; border-left-color: #dc2626; }
        .alert.info { background: #eff6ff; color: #2563eb; border-left-color: #2563eb; }

        .control-no-chip {
            display: inline-flex; align-items: center; gap: 6px;
            background: var(--bg-white); border: 1.5px solid var(--border-line);
            border-radius: 20px; padding: 6px 14px; font-size: 12px; font-weight: 700;
            font-family: 'JetBrains Mono', monospace; color: var(--mcnp-teal); margin-bottom: 14px;
        }

        .report-ready-card {
            background: linear-gradient(135deg, #ecfdf5, #d1fae5);
            border: 1.5px solid rgba(5, 150, 105, 0.2);
            border-radius: 16px; padding: 18px; margin-top: 16px;
        }
        .report-ready-card h5 { font-size: 13px; font-weight: 800; color: #065f46; margin-bottom: 6px; }
        .report-ready-card p { font-size: 12.5px; color: #047857; margin-bottom: 10px; }

        /* Upload modal (page-local, mirrors module_statistics.php's own copy — not part of the
           shared dashboard-cards.css, which only supplies the wallet card / history / download
           modal pieces). */
        @keyframes scaleIn { from { opacity: 0; transform: scale(0.92); } to { opacity: 1; transform: scale(1); } }
        @keyframes float { 0%, 100% { transform: translateY(0); } 50% { transform: translateY(-6px); } }

        .modal-backdrop {
            position: fixed; inset: 0; background: rgba(10, 30, 36, 0.5);
            backdrop-filter: blur(10px); -webkit-backdrop-filter: blur(10px);
            display: none; justify-content: center; align-items: center;
            z-index: 100000; padding: 20px; opacity: 0; transition: opacity 0.3s ease;
        }
        .modal-backdrop.active { display: flex; opacity: 1; }
        .modal-box {
            background: var(--bg-white); width: 100%; max-width: 480px; border-radius: 20px;
            box-shadow: 0 25px 60px rgba(0, 0, 0, 0.2);
            animation: scaleIn 0.35s cubic-bezier(0.175, 0.885, 0.32, 1.275); overflow: hidden;
        }
        .modal-head { display: flex; align-items: center; justify-content: space-between; padding: 22px 24px; border-bottom: 1.5px solid var(--border-line); }
        .modal-head-left { display: flex; align-items: center; gap: 12px; }
        .modal-head-left .modal-icon { width: 40px; height: 40px; background: var(--mcnp-teal); color: white; border-radius: 12px; display: flex; align-items: center; justify-content: center; }
        .modal-head-left h3 { font-size: 17px; font-weight: 700; color: var(--mcnp-teal); }
        .modal-head-left p { font-size: 11.5px; color: var(--text-muted); margin-top: 1px; }
        .modal-close { background: none; border: none; color: var(--text-muted); cursor: pointer; padding: 6px; border-radius: 8px; transition: all 0.2s; display: flex; }
        .modal-close:hover { background: #fee2e2; color: #e11d48; }
        .modal-body { padding: 24px; }
        .drop-zone { border: 2px dashed #d1d5db; border-radius: 14px; padding: 36px 20px; text-align: center; cursor: pointer; transition: all 0.25s ease; background: rgba(148,163,184,0.06); position: relative; }
        .drop-zone:hover, .drop-zone.dragover { border-color: #0ea5e9; background: rgba(14,165,233,0.08); transform: scale(1.01); }
        .drop-zone input[type="file"] { position: absolute; inset: 0; opacity: 0; cursor: pointer; }
        .drop-zone-icon { width: 52px; height: 52px; border-radius: 14px; background: rgba(14,165,233,0.1); color: #0ea5e9; display: flex; align-items: center; justify-content: center; margin: 0 auto 14px; animation: float 3s ease-in-out infinite; }
        .drop-zone h4 { font-size: 15px; font-weight: 700; color: var(--mcnp-teal); margin-bottom: 4px; }
        .drop-zone p { font-size: 12.5px; color: var(--text-muted); }
        .drop-zone p span { font-weight: 700; color: #0ea5e9; }
        .file-preview { display: none; align-items: center; gap: 12px; padding: 14px 16px; background: rgba(5,150,105,0.1); border: 1px solid rgba(5, 150, 105, 0.15); border-radius: 10px; margin-top: 16px; }
        .file-preview.visible { display: flex; }
        .file-preview-icon { width: 36px; height: 36px; background: #059669; color: white; border-radius: 10px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
        .file-preview-name { font-size: 13px; font-weight: 600; color: #065f46; flex: 1; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .btn-submit-modal {
            width: 100%; padding: 14px; margin-top: 20px;
            background: linear-gradient(135deg, var(--mcnp-teal), var(--mcnp-hover)); color: white;
            border: none; border-radius: 10px; font-family: var(--ui-sans); font-size: 14px; font-weight: 700;
            cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 8px;
            transition: all 0.3s ease; box-shadow: 0 4px 16px rgba(12, 52, 61, 0.2);
        }
        .btn-submit-modal:hover { transform: translateY(-1px); box-shadow: 0 6px 24px rgba(12, 52, 61, 0.3); }

        /* Step tracker — sister visual to Statistics' Data Upload/Finance Payment/Requirements/
           Release tracker, reworded for plagiarism's simpler 3-step flow (no payment step). */
        .hero-header { display:flex; align-items:center; justify-content:flex-end; margin-bottom:14px; }
        .step-tracker { display:flex; align-items:flex-start; justify-content:space-between; margin-bottom:22px; position:relative; padding:0 10px; }
        .step-tracker::before { content:''; position:absolute; top:20px; left:40px; right:40px; height:3px; background:#e5e7eb; border-radius:2px; z-index:0; }
        .step-tracker .progress-line { position:absolute; top:20px; left:40px; height:3px; background:linear-gradient(90deg, #059669, var(--mcnp-teal)); border-radius:2px; z-index:1; transition:width 0.8s cubic-bezier(0.4,0,0.2,1); }
        .step-item { display:flex; flex-direction:column; align-items:center; gap:10px; position:relative; z-index:2; flex:1; }
        .step-circle { width:40px; height:40px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:14px; font-weight:800; border:3px solid #e5e7eb; background:var(--bg-white); color:#9ca3af; transition:all 0.4s ease; position:relative; }
        .step-item.completed .step-circle { background:#059669; border-color:#059669; color:white; box-shadow:0 3px 12px rgba(5,150,105,0.3); }
        .step-item.active .step-circle { background:var(--mcnp-teal); border-color:var(--mcnp-teal); color:white; box-shadow:0 3px 12px rgba(12,52,61,0.3); animation:pulseGlow 2s infinite; }
        @keyframes pulseGlow { 0%, 100% { box-shadow:0 0 0 0 rgba(5,150,105,0.4); } 50% { box-shadow:0 0 0 8px rgba(5,150,105,0); } }
        .step-label { font-size:11px; font-weight:700; color:#9ca3af; text-align:center; text-transform:uppercase; letter-spacing:0.4px; max-width:100px; line-height:1.3; }
        .step-item.completed .step-label, .step-item.active .step-label { color:var(--mcnp-teal); }
    </style>
</head>
<body>
    <?php if ($plag_control_no): ?>
        <div class="hero-header">
            <div class="control-no-chip" style="margin-bottom:0;">🔖 <?= htmlspecialchars($plag_control_no) ?></div>
        </div>
    <?php endif; ?>

    <?php if ($message): ?><div class="alert <?= htmlspecialchars($message_type) ?>"><?= htmlspecialchars($message) ?></div><?php endif; ?>

    <?php
    $steps = [
        ['num' => 1, 'label' => 'Upload Manuscript', 'icon' => 'upload-cloud'],
        ['num' => 2, 'label' => 'In Review', 'icon' => 'search'],
        ['num' => 3, 'label' => 'Clearance Report', 'icon' => 'award'],
    ];
    $step_progress = 0;
    if ($current_step >= 2) $step_progress = 50;
    if ($current_step >= 3) $step_progress = 100;
    ?>
    <div class="step-tracker">
        <div class="progress-line" style="width: calc(<?= $step_progress ?>% * 0.85);"></div>
        <?php foreach ($steps as $s):
            $state = '';
            if ($s['num'] < $current_step || ($s['num'] == 3 && $step_completed_3)) $state = 'completed';
            elseif ($s['num'] == $current_step) $state = 'active';
        ?>
            <div class="step-item <?= $state ?>">
                <div class="step-circle">
                    <?php if ($state === 'completed'): ?>
                        <i data-lucide="check" style="width:18px; height:18px;"></i>
                    <?php else: ?>
                        <i data-lucide="<?= $s['icon'] ?>" style="width:16px; height:16px;"></i>
                    <?php endif; ?>
                </div>
                <span class="step-label"><?= $s['label'] ?></span>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="mobile-stack-hint">
        <i data-lucide="hand-pointer" style="width:14px;height:14px;"></i> Tap the card to manage
    </div>

    <div class="items-grid">
        <div class="item-card <?= $card_status ?>" id="req-item-<?= $checklist_item['item_id'] ?>" onclick="expandWalletCard(this, event)" style="z-index: 1;">
            <div class="card-inner-bg">
                <div class="card-header">
                    <div class="status-icon-box num-indicator">01</div>
                    <div>
                        <h3 class="card-title"><?= htmlspecialchars($checklist_item['item_name']) ?></h3>
                        <p class="card-meta"><?= htmlspecialchars($checklist_item['description']) ?></p>
                    </div>
                </div>

                <div class="card-body" onclick="event.stopPropagation()">

                    <div style="display:flex; align-items:center; justify-content:space-between; gap:8px; margin-bottom:16px;">
                        <div class="status-pill <?= $card_status ?>" style="margin-bottom:0;"><?= $status_text ?></div>
                        <?php if (!empty($upload_history)): ?>
                            <button onclick="openHistoryPanel(4)" class="btn-history">
                                <i data-lucide="clock" style="width:13px;height:13px;"></i> History
                            </button>
                        <?php endif; ?>
                    </div>

                    <?php if ($plag_status === 'Revision Requested' && !empty($upload_data['remarks'])): ?>
                        <div class="instruction-box" style="background:#fef2f2; color:#b91c1c; border:1px solid #fecaca; margin-bottom:16px;">
                            <strong>Revision Required:</strong> "<?= htmlspecialchars($upload_data['remarks']) ?>"
                        </div>
                    <?php elseif ($card_status === 'pending'): ?>
                        <div class="instruction-box" style="margin-bottom:16px;">
                            <i data-lucide="clock" style="width:15px;height:15px;display:inline-block;vertical-align:middle;"></i>
                            Your manuscript has been received and is in review. We'll notify you once a decision is made.
                        </div>
                    <?php endif; ?>

                    <?php if ($has_upload):
                        $sub_fname = $upload_data['original_filename'] ?: 'Uploaded file';
                        $sub_fpath = $upload_data['file_path'];
                        $sub_fdate = !empty($upload_data['uploaded_at']) ? date('M j, Y', strtotime($upload_data['uploaded_at'])) : '';
                        $sub_ftime = !empty($upload_data['uploaded_at']) ? date('g:i A', strtotime($upload_data['uploaded_at'])) : '';
                        $sub_ext = strtolower(pathinfo($sub_fname, PATHINFO_EXTENSION));
                        $sub_is_img = in_array($sub_ext, ['jpg', 'jpeg', 'png', 'gif', 'webp']);
                        $display_fname = strlen($sub_fname) > 28 ? substr($sub_fname, 0, 25) . '...' : $sub_fname;
                    ?>
                        <div class="apple-file-card">
                            <div class="afc-thumb-wrap">
                                <?php if ($sub_is_img): ?>
                                    <img src="<?= htmlspecialchars($sub_fpath) ?>" alt="Preview" class="afc-thumb">
                                <?php else: ?>
                                    <i data-lucide="file-text" style="width:20px;height:20px;color:#64748b;"></i>
                                <?php endif; ?>
                            </div>
                            <div class="afc-info">
                                <div class="afc-name" title="<?= htmlspecialchars($sub_fname) ?>"><?= htmlspecialchars($display_fname) ?></div>
                                <div class="afc-meta"><?= $sub_fdate ?> &middot; <?= $sub_ftime ?></div>
                            </div>
                            <div class="afc-actions">
                                <button type="button" class="afc-btn" onclick="openDownloadModal('<?= htmlspecialchars($sub_fpath) ?>', '<?= htmlspecialchars($sub_fname) ?>'); event.stopPropagation();">View</button>
                                <?php if ($card_status === 'pending'): // un-reviewed draft can be removed ?>
                                    <button type="button" class="afc-btn afc-delete" onclick="deleteUpload(<?= $upload_data['upload_id'] ?? 0 ?>, 'plagiarism'); event.stopPropagation();">
                                        <i data-lucide="trash-2" style="width:14px;height:14px;"></i>
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if ($card_status !== 'approved'): ?>
                        <button type="button" class="btn btn-primary" style="margin-top:14px;" onclick="openUploadModal()">
                            <i data-lucide="<?= $has_upload ? 'refresh-cw' : 'upload' ?>" style="width:18px;height:18px;"></i>
                            <?= $has_upload ? 'Re-upload' : 'Upload Manuscript' ?>
                        </button>
                    <?php endif; ?>

                    <?php if ($latest_report):
                        $report_is_approved = ($latest_report['verification_status'] === 'Approved');
                    ?>
                        <div class="report-ready-card" style="<?= $report_is_approved ? '' : 'background: linear-gradient(135deg, #fffbeb, #fef3c7); border-color: rgba(217,119,6,0.25);' ?>">
                            <h5 style="<?= $report_is_approved ? '' : 'color:#92400e;' ?>"><?= $report_is_approved ? '🎉 Institutional Approval Secured!' : '📋 Turnitin Report Attached' ?></h5>
                            <p style="<?= $report_is_approved ? '' : 'color:#92400e;' ?>"><?= $report_is_approved ? 'Your official plagiarism clearance report is ready.' : 'The Research Office attached a Turnitin similarity report with your revision request.' ?></p>
                            <?php if (!empty($latest_report['remarks'])): ?>
                                <p style="font-style:italic; <?= $report_is_approved ? '' : 'color:#92400e;' ?>">Note from the Research Office: "<?= htmlspecialchars($latest_report['remarks']) ?>"</p>
                            <?php endif; ?>
                            <div style="display:flex; gap:8px; flex-wrap:wrap;">
                                <button type="button" class="btn btn-primary" onclick="openDownloadModal('<?= htmlspecialchars($latest_report['file_path']) ?>', 'Turnitin_Similarity_Report'); event.stopPropagation();">
                                    <i data-lucide="download" style="width:16px;height:16px;"></i> Download Report
                                </button>
                                <?php if (count($report_history) > 1): ?>
                                    <button type="button" class="btn-history" onclick="openHistoryPanel(40); event.stopPropagation();">
                                        <i data-lucide="clock" style="width:13px;height:13px;"></i> Report History
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                </div>
            </div>
        </div>
    </div>

    <!-- Download Preview Modal (shared behaviour with Module Proposal / Statistics) -->
    <div id="download-modal" class="dl-modal-overlay" onclick="if(event.target===this)closeDlModal(false)">
        <div class="dl-modal-box">
            <div class="dl-modal-header">
                <i data-lucide="file" style="width:18px;height:18px;color:#64748b; flex-shrink: 0;"></i>
                <span id="dm-name" style="word-break: break-word; overflow-wrap: anywhere;">Document</span>
            </div>
            <p style="font-size:13px;color:#64748b;margin:12px 0 20px 0;line-height:1.5;">Open a preview or save a copy to your device.</p>
            <div style="display:flex;flex-direction:column;gap:10px;">
                <button id="dm-preview-btn" class="btn btn-primary"><i data-lucide="eye" style="width:18px;height:18px;"></i> Open Preview</button>
                <button id="dm-download-btn" class="btn btn-outline"><i data-lucide="download" style="width:18px;height:18px;"></i> Download</button>
                <button onclick="closeDlModal()" class="btn" style="background:transparent;color:#94a3b8;border:none;font-size:14px;padding:12px;">Cancel</button>
            </div>
        </div>
    </div>

    <!-- Upload Modal -->
    <div class="modal-backdrop" id="uploadModal">
        <div class="modal-box">
            <div class="modal-head">
                <div class="modal-head-left">
                    <div class="modal-icon">
                        <i data-lucide="upload-cloud" style="width:20px; height:20px;"></i>
                    </div>
                    <div>
                        <h3>Upload Manuscript</h3>
                        <p>Required format: <strong>PDF or DOCX</strong></p>
                    </div>
                </div>
                <button class="modal-close" onclick="closeUploadModal()">
                    <i data-lucide="x" style="width:20px; height:20px;"></i>
                </button>
            </div>

            <div class="modal-body">
                <form action="upload_handler.php" method="POST" enctype="multipart/form-data" id="uploadForm" target="_parent">
                    <input type="hidden" name="module_context" value="plagiarism">
                    <input type="hidden" name="item_id" value="4">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">

                    <div class="drop-zone" id="dropArea">
                        <div class="drop-zone-icon">
                            <i data-lucide="cloud-upload" style="width:24px; height:24px;"></i>
                        </div>
                        <h4>Drop your file here</h4>
                        <p>or <span>click to browse</span> from your device</p>
                        <input type="file" name="research_file" id="fileInput" accept=".pdf,.doc,.docx" required>
                    </div>

                    <div class="file-preview" id="filePreview">
                        <div class="file-preview-icon">
                            <i data-lucide="file-check" style="width:18px; height:18px;"></i>
                        </div>
                        <span class="file-preview-name" id="fileName"></span>
                        <i data-lucide="check-circle-2" style="width:18px; height:18px; color: #059669; flex-shrink: 0;"></i>
                    </div>

                    <button type="submit" class="btn-submit-modal">
                        <i data-lucide="send" style="width:16px; height:16px;"></i>
                        Submit Document
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- History Panel (single item) -->
    <?php if (!empty($upload_history)): ?>
    <div id="history-panel-4" class="history-panel">
        <div class="history-panel-header">
            <div>
                <div style="font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:0.07em;color:#94a3b8;margin-bottom:2px;">Submission History</div>
                <h3><?= htmlspecialchars($checklist_item['item_name']) ?></h3>
            </div>
            <button onclick="closeHistoryPanel()" class="history-close-btn">
                <i data-lucide="x" style="width:18px;height:18px;"></i>
            </button>
        </div>
        <div class="history-panel-body">
            <?php
            $hist_total = count($upload_history);
            foreach ($upload_history as $hi => $hu):
                $hu_st = $hu['verification_status'];
                if ($hu_st === 'Approved') $hu_sc = 'approved';
                elseif ($hu_st === 'Revision Requested') $hu_sc = 'revision';
                else $hu_sc = 'review';
                $hu_label = 'Version ' . ($hist_total - $hi) . ($hi === 0 ? ' (Latest Reviewed)' : '');
                $hu_date = $hu['uploaded_at'] ? date('M j, Y \a\t g:i A', strtotime($hu['uploaded_at'])) : '';
            ?>
            <div class="history-item">
                <div>
                    <div class="history-item-label"><?= $hu_label ?></div>
                    <div class="history-status-pill <?= $hu_sc ?>"><?= htmlspecialchars($hu_st) ?></div>
                </div>
                <div class="history-filename"><?= htmlspecialchars($hu['original_filename'] ?? 'Unknown file') ?></div>
                <?php if ($hu_date): ?><div class="history-date"><?= $hu_date ?></div><?php endif; ?>
                <?php if (!empty($hu['remarks'])): ?>
                <div class="history-remarks"><?= htmlspecialchars($hu['remarks']) ?></div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Report History Panel (item 40 — Turnitin similarity reports) -->
    <?php if (count($report_history) > 1): ?>
    <div id="history-panel-40" class="history-panel">
        <div class="history-panel-header">
            <div>
                <div style="font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:0.07em;color:#94a3b8;margin-bottom:2px;">Report History</div>
                <h3>Turnitin Similarity Report</h3>
            </div>
            <button onclick="closeHistoryPanel()" class="history-close-btn">
                <i data-lucide="x" style="width:18px;height:18px;"></i>
            </button>
        </div>
        <div class="history-panel-body">
            <?php
            $rep_total = count($report_history);
            foreach ($report_history as $ri => $rr):
                $rr_st = $rr['verification_status'];
                $rr_sc = ($rr_st === 'Approved') ? 'approved' : (($rr_st === 'Revision Requested') ? 'revision' : 'review');
                $rr_label = 'Version ' . ($rep_total - $ri) . ($ri === 0 ? ' (Latest)' : '');
                $rr_date = $rr['uploaded_at'] ? date('M j, Y \a\t g:i A', strtotime($rr['uploaded_at'])) : '';
            ?>
            <div class="history-item">
                <div>
                    <div class="history-item-label"><?= $rr_label ?></div>
                    <div class="history-status-pill <?= $rr_sc ?>"><?= htmlspecialchars($rr_st) ?></div>
                </div>
                <div class="history-filename"><?= htmlspecialchars($rr['original_filename'] ?? 'Unknown file') ?></div>
                <?php if ($rr_date): ?><div class="history-date"><?= $rr_date ?></div><?php endif; ?>
                <?php if (!empty($rr['remarks'])): ?>
                <div class="history-remarks"><?= htmlspecialchars($rr['remarks']) ?></div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
    <div id="history-backdrop" class="history-backdrop" onclick="closeHistoryPanel()"></div>

    <script>window.CSRF_TOKEN = '<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>';</script>
    <script>
        (function() {
            const savedTheme = localStorage.getItem('rd-portal-theme');
            if (savedTheme) {
                document.body.className = savedTheme;
            }
        })();

        lucide.createIcons();

        const modal = document.getElementById('uploadModal');
        const fileInput = document.getElementById('fileInput');
        const filePreview = document.getElementById('filePreview');
        const dropArea = document.getElementById('dropArea');

        function openUploadModal() {
            fileInput.value = '';
            filePreview.classList.remove('visible');
            dropArea.style.display = 'block';
            modal.classList.add('active');
            setTimeout(() => lucide.createIcons(), 50);
        }

        function closeUploadModal() {
            modal.classList.remove('active');
        }

        modal.addEventListener('click', function(e) {
            if (e.target === modal) closeUploadModal();
        });

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') closeUploadModal();
        });

        fileInput.addEventListener('change', function() {
            if (this.files && this.files[0]) {
                document.getElementById('fileName').textContent = this.files[0].name;
                dropArea.style.display = 'none';
                filePreview.classList.add('visible');
                lucide.createIcons();
            }
        });

        ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(evt => {
            dropArea.addEventListener(evt, e => { e.preventDefault(); e.stopPropagation(); }, false);
        });
        ['dragenter', 'dragover'].forEach(evt => {
            dropArea.addEventListener(evt, () => dropArea.classList.add('dragover'), false);
        });
        ['dragleave', 'drop'].forEach(evt => {
            dropArea.addEventListener(evt, () => dropArea.classList.remove('dragover'), false);
        });
        dropArea.addEventListener('drop', function(e) {
            const files = e.dataTransfer.files;
            fileInput.files = files;
            if (files && files[0]) {
                document.getElementById('fileName').textContent = files[0].name;
                dropArea.style.display = 'none';
                filePreview.classList.add('visible');
                lucide.createIcons();
            }
        });
    </script>
    <script src="../assets/js/dashboard-cards.js"></script>
    <!-- Deep-link: scroll to + highlight a specific requirement when opened from a notification -->
    <style>
        @keyframes deeplinkPulse {
            0%   { box-shadow: 0 0 0 0 rgba(124, 58, 237, 0.55); }
            70%  { box-shadow: 0 0 0 14px rgba(124, 58, 237, 0); }
            100% { box-shadow: 0 0 0 0 rgba(124, 58, 237, 0); }
        }
        .item-card.deeplink-flash {
            animation: deeplinkPulse 1.3s ease-out 2;
            outline: 2px solid rgba(124, 58, 237, 0.9);
            outline-offset: 3px;
        }
    </style>
    <script>
        (function () {
            var itemId = new URLSearchParams(window.location.search).get('item');
            if (!itemId) return;
            function jump() {
                var allTab = document.querySelector('.status-filter-tab[data-filter="all"]');
                if (allTab && !allTab.classList.contains('active')) allTab.click();
                var el = document.getElementById('req-item-' + itemId);
                if (!el) return;
                el.scrollIntoView({ behavior: 'smooth', block: 'center', inline: 'center' });
                el.classList.add('deeplink-flash');
                setTimeout(function () { el.classList.remove('deeplink-flash'); }, 2800);
            }
            if (document.readyState === 'complete') setTimeout(jump, 450);
            else window.addEventListener('load', function () { setTimeout(jump, 450); });
        })();
    </script>
</body>
</html>
