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

// Fetch latest status
$stmt = $pdo->prepare("SELECT verification_status, remarks FROM uploads WHERE user_id = ? AND item_id = 4 ORDER BY uploaded_at DESC LIMIT 1"); // Use effective_user_id
$stmt->execute([$effective_user_id]);
$plag_data = $stmt->fetch();
$plag_status = $plag_data['verification_status'] ?? 'No Verification Data';
$plag_remarks = $plag_data['remarks'] ?? '';

// Fetch Payment Status (Form 1 context)
$app_stmt = $pdo->prepare("SELECT payment_status FROM approvals WHERE user_id = ? AND form_id = 1");
$app_stmt->execute([$effective_user_id]);
$payment_status = $app_stmt->fetchColumn() ?: 'Unpaid';

// Handle messages from parent
$message = $_GET['msg'] ?? '';
$message_type = $_GET['type'] ?? '';

// Fetch recent uploads
// Fetch recent uploads for this item
$recent_stmt = $pdo->prepare("SELECT upload_id, original_filename, file_path, uploaded_at FROM uploads WHERE user_id = ? AND item_id = 4 ORDER BY uploaded_at DESC LIMIT 5"); // Use effective_user_id
$recent_stmt->execute([$effective_user_id]);
$recent_uploads = $recent_stmt->fetchAll();

// Determine redirect module slug for upload_handler
$redirect_module_slug = 'plag';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Plagiarism Verification Scan Hub</title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;600;800&display=swap');

        :root, body.theme-default, body.theme-blue { 
            --bg-beige: #f3f7fa; --mcnp-teal: #1e40af; --text-muted: #475569; --mcnp-hover: #172554;
            --border-line: #e2e8f0; --bg-white: #ffffff; --text-dark: #0f172a;
            --color-approved: #059669; --color-review: #d97706; --color-revision: #dc2626; --color-pending: #9ca3af;
            --ui-sans: 'Inter', system-ui, -apple-system, sans-serif;
            --card-shadow: 0 4px 14px rgba(0,0,0,0.06);
            --card-shadow-hover: 0 12px 30px rgba(0,0,0,0.08);
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
        h2 { font-family: var(--ui-sans); font-size: 24px; font-weight: 800; letter-spacing: -0.025em; color: var(--mcnp-teal); margin-bottom: 4px; }
        p { font-size: 14px; color: var(--text-muted); }
        
        .split-work-grid { display: grid; grid-template-columns: 1.1fr 0.9fr; gap: 25px; margin-top: 25px; }
        
        .upload-card-box, .live-status-card { 
            background-color: var(--bg-white); 
            border: 1.5px solid var(--border-line); 
            border-radius: 24px; 
            padding: 28px; 
            box-shadow: var(--card-shadow);
            transition: all 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);
        }
        .upload-card-box:hover, .live-status-card:hover {
            transform: scale(1.02) translateY(-4px);
            box-shadow: var(--card-shadow-hover);
            border-color: var(--mcnp-teal);
        }
        .upload-card-box h3 { font-family: var(--ui-sans); font-size: 18px; font-weight: 700; color: var(--mcnp-teal); margin-bottom: 10px; }
        .upload-card-box p { font-size: 13px; color: var(--text-muted); line-height: 1.5; }

        .alert { padding: 16px; border-radius: 12px; margin-bottom: 20px; font-size: 14px; font-weight: 600; border-left: 4px solid; line-height: 1.4; }
        .alert.success { background: #e6f4ea; color: #059669; border-left-color: #059669; }
        .alert.error { background: #fef2f2; color: #dc2626; border-left-color: #dc2626; }
        .alert.info { background: #eff6ff; color: #2563eb; border-left-color: #2563eb; }
        
        .custom-upload-label { 
            font-family: var(--ui-sans);
            display: inline-block; background: linear-gradient(to bottom right, var(--mcnp-teal), var(--mcnp-hover)); 
            color: #fff; padding: 10px 20px; font-weight: bold; border-radius: 10px; cursor: pointer; 
            margin-top: 15px; transition: all 0.2s ease;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        }
        .custom-upload-label:hover { transform: scale(1.02); box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1); }

        .live-status-card h4 { font-family: var(--ui-sans); font-size: 16px; font-weight: 700; color: var(--mcnp-teal); }
        .text-badge-status { font-family: var(--ui-sans); padding: 6px 12px; border-radius: 16px; color: #fff; font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; }
        .text-badge-status.approved { background-color: var(--color-approved); }
        .text-badge-status.pending { background-color: var(--color-pending); }
        .text-badge-status.review { background-color: var(--color-review); }
        .text-badge-status.revision { background-color: var(--color-revision); }
        
        .feedback-highlight-box { 
            background-color: #fffbeb; 
            border: 1px solid #fef3c7;
            border-left: 4px solid var(--color-review); 
            padding: 16px; 
            border-radius: 12px; 
            margin-top: 15px; 
            box-shadow: 0 4px 10px rgba(0,0,0,0.03);
        }
        .feedback-highlight-box h5 { font-family: var(--ui-sans); color: #856404; font-size: 13px; margin-bottom: 8px; display: flex; align-items: center; gap: 5px; }
        .feedback-highlight-box p { font-size: 13px; color: #2c2416; line-height: 1.5; font-style: italic; }

        /* Recent Uploads Styling */
        .recent-uploads-container { margin-top: 25px; border-top: 1px solid var(--border-line); padding-top: 20px; text-align: left; }
        .recent-uploads-container h4 { font-family: var(--ui-sans); color: var(--mcnp-teal); margin-bottom: 15px; font-size: 14px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.025em; }
        .upload-item { 
            background: var(--bg-beige); padding: 12px; border-radius: 10px; margin-bottom: 10px; 
            border: 1px solid var(--border-line); display: flex; justify-content: space-between; align-items: center;
            box-shadow: 0 2px 5px rgba(0,0,0,0.02);
        }
        .upload-item:last-child { margin-bottom: 0; }
        .upload-item p { font-family: var(--ui-sans); font-size: 12px; color: var(--mcnp-teal); font-weight: 600; margin: 0; }
        .upload-item span { font-size: 10px; color: var(--text-muted); margin: 0; }
        .upload-actions { display: flex; gap: 8px; }
        .upload-actions a, .upload-actions button {
            font-family: var(--ui-sans); font-size: 11px; padding: 6px 10px; border-radius: 8px; font-weight: 600;
            text-decoration: none; transition: all 0.2s ease;
        }
        .upload-actions a { background: var(--mcnp-teal); color: white; }
        .upload-actions a:hover { background: var(--mcnp-hover); transform: translateY(-1px); }
        .upload-actions button { background: #ef4444; color: white; border: none; cursor: pointer; }
        .upload-actions button:hover { background: #dc2626; transform: translateY(-1px); }
    </style>
</head>
<body>
    <div style="margin-bottom: 30px;">
        <h2 style="font-family: var(--ui-sans); font-weight: 800; letter-spacing: -0.025em;">Plagiarism Verification Scan Hub</h2>
        <p style="font-size:14px; color: var(--text-muted);">Upload your full chapter files for Turnitin parameter authentication clearance metrics.</p>
    </div>

    <?php if ($plag_status === 'Revision Requested'): ?>
        <div class="alert error">❌ <b>Revision Required:</b> Turnitin parameters require adjustment. Check feedback below.</div>
    <?php elseif ($plag_status === 'Under Review'): ?>
        <?php if ($payment_status === 'Paid'): ?>
            <div class="alert success">✔ <b>Payment Verified:</b> The Research Office has verified the clearance fee. Awaiting final Institutional Approval from the Research Director.</div>
        <?php else: ?>
            <div class="alert info">🕒 <b>Originality Confirmed:</b> The plagiarism scan has passed. <strong>Students are advised to proceed to the Cashier</strong> for payment, then visit the Research Office for face-to-face receipt verification.</div>
        <?php endif; ?>
    <?php elseif ($plag_status === 'Approved'): ?>
        <div class="alert success">
            🎉 <b>Institutional Approval Secured!</b> Plagiarism clearance is complete. 
            <a href="../stats.pdf" download class="custom-upload-label" style="text-decoration: none; background: linear-gradient(to bottom right, #059669, #047857); margin-left: 10px; margin-top:0;">📥 Download Form</a>
        </div>
    <?php endif; ?>

    <?php if ($message): ?><div class="alert <?= htmlspecialchars($message_type) ?>"><?= htmlspecialchars($message) ?></div><?php endif; ?>

    <div class="split-work-grid">
        <div class="upload-card-box">
            <h3>Upload Scannable Manuscript</h3>
            <p style="font-size: 12px; color: var(--text-muted);">Supported Formats: PDF or Microsoft Word Document (.docx)</p>
            
            <form action="upload_handler.php" method="POST" enctype="multipart/form-data" target="_parent">
                <input type="hidden" name="module_context" value="plagiarism">
                <input type="hidden" name="item_id" value="4"> <!-- Specific item ID for Plagiarism -->
                <label class="custom-upload-label">
                    Select Documents
                    <input type="file" name="research_file" style="display:none;" onchange="this.form.submit()" accept="image/*,.jpg,.jpeg,.png,.pdf,.doc,.docx,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document" required>
                </label>
            </form>

            <div class="recent-uploads-container">
                <h4>Recent Uploads</h4>
                <?php if (count($recent_uploads) > 0): ?>
                    <div style="display: flex; flex-direction: column; gap: 10px;">
                        <?php foreach ($recent_uploads as $upload): ?>
                        <div class="upload-item">
                            <div>
                                <p>📁 <?= htmlspecialchars($upload['original_filename'] ?? basename($upload['file_path'])) ?></p>
                                <span><?= date('M d, Y', strtotime($upload['uploaded_at'])) ?></span>
                            </div>
                            <div class="upload-actions">
                                <a href="<?= htmlspecialchars($upload['file_path']) ?>" download>Download</a>
                                <form method="POST" action="upload_handler.php" target="_parent" style="margin: 0;">
                                    <input type="hidden" name="action" value="delete_upload">
                                    <input type="hidden" name="module_context" value="plagiarism">
                                    <input type="hidden" name="upload_id" value="<?= $upload['upload_id'] ?>">
                                    <button type="submit" onclick="return confirm('Delete this upload?');">Delete</button>
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
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 15px;">
                <h4 style="margin:0;">Clearance Status Logs</h4>
                <span class="text-badge-status <?= (strpos(strtolower($plag_status), 'approved') !== false) ? 'approved' : (strpos(strtolower($plag_status), 'review') !== false ? 'review' : ((strpos(strtolower($plag_status), 'pending') !== false) ? 'pending' : 'revision')) ?>">
                    <?= htmlspecialchars($plag_status) ?>
                </span>
            </div>

            <?php if (!empty($plag_remarks)): ?>
                <div class="feedback-highlight-box">
                    <h5>
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path></svg>
                        Official Feedback
                    </h5>
                    <p>"<?= htmlspecialchars($plag_remarks) ?>"</p>
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