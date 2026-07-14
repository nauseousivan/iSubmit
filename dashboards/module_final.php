<?php
require_once '../config/db.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Student') {
    exit("Access Denied");
}

$user_id = $_SESSION['user_id'];
// Determine the effective user_id for data retrieval (leader's ID if current user is a member)
$stmt_leader_check = $pdo->prepare("SELECT leader_id FROM users WHERE user_id = ?");
$stmt_leader_check->execute([$user_id]);
$leader_id_for_current_user = $stmt_leader_check->fetchColumn();
$effective_user_id = $leader_id_for_current_user ?? $user_id; // Use leader_id if exists, otherwise current user_id

// Fetch all checklist items for the Final Defense (form_id = 2), grouping cascaded items under Final Manuscript (24)
$checklist_stmt = $pdo->prepare("SELECT * FROM checklist_items WHERE item_id IN (21,22,23,24,25,26,27) ORDER BY item_id ASC");
$checklist_stmt->execute();
$checklist_items = $checklist_stmt->fetchAll();

// Prepare an array to hold the latest status and remarks for each item, including Form 008 data
$item_statuses = [];
foreach ($checklist_items as $item) {
    $stmt = $pdo->prepare("SELECT verification_status, remarks, file_path, original_filename, uploaded_at, form_008_data, form_008_score, form_008_decision FROM uploads WHERE user_id = ? AND item_id = ? ORDER BY uploaded_at DESC LIMIT 1");
    $stmt->execute([$effective_user_id, $item['item_id']]);
    $latest_upload = $stmt->fetch();

    $item_statuses[$item['item_id']] = [
        'status' => $latest_upload['verification_status'] ?? 'No Upload',
        'remarks' => $latest_upload['remarks'] ?? '',
        'file_path' => $latest_upload['file_path'] ?? '',
        'original_filename' => $latest_upload['original_filename'] ?? '',
        'reviewer_name' => '',
        'uploaded_at' => $latest_upload['uploaded_at'] ?? null,
        'form_008_data' => $latest_upload['form_008_data'] ?? null,
        'form_008_score' => $latest_upload['form_008_score'] ?? null,
        'form_008_decision' => $latest_upload['form_008_decision'] ?? null
    ];
}

// Determine overall proposal status for the alert message
$overall_final_status = 'No Upload';
$has_revision_requested = false;
$has_under_review = false;
$all_approved = true;

foreach ($item_statuses as $status_data) {
    if ($status_data['status'] === 'Revision Requested') {
        $has_revision_requested = true;
        $all_approved = false;
    } elseif ($status_data['status'] === 'Under Review' || $status_data['status'] === 'Pending') {
        $has_under_review = true;
        $all_approved = false;
    } elseif ($status_data['status'] !== 'Approved') {
        $all_approved = false;
    }
}

if ($all_approved) {
    $overall_final_status = 'Approved';
} elseif ($has_revision_requested) {
    $overall_final_status = 'Revision Requested';
} elseif ($has_under_review) {
    $overall_final_status = 'Under Review';
}

// Handle messages
$message = $_GET['msg'] ?? '';
$message_type = $_GET['type'] ?? '';

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;600;800&display=swap');

        :root {
            --primary: #0c343d;
            --primary-light: #1a4f5c;
            --text-dark: #1f2937;
            --text-muted: #6b7280;
            --text-light: #9ca3af;
            --bg-white: #ffffff;
            --bg-light: #f8f9fa;
            --bg-pale: #f3f4f6;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --info: #3b82f6;
            --border-color: #e5e7eb;
            --ui-sans: 'Inter', system-ui, -apple-system, sans-serif;
        }

        * {
            box-sizing: border-box;
        }

        body {
            font-family: 'Cambria', serif;
            background: linear-gradient(135deg, var(--bg-light) 0%, #f0f4f8 100%);
            color: var(--text-dark);
            padding: 24px;
            margin: 0;
            min-height: 100vh;
            transition: all 0.3s ease;
        }

        body::-webkit-scrollbar {
            display: none;
        }

        .container {
            max-width: 1000px;
            margin: 0 auto;
        }

        .header {
            margin-bottom: 32px;
        }

        h2 {
            font-family: var(--ui-sans);
            font-size: 32px;
            color: var(--primary);
            margin: 0 0 8px 0;
            font-weight: 800;
            letter-spacing: -0.02em;
        }

        .subtitle {
            font-size: 15px;
            color: var(--text-muted);
            margin: 0;
            line-height: 1.6;
        }

        /* Status Alerts */
        .alert {
            padding: 16px 20px;
            border-radius: 12px;
            margin-bottom: 24px;
            font-family: var(--ui-sans);
            font-size: 14px;
            font-weight: 500;
            display: flex;
            align-items: flex-start;
            gap: 12px;
            animation: slideDown 0.3s ease-out;
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .alert.success {
            background: #ecfdf5;
            color: #065f46;
            border: 1px solid #a7f3d0;
        }

        .alert.error {
            background: #fef2f2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }

        .alert.info {
            background: #eff6ff;
            color: #0c4a6e;
            border: 1px solid #bae6fd;
        }

        .alert-icon {
            flex-shrink: 0;
            font-size: 20px;
            line-height: 1;
            margin-top: 2px;
        }

        .alert-content {
            flex: 1;
        }

        .alert-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: var(--success);
            color: white;
            padding: 10px 18px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 700;
            font-size: 13px;
            cursor: pointer;
            border: none;
            margin-top: 10px;
            transition: all 0.25s ease;
            font-family: var(--ui-sans);
        }

        .alert-btn:hover {
            background: #059669;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
        }

        /* Cards Grid */
        .items-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 24px;
        }

        .item-card {
            background: var(--bg-white);
            border-radius: 24px;
            border: 1.5px solid var(--border-color);
            padding: 0;
            overflow: hidden;
            transition: all 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            box-shadow: 0 4px 14px rgba(0, 0, 0, 0.06);
        }

        .item-card:hover {
            border-color: var(--primary);
            box-shadow: 0 12px 30px rgba(0, 0, 0, 0.08);
            transform: scale(1.02) translateY(-4px);
        }

        .item-card.approved {
            border-left: 4px solid var(--success);
        }

        .item-card.review {
            border-left: 4px solid var(--warning);
        }

        .item-card.revision {
            border-left: 4px solid var(--danger);
        }

        .item-card.pending {
            border-left: 4px solid var(--info);
        }

        /* Sub-Card Visual Grouping for Cascaded Items */
        .item-card.cascaded-item {
            border-left: 4px solid var(--info) !important;
            background: linear-gradient(145deg, #f8fafc 0%, #ffffff 100%);
            transform: scale(0.97);
        }

        .card-header {
            padding: 16px 20px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: flex-start;
            gap: 12px;
        }

        .status-badge {
            flex-shrink: 0;
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            font-weight: 700;
            color: white;
        }

        .status-badge.approved {
            background: linear-gradient(135deg, var(--success) 0%, #059669 100%);
        }

        .status-badge.review {
            background: linear-gradient(135deg, var(--warning) 0%, #d97706 100%);
            animation: pulse 2s infinite;
        }

        .status-badge.revision {
            background: linear-gradient(135deg, var(--danger) 0%, #991b1b 100%);
        }

        .status-badge.pending {
            background: linear-gradient(135deg, #94a3b8 0%, #64748b 100%);
        }

        @keyframes pulse {

            0%,
            100% {
                box-shadow: 0 0 0 0 rgba(245, 158, 11, 0.4);
            }

            50% {
                box-shadow: 0 0 0 8px rgba(245, 158, 11, 0);
            }
        }

        .card-title {
            font-family: var(--ui-sans);
            font-size: 14px;
            font-weight: 700;
            color: var(--text-dark);
            margin: 0 0 4px 0;
            text-transform: uppercase;
            letter-spacing: 0.02em;
        }

        .card-meta {
            font-size: 12px;
            color: var(--text-muted);
            margin: 0;
            font-family: var(--ui-sans);
        }

        .card-body {
            padding: 20px;
        }

        .card-description {
            font-size: 13px;
            color: var(--text-muted);
            line-height: 1.6;
            margin-bottom: 16px;
        }

        .status-label {
            display: inline-block;
            font-family: var(--ui-sans);
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            padding: 6px 12px;
            border-radius: 6px;
            margin-bottom: 12px;
        }

        .status-label.approved {
            background: #d1fae5;
            color: #065f46;
        }

        .status-label.review {
            background: #fef3c7;
            color: #78350f;
        }

        .status-label.revision {
            background: #fee2e2;
            color: #7c2d12;
        }

        .status-label.pending {
            background: #dbeafe;
            color: #0c4a6e;
        }

        .reviewer-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: #f3f4f6;
            padding: 8px 12px;
            border-radius: 6px;
            font-size: 12px;
            color: var(--text-dark);
            margin-bottom: 12px;
            font-weight: 600;
            font-family: var(--ui-sans);
        }

        .reviewer-badge svg {
            width: 14px;
            height: 14px;
            color: var(--success);
        }

        .action-buttons {
            display: flex;
            flex-direction: column;
            gap: 10px;
            margin-top: 16px;
        }

        .btn {
            padding: 12px 18px;
            border-radius: 8px;
            font-family: var(--ui-sans);
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            border: none;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-align: center;
            justify-content: center;
            transition: all 0.25s ease;
        }

        .btn-upload {
            background: var(--info);
            color: white;
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
        }

        .btn-upload:hover {
            background: #2563eb;
            box-shadow: 0 6px 16px rgba(59, 130, 246, 0.4);
            transform: translateY(-2px);
        }

        .btn-download {
            background: var(--bg-pale);
            color: var(--primary);
            border: 2px solid var(--border-color);
            font-weight: 700;
        }

        .btn-download:hover {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
            box-shadow: 0 4px 12px rgba(12, 52, 61, 0.2);
            transform: translateY(-2px);
        }

        .file-info {
            background: #eff6ff;
            border: 1px solid #bae6fd;
            border-radius: 8px;
            padding: 12px 14px;
            font-size: 13px;
            color: var(--primary);
            display: flex;
            align-items: center;
            gap: 10px;
            margin-top: 12px;
        }

        .file-info a {
            color: var(--primary);
            text-decoration: none;
            font-weight: 700;
            word-break: break-word;
            flex: 1;
        }

        .file-info a:hover {
            text-decoration: underline;
        }

        .feedback-box {
            background: #fffbeb;
            border: 1px solid #fef3c7;
            border-left: 4px solid var(--warning);
            padding: 14px;
            border-radius: 8px;
            margin-top: 12px;
            font-size: 12px;
        }

        .feedback-box p {
            color: #92400e;
            margin: 0;
            line-height: 1.5;
            font-style: italic;
        }

        .awaiting-text {
            color: var(--warning);
            font-weight: 700;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .loader {
            width: 12px;
            height: 12px;
            border: 2px solid rgba(245, 158, 11, 0.3);
            border-top-color: var(--warning);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }

        /* Mobile adaptions */
        @media (max-width: 640px) {
            body {
                padding: 16px;
            }

            .items-grid {
                grid-template-columns: 1fr;
            }

            h2 {
                font-size: 24px;
            }
        }

        /* Dynamic Theme classes mapping */
        body.theme-default,
        body.theme-blue {
            --bg-light: #f0f4f9;
            --bg-white: #ffffff;
            --text-dark: #1c2a44;
            --text-muted: #5f6f8a;
            --border-color: #c6d4e9;
            --primary: #4a7c8c;
            --primary-light: #3b6370;
        }

        body.theme-red {
            --bg-light: #ffe8e8;
            --bg-white: #ffffff;
            --text-dark: #4c1f20;
            --text-muted: #9d5b5c;
            --border-color: #f2c7c7;
            --primary: #d65a5a;
            --primary-light: #c04f4f;
        }

        body.theme-pink,
        body.theme-rose {
            --bg-light: #fde8f5;
            --bg-white: #ffffff;
            --text-dark: #4c2346;
            --text-muted: #9f628d;
            --border-color: #f3c7dc;
            --primary: #c56ba8;
            --primary-light: #ac5e94;
        }

        body.theme-green {
            --bg-light: #e8f6ea;
            --bg-white: #ffffff;
            --text-dark: #2f4a33;
            --text-muted: #6d8b75;
            --border-color: #c9dec9;
            --primary: #4a9e7b;
            --primary-light: #3a8565;
        }

        body.theme-purple,
        body.theme-lavender {
            --bg-light: #f5f3ff;
            --bg-white: #ffffff;
            --text-dark: #4c1d95;
            --text-muted: #9c9284;
            --border-color: #ddd6fe;
            --primary: #6d28d9;
            --primary-light: #4c1d95;
        }

        body.theme-orange,
        body.theme-amber {
            --bg-light: #fffbeb;
            --bg-white: #ffffff;
            --text-dark: #78350f;
            --text-muted: #9c9284;
            --border-color: #fde68a;
            --primary: #b45309;
            --primary-light: #78350f;
        }

        body.theme-dark {
            --bg-light: #1a1d21;
            --bg-white: #24282d;
            --text-dark: #e0e0e0;
            --text-muted: #b0ada8;
            --border-color: #3a3f45;
            --primary: #38bdf8;
            --primary-light: #0284c7;
        }

        /* ── Premium Icon & Animation Enhancements ── */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(16px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }

        .item-card {
            animation: fadeInUp 0.4s ease forwards;
            opacity: 0;
        }

        .item-card:nth-child(1) {
            animation-delay: 0.05s;
        }

        .item-card:nth-child(2) {
            animation-delay: 0.1s;
        }

        .item-card:nth-child(3) {
            animation-delay: 0.15s;
        }

        .item-card:nth-child(4) {
            animation-delay: 0.2s;
        }

        .item-card:nth-child(5) {
            animation-delay: 0.25s;
        }

        .item-card:nth-child(6) {
            animation-delay: 0.3s;
        }

        .item-card:nth-child(7) {
            animation-delay: 0.35s;
        }

        .item-card:nth-child(8) {
            animation-delay: 0.4s;
        }

        .status-badge i {
            display: flex;
        }

        .alert {
            animation: fadeInUp 0.35s ease forwards;
        }

        .alert-icon {
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .btn-upload,
        .btn-download {
            position: relative;
            overflow: hidden;
        }

        .btn-upload::after,
        .btn-download::after {
            content: "";
            position: absolute;
            top: 50%;
            left: 50%;
            width: 0;
            height: 0;
            background: rgba(255, 255, 255, 0.15);
            border-radius: 50%;
            transform: translate(-50%, -50%);
            transition: width 0.4s ease, height 0.4s ease;
        }

        .btn-upload:hover::after,
        .btn-download:hover::after {
            width: 300px;
            height: 300px;
        }

        .file-info {
            transition: all 0.25s ease;
        }

        .file-info:hover {
            transform: translateX(4px);
            border-color: #93c5fd;
            box-shadow: 0 2px 8px rgba(59, 130, 246, 0.1);
        }

        .feedback-box {
            transition: all 0.25s ease;
        }

        .feedback-box:hover {
            transform: translateX(4px);
            box-shadow: 0 2px 8px rgba(245, 158, 11, 0.1);
        }

        .item-card:hover .status-badge {
            transform: scale(1.05);
            transition: transform 0.2s ease;
        }
    </style>
    <script src="https://unpkg.com/lucide@latest"></script>
</head>

<body>
    <div class="container">
        <div class="header">
            <h2>Final Defense Workspace</h2>
            <p class="subtitle">Secure clearance and satisfy prerequisites for your official Final Defense Phase.</p>
        </div>

        <?php if ($overall_final_status === 'Revision Requested'): ?>
            <div class="alert error">
                <div class="alert-icon"><i data-lucide="x-circle" style="width:22px;height:22px;color:#ef4444;"></i></div>
                <div class="alert-content">
                    <strong>Revision Required</strong>
                    <p style="margin: 4px 0 0 0; font-size: 13px;">Revisions requested. Review evaluator feedback for each card below and upload corrected files.</p>
                </div>
            </div>
        <?php elseif ($overall_final_status === 'Under Review'): ?>
            <div class="alert info">
                <div class="alert-icon"><i data-lucide="loader" style="width:22px;height:22px;color:#3b82f6;animation:spin 2s linear infinite;"></i></div>
                <div class="alert-content">
                    <strong>Under Review</strong>
                    <p style="margin: 4px 0 0 0; font-size: 13px;">Your files are currently being audited by coordinators. You will be notified of decisions here.</p>
                </div>
            </div>
        <?php elseif ($overall_final_status === 'Approved'): ?>
            <div class="alert success">
                <div class="alert-icon"><i data-lucide="trophy" style="width:22px;height:22px;color:#10b981;"></i></div>
                <div class="alert-content">
                    <strong>Institutional Clearance Obtained!</strong>
                    <p style="margin: 4px 0 0 0; font-size: 13px;">Your Proposal Defense Stage is cleared.</p>
                    <a href="../proposal_cleared.pdf" download class="alert-btn"><i data-lucide="download" style="width:16px;height:16px;"></i> Download Signed Approval Form</a>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($message): ?>
            <div class="alert <?= htmlspecialchars($message_type) === 'success' ? 'success' : 'error' ?>">
                <div class="alert-icon"><?php if ($message_type === 'success'): ?><i data-lucide="check-circle-2" style="width:20px;height:20px;color:#10b981;"></i><?php else: ?><i data-lucide="alert-circle" style="width:20px;height:20px;color:#ef4444;"></i><?php endif; ?></div>
                <div class="alert-content">
                    <?= htmlspecialchars($message) ?>
                </div>
            </div>
        <?php endif; ?>

        <div class="items-grid">
            <?php foreach ($checklist_items as $item):
                $status_data = $item_statuses[$item['item_id']];
                $current_status = $status_data['status'];
                $current_remarks = $status_data['remarks'];
                $current_file_path = $status_data['file_path'];
                $current_original_filename = $status_data['original_filename'];
                $reviewer_name = $status_data['reviewer_name'];

                // Determine status class
                $status_class = 'pending';
                $status_text = 'Pending';
                $status_icon = '<i data-lucide="circle-dashed" style="width:18px;height:18px;"></i>';

                if ($current_status === 'Approved') {
                    $status_class = 'approved';
                    $status_text = 'Approved';
                    $status_icon = '<i data-lucide="check" style="width:18px;height:18px;"></i>';
                } elseif ($current_status === 'Under Review' || $current_status === 'Pending') {
                    $status_class = 'review';
                    $status_text = 'Under Review';
                    $status_icon = '<i data-lucide="clock" style="width:18px;height:18px;"></i>';
                } elseif ($current_status === 'Revision Requested') {
                    $status_class = 'revision';
                    $status_text = 'Revision Needed';
                    $status_icon = '<i data-lucide="alert-triangle" style="width:18px;height:18px;"></i>';
                } elseif ($current_status === 'No Upload') {
                    $status_class = 'pending';
                    $status_text = 'No Upload';
                    $status_icon = '<i data-lucide="circle-dashed" style="width:18px;height:18px;"></i>';
                }

                // Define a permissive accept attribute for all document types and image scans
                $accept_attr = 'image/*,.jpg,.jpeg,.png,.pdf,.doc,.docx,.xls,.xlsx,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
                $is_cascaded = in_array($item['item_id'], [13, 15, 16]);
            ?>
                <div class="item-card <?= $status_class ?> <?= $is_cascaded ? 'cascaded-item' : '' ?>" id="req-item-<?= $item['item_id'] ?>">
                    <div class="card-header">
                        <div class="status-badge <?= $status_class ?>"><?= $status_icon ?></div>
                        <div style="flex: 1;">
                            <h3 class="card-title"><?= htmlspecialchars($item['item_name']) ?></h3>
                            <p class="card-meta"><?= htmlspecialchars($item['description']) ?></p>
                        </div>
                    </div>

                    <div class="card-body">
                        <span class="status-label <?= $status_class ?>"><?= $status_text ?></span>

                        <?php if ($item['item_id'] == 21): ?>
                            <div class="feedback-box" style="background: #f0fdf4; border-left-color: var(--success); margin-bottom: 12px; padding: 10px;">
                                <p style="color: #166534; font-size: 11px;"><b>Workflow:</b> Download form &rarr; Hand to adviser for signature &rarr; Snap picture/Scan &rarr; Upload signed copy.</p>
                            </div>
                        <?php endif; ?>

                        <?php if ($reviewer_name && $current_status === 'Approved'): ?>
                            <div class="reviewer-badge">
                                <i data-lucide="badge-check" style="width:14px;height:14px;"></i>
                                Verified by <?= htmlspecialchars($reviewer_name) ?>
                            </div>
                        <?php endif; ?>

                        <?php if (in_array($item['item_id'], [21, 22])): ?>
                            <!-- Description simplified -->
                        <?php elseif (in_array($item['item_id'], [13, 15, 16])): ?>
                            <p class="card-description">State updates dynamically depending on evaluation outcomes of your primary Final Manuscript document.</p>
                        <?php else: ?>
                            <p class="card-description"><?= htmlspecialchars($item['description']) ?></p>
                        <?php endif; ?>

                        <?php if (in_array($item['item_id'], [13, 15, 16])): // Cascaded items 
                        ?>
                            <?php if ($current_status !== 'Approved'): ?>
                                <div class="awaiting-text">
                                    <div class="loader"></div>
                                    Awaiting Final Manuscript Evaluation
                                </div>
                            <?php else: ?>
                                <div class="awaiting-text" style="color: var(--success);">
                                    <i data-lucide="check-circle-2" style="width:16px;height:16px;"></i>
                                    Cleared via Final Manuscript
                                </div>
                            <?php endif; ?>
                        <?php elseif ($current_status === 'Approved'): ?>
                            <?php if ($current_file_path): ?>
                                <div class="file-info">
                                    <i data-lucide="file-text" style="width:16px;height:16px;"></i>
                                    <a href="<?= htmlspecialchars($current_file_path) ?>" download><?= htmlspecialchars($current_original_filename) ?></a>
                                </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="action-buttons">
                                <form action="upload_handler.php" method="POST" enctype="multipart/form-data" target="_parent" style="width: 100%;">
                                    <input type="hidden" name="module_context" value="final">
                                    <input type="hidden" name="item_id" value="<?= $item['item_id'] ?>">
                                    <label class="btn btn-upload">
                                        <i data-lucide="upload" style="width:16px;height:16px;"></i>
                                        Upload File
                                        <input type="file" name="research_file" style="display:none;" onchange="this.form.submit()" accept="<?= $accept_attr ?>" required>
                                    </label>
                                </form>
                            </div>

                            <?php if ($current_file_path): ?>
                                <div class="file-info" style="margin-top: 12px;">
                                    <i data-lucide="file-text" style="width:16px;height:16px;"></i>
                                    <div style="flex: 1;">
                                        <a href="<?= htmlspecialchars($current_file_path) ?>" download><?= htmlspecialchars($current_original_filename) ?></a>
                                        <div style="font-size: 11px; color: var(--text-muted); margin-top: 4px;">Latest submission</div>
                                    </div>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>

                        <?php if (!empty($current_remarks)): ?>
                            <div class="feedback-box">
                                <p>"<?= htmlspecialchars($current_remarks) ?>"</p>
                            </div>
                        <?php endif; ?>

                        <?php if ($item['item_id'] === 21): ?>
                            <a href="../endorsement.pdf" download class="btn btn-download" style="margin-top: 12px; justify-content: flex-start;">
                                <i data-lucide="download" style="width:15px;height:15px;"></i>
                                Download Blank Form
                            </a>
                        <?php elseif ($item['item_id'] === 13): ?>
                            <div style="display: flex; gap: 10px; margin-top: 12px; flex-wrap: wrap;">
                                <a href="../proposal_review.pdf" download class="btn btn-download" style="justify-content: flex-start;">
                                    <i data-lucide="download" style="width:15px;height:15px;"></i>
                                    Reference Form
                                </a>

                                <?php if (!empty($item_statuses[14]['form_008_data'])): ?>
                                    <button onclick='openStudentForm008(<?= json_encode($item_statuses[14]['form_008_data'], JSON_HEX_APOS | JSON_HEX_QUOT) ?>, <?= $item_statuses[14]['form_008_score'] ?>, <?= json_encode($item_statuses[14]['form_008_decision'], JSON_HEX_APOS | JSON_HEX_QUOT) ?>)' class="btn" style="background: #fef3c7; color: #92400e; border: 1px solid #f59e0b; justify-content: flex-start; font-family: var(--ui-sans);">
                                        View Form 008 Findings
                                    </button>
                                <?php endif; ?>
                            </div>
                        <?php elseif ($item['item_id'] === 14): ?>
                            <div style="display: flex; gap: 10px; margin-top: 12px; flex-wrap: wrap;">
                                <a href="../capsule_form.pdf" download class="btn btn-download" style="justify-content: flex-start;">
                                    <i data-lucide="download" style="width:15px;height:15px;"></i>
                                    Download Template
                                </a>

                                <?php if (!empty($status_data['form_008_data'])): ?>
                                    <button onclick='openStudentForm008(<?= json_encode($status_data['form_008_data'], JSON_HEX_APOS | JSON_HEX_QUOT) ?>, <?= $status_data['form_008_score'] ?>, <?= json_encode($status_data['form_008_decision'], JSON_HEX_APOS | JSON_HEX_QUOT) ?>)' class="btn" style="background: #fef3c7; color: #92400e; border: 1px solid #f59e0b; justify-content: flex-start; font-family: var(--ui-sans);">
                                        View Form 008 Findings
                                    </button>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div id="studentForm008Modal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.6); z-index:9999; justify-content:center; align-items:center; padding: 20px;">
        <div style="background:white; width:100%; max-width:800px; height:90%; border-radius:16px; display:flex; flex-direction:column; overflow:hidden; box-shadow:0 25px 50px -12px rgba(0,0,0,0.5);">

            <div style="background:var(--primary); color:white; padding:15px 25px; display:flex; justify-content:space-between; align-items:center;">
                <div>
                    <span style="font-size:10px; font-family:var(--ui-sans); text-transform:uppercase; letter-spacing:0.05em; opacity:0.8;">Feedback Panel</span>
                    <h3 style="margin:0; font-family:var(--ui-sans); font-size:18px;">ISAP Form No. 008 Evaluation Sheet</h3>
                </div>
                <button onclick="document.getElementById('studentForm008Modal').style.display='none'" style="background:none; border:none; color:white; font-size:28px; cursor:pointer;">&times;</button>
            </div>

            <div style="flex:1; overflow-y:auto; padding:25px; background:#f9f7f2; font-family:var(--ui-sans);">

                <div style="display:flex; justify-content:space-between; align-items:center; background:white; padding:15px 20px; border-radius:12px; border:1px solid #e5e7eb; margin-bottom:20px;">
                    <div>
                        <span style="font-size:12px; color:var(--text-muted); font-weight:bold; text-transform:uppercase;">Evaluated Score</span>
                        <h2 style="margin:0; color:var(--primary); font-size:24px;" id="sModalScore">0/22</h2>
                    </div>
                    <div style="text-align:right;">
                        <span style="font-size:12px; color:var(--text-muted); font-weight:bold; text-transform:uppercase;">Overall Decision</span>
                        <h3 style="margin:0; color:var(--warning);" id="sModalDecision">Pending</h3>
                    </div>
                </div>

                <div id="sModalContent" style="display:flex; flex-direction:column; gap:15px;"></div>
            </div>
        </div>
    </div>

    <script>
        const syncTheme = () => {
            const savedTheme = localStorage.getItem('rd-portal-theme') || 'theme-default';
            document.body.className = savedTheme;
        };
        syncTheme();
        window.addEventListener('storage', syncTheme);
        setInterval(() => {
            try {
                if (window.parent && window.parent.document && window.parent.document.body) {
                    const pTheme = window.parent.document.body.className;
                    if (pTheme && pTheme !== document.body.className) {
                        document.body.className = pTheme;
                    }
                }
            } catch (e) {}
        }, 500);

        const form008Questions = {
            "Clarity of Research Objectives": {
                "q1": "Are the research questions or objectives clearly articulated and well-defined?",
                "q2": "Is there a logical rationale for the study?"
            },
            "Literature Review": {
                "q3": "Does the literature review demonstrate a thorough understanding of existing research?",
                "q4": "Is the literature review up-to-date and relevant?"
            },
            "Theoretical Framework": {
                "q5": "Is there a well-developed theoretical framework guiding the research?",
                "q6": "Does the theoretical framework align with research questions?"
            },
            "Research Design and Methodology": {
                "q7": "Is the research design appropriate for addressing objectives?",
                "q8": "Are methods described in sufficient detail?",
                "q9": "Is sample size and sampling method justified?"
            },
            "Data Collection": {
                "q10": "Are data collection methods clearly described and appropriate?",
                "q11": "Is there a plan for ensuring credentials and validity?"
            },
            "Data Analysis": {
                "q12": "Is data analysis approach suitable?",
                "q13": "Are statistical methods appropriate?"
            },
            "Significance of the Study": {
                "q14": "Does proposal articulate potential contributions to the field?",
                "q15": "Is there discussion of practical implications?"
            },
            "Feasibility": {
                "q16": "Are required resources realistically addressed?",
                "q17": "Does researcher have access to necessary data/facilities?"
            },
            "Ethical Considerations": {
                "q18": "Are ethical considerations adequately addressed?",
                "q19": "Are there plans for consent and confidentiality?"
            },
            "Presentation and Communication": {
                "q20": "Is proposal organized and clearly written?",
                "q21": "Are ideas presented coherently?",
                "q22": "Is the language appropriate and accessible?"
            }
        };

        function openStudentForm008(jsonString, score, decision) {
            try {
                const data = typeof jsonString === 'string' ? JSON.parse(jsonString) : jsonString;

                document.getElementById('sModalScore').textContent = `${score} / 22 Points`;
                const decEl = document.getElementById('sModalDecision');
                decEl.textContent = decision ? decision.toUpperCase() : "EVALUATED";

                if (score >= 15) decEl.style.color = "#059669";
                else if (score >= 8) decEl.style.color = "#d97706";
                else decEl.style.color = "#dc2626";

                const container = document.getElementById('sModalContent');
                container.innerHTML = '';

                for (const [sectionTitle, questions] of Object.entries(form008Questions)) {
                    let sectionHTML = `
                        <div style="background:white; border:1px solid #e5e7eb; border-radius:8px; padding:15px;">
                            <h4 style="margin:0 0 12px 0; color:var(--primary); font-size:14px; border-bottom:1px solid #e5e7eb; padding-bottom:8px; font-family:var(--ui-sans);">${sectionTitle}</h4>
                            <div style="display:flex; flex-direction:column; gap:15px;">
                    `;

                    for (const [qKey, qText] of Object.entries(questions)) {
                        const answerData = data && data[qKey] ? data[qKey] : {
                            val: "N/A",
                            comment: ""
                        };

                        let badgeStyle = "background:#f3f4f6; color:#4b5563;";
                        if (answerData.val === "YES") badgeStyle = "background:#d1fae5; color:#065f46; border:1px solid #34d399;";
                        if (answerData.val === "NO") badgeStyle = "background:#fee2e2; color:#991b1b; border:1px solid #f87171;";

                        let commentHTML = "";
                        if (answerData.comment && answerData.comment.trim() !== "") {
                            commentHTML = `
                                <div style="margin-top:8px; background:#fffbeb; border-left:3px solid #f59e0b; padding:10px; border-radius:4px; font-size:12px; color:#92400e; font-style:italic;">
                                    <strong>Evaluator Note:</strong> "${answerData.comment}"
                                </div>
                            `;
                        }

                        sectionHTML += `
                            <div style="display:flex; flex-direction:column;">
                                <div style="display:flex; gap:12px; align-items:flex-start;">
                                    <span style="padding:4.5px 10px; border-radius:6px; font-size:11px; font-weight:bold; height:fit-content; ${badgeStyle}">${answerData.val}</span>
                                    <p style="margin:0; font-size:13px; color:#374151; line-height:1.4;">${qText}</p>
                                </div>
                                ${commentHTML}
                            </div>
                        `;
                    }
                    sectionHTML += `</div></div>`;
                    container.innerHTML += sectionHTML;
                }

                document.getElementById('studentForm008Modal').style.display = 'flex';
            } catch (e) {
                alert("Error loading Form 008 evaluation details.");
                console.error(e);
            }
        }
    </script>
    <script>
        lucide.createIcons();
    </script>
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