<?php
require_once '../config/db.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Student') { exit("Access Denied"); }

$user_id = $_SESSION['user_id'];

// Fetch plagiarism status and remarks
$stmt = $pdo->prepare("SELECT verification_status, remarks FROM uploads WHERE user_id = ? AND item_id = 4 ORDER BY uploaded_at DESC LIMIT 1");
$stmt->execute([$user_id]);
$plag_data = $stmt->fetch();
$plag_status = $plag_data['verification_status'] ?? 'No Verification Data';
$plag_remarks = $plag_data['remarks'] ?? '';

// Fetch Payment Status from approvals (linked to Form 1 for this phase)
$app_stmt = $pdo->prepare("SELECT payment_status FROM approvals WHERE user_id = ? AND form_id = 1");
$app_stmt->execute([$user_id]);
$payment_status = $app_stmt->fetchColumn() ?: 'Unpaid';

// Fetch recent uploads (Plagiarism is item_id 4)
$recent_stmt = $pdo->prepare("SELECT upload_id, file_path, uploaded_at FROM uploads WHERE user_id = ? AND item_id = 4 ORDER BY uploaded_at DESC LIMIT 5");
$recent_stmt->execute([$user_id]);
$recent_uploads = $recent_stmt->fetchAll();

// Handle messages from parent (student.php)
$message = $_GET['msg'] ?? '';
$message_type = $_GET['type'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <style>
        :root { 
            --bg-beige: #f7f4eb; --mcnp-teal: #0c343d; --text-muted: #7d7569;
            --border-line: #ded9cf; --bg-white: #ffffff;
            --color-approved: #137333; --color-revision: #c5221f; --color-pending: #5f6368;
        }
        body { font-family: 'Cambria', serif; background-color: var(--bg-white); color: #2b261f; padding: 10px; margin: 0; }
        h2 { font-size: 24px; color: var(--mcnp-teal); margin-bottom: 4px; }
        .split-work-grid { display: grid; grid-template-columns: 1.1fr 0.9fr; gap: 30px; margin-top: 20px; }
        .upload-card-box { background-color: var(--bg-beige); border: 2px dashed var(--border-line); border-radius: 12px; padding: 35px; text-align: center; }
        .alert { padding: 12px; border-radius: 8px; margin-bottom: 20px; font-size: 14px; font-weight: 600; border-left: 4px solid; }
        .alert.success { background: #e6f4ea; color: #137333; border-left-color: #27ae60; }
        .alert.error { background: #f8d7da; color: #721c24; border-left-color: #e74c3c; }
        .alert.info { background: #d1ecf1; color: #0c5460; border-left-color: #17a2b8; }
        .custom-upload-label { display: inline-block; background-color: var(--mcnp-teal); color: #fff; padding: 12px 24px; font-weight: bold; border-radius: 8px; cursor: pointer; margin-top: 15px; }
        .live-status-card { background-color: var(--bg-white); border: 1px solid var(--border-line); border-radius: 12px; padding: 24px; }
        .text-badge-status { padding: 6px 14px; border-radius: 20px; color: #fff; font-size: 11px; font-weight: bold; text-transform: uppercase; }
        .text-badge-status.approved { background-color: var(--color-approved); }
        .text-badge-status.pending { background-color: var(--color-pending); }
        .text-badge-status.revision { background-color: var(--color-revision); }
        .remark-comment-bubble { background-color: #fce8e6; border-left: 4px solid var(--color-revision); padding: 15px; border-radius: 6px; margin-top: 15px; font-size: 14px; color: #a82315; }
    </style>
</head>
<body>
    <div style="margin-bottom: 25px;">
        <h2>Plagiarism Verification Scan Hub</h2>
        <p style="font-size:14px; color: var(--text-muted);">Upload your full chapter files for Turnitin parameter authentication clearance metrics.</p>
    </div>

    <?php if ($plag_status === 'Under Review'): ?>
        <?php if ($payment_status === 'Paid'): ?>
            <div class="alert success">✔ Payment Verified! Your plagiarism clearance fee has been processed. Awaiting Research Director sign-off.</div>
        <?php else: ?>
            <div class="alert info">✔ Plagiarism scan cleared! <strong>You may now proceed to the Cashier</strong> to pay for the clearance certificate. Once paid, the Research Director will provide the final sign-off.</div>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ($message): ?><div class="alert <?= htmlspecialchars($message_type) ?>"><?= htmlspecialchars($message) ?></div><?php endif; ?>

    <div class="split-work-grid">
        <div class="upload-card-box">
            <h3>Upload Scannable Manuscript</h3>
            <p style="font-size: 12px; color: var(--text-muted);">Supported Formats: PDF or Microsoft Word Document (.docx)</p>
            
            <form action="upload_handler.php" method="POST" enctype="multipart/form-data" target="_parent">
                <input type="hidden" name="module_context" value="plagiarism">
                <label class="custom-upload-label">
                    Select Documents
                    <input type="file" name="research_file" style="display:none;" onchange="this.form.submit()" required>
                </label>
            </form>

            <div style="margin-top: 20px; border-top: 1px solid var(--border-line); padding-top: 20px; text-align: left;">
                <h4 style="color: var(--mcnp-teal); margin-bottom: 15px; font-size: 14px;">Recent Uploads</h4>
                <?php if (count($recent_uploads) > 0): ?>
                    <div style="display: flex; flex-direction: column; gap: 10px;">
                        <?php foreach ($recent_uploads as $upload): ?>
                        <div style="background: var(--bg-white); padding: 12px; border-radius: 8px; display: flex; justify-content: space-between; align-items: center; border: 1px solid var(--border-line);">
                            <div>
                                <p style="font-size: 12px; color: var(--mcnp-teal); font-weight: bold; margin: 0;">📁 <?= htmlspecialchars(basename($upload['file_path'])) ?></p>
                                <p style="font-size: 11px; color: var(--text-muted); margin: 0;"><?= date('M d, Y', strtotime($upload['uploaded_at'])) ?></p>
                            </div>
                            <div style="display: flex; gap: 6px;">
                                <a href="<?= htmlspecialchars($upload['file_path']) ?>" download style="background: var(--mcnp-teal); color: white; padding: 6px 10px; border-radius: 6px; font-size: 11px; text-decoration: none;">Download</a>
                                <form method="POST" action="upload_handler.php" target="_parent" style="margin: 0;">
                                    <input type="hidden" name="action" value="delete_upload">
                                    <input type="hidden" name="module_context" value="plagiarism">
                                    <input type="hidden" name="upload_id" value="<?= $upload['upload_id'] ?>">
                                    <button type="submit" onclick="return confirm('Delete this upload?');" style="background: #c5221f; color: white; padding: 6px 10px; border: none; border-radius: 6px; font-size: 11px; cursor: pointer;">Delete</button>
                                </form>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p style="font-size:12px; color: var(--text-muted); text-align:center;">No uploads yet.</p>
                <?php endif; ?>
            </div>
        </div>

        <div class="live-status-card">
            <div style="display:flex; justify-content:space-between; align-items:center;">
                <h4 style="margin:0;">Clearance Status Logs</h4>
                <span class="text-badge-status <?= (strpos(strtolower($plag_status), 'approved') !== false) ? 'approved' : ((strpos(strtolower($plag_status), 'pending') !== false || strpos(strtolower($plag_status), 'review') !== false) ? 'pending' : 'revision') ?>">
                    <?= htmlspecialchars($plag_status) ?>
                </span>
            </div>

            <?php if (!empty($plag_remarks)): ?>
                <div class="remark-comment-bubble">
                    <b>💬 Secretariat Remarks:</b>
                    <p style="margin-top: 4px; font-style: italic;">"<?= htmlspecialchars($plag_remarks) ?>"</p>
                </div>
            <?php else: ?>
                <p style="font-size:13px; color: var(--text-muted); margin-top:20px; text-align:center;">No similarity checks logged yet.</p>
            <?php endif; ?>
        </div>
    </div>
    <script>
        document.body.className = localStorage.getItem('rd-portal-theme') || 'theme-default';
    </script>
</body>
</html>