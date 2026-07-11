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

// Fetch all checklist items for the Proposal Defense (form_id = 1), grouping cascaded items under Capsule Proposal (14)
$checklist_stmt = $pdo->prepare("SELECT * FROM checklist_items WHERE form_id = 1 ORDER BY CASE WHEN item_id = 12 THEN 10.5 WHEN item_id = 13 THEN 14.1 WHEN item_id = 15 THEN 14.2 WHEN item_id = 16 THEN 14.3 ELSE item_id END ASC");
$checklist_stmt->execute();
$checklist_items = $checklist_stmt->fetchAll();

// Prepare an array to hold the latest status and remarks for each item, including Form 008 data
$item_statuses = [];
foreach ($checklist_items as $item) {
    $stmt = $pdo->prepare("SELECT upload_id, verification_status, remarks, file_path, original_filename, uploaded_at, form_008_data, form_008_score, form_008_decision FROM uploads WHERE user_id = ? AND item_id = ? ORDER BY uploaded_at DESC LIMIT 1");
    $stmt->execute([$effective_user_id, $item['item_id']]);
    $latest_upload = $stmt->fetch();

    // Upload history for the timeline panel (read-only). Only officially processed versions belong here:
    // un-reviewed 'Pending' drafts are shown on the current submission card and can still be deleted, so they
    // are intentionally excluded from history until a reviewer acts on them.
    $hist_stmt = $pdo->prepare("SELECT upload_id, verification_status, remarks, file_path, original_filename, uploaded_at FROM uploads WHERE user_id = ? AND item_id = ? AND verification_status IN ('Under Review', 'Approved', 'Revision Requested') ORDER BY uploaded_at DESC");
    $hist_stmt->execute([$effective_user_id, $item['item_id']]);
    $upload_history = $hist_stmt->fetchAll();

    $item_statuses[$item['item_id']] = [
        'upload_id' => $latest_upload['upload_id'] ?? 0,
        'status' => $latest_upload['verification_status'] ?? 'No Upload',
        'remarks' => $latest_upload['remarks'] ?? '',
        'file_path' => $latest_upload['file_path'] ?? '',
        'original_filename' => $latest_upload['original_filename'] ?? '',
        'reviewer_name' => '',
        'uploaded_at' => $latest_upload['uploaded_at'] ?? null,
        'form_008_data' => $latest_upload['form_008_data'] ?? null,
        'form_008_score' => $latest_upload['form_008_score'] ?? null,
        'form_008_decision' => $latest_upload['form_008_decision'] ?? null,
        'history' => $upload_history
    ];
}

// Determine overall proposal status for the alert message
$overall_prop_status = 'No Upload';
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
    $overall_prop_status = 'Approved';
} elseif ($has_revision_requested) {
    $overall_prop_status = 'Revision Requested';
} elseif ($has_under_review) {
    $overall_prop_status = 'Under Review';
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
    <link rel="stylesheet" href="../assets/css/dashboard-cards.css">
    <script src="https://unpkg.com/lucide@latest"></script>
    <script src="../assets/js/global-scripts.js"></script>

</head>

<body>


    <?php
    $total_items = count($checklist_items);
    $approved_items = 0;
    $needs_you_items = 0;
    foreach ($checklist_items as $ci) {
        $st = $item_statuses[$ci['item_id']]['status'];
        $is_cascaded = in_array($ci['item_id'], [13, 15, 16]);
        if ($st === 'Approved') {
            $approved_items++;
        } elseif (!$is_cascaded && in_array($st, ['No Upload', 'Revision Requested'])) {
            // Cascaded items (13/15/16) auto-derive from item 14 — the student can't act on them
            // directly, so they don't count as "needs you" even while still unapproved.
            $needs_you_items++;
        }
    }
    ?>

    <?php
    // "What's Next" — derive a single next action from existing statuses
    $nsb_class = '';
    $nsb_icon = 'upload';

    // Base styles mimicking `.widget-card`
    $nsb_bg = '#ffffff';
    $nsb_border = '#f3f4f6';
    $nsb_shadow = '0 8px 16px -4px rgba(0, 0, 0, 0.05)';
    $nsb_icon_bg = '#f8fafc';
    $nsb_icon_color = '#334155';
    $nsb_text_color = '#475569';
    $nsb_title_color = '#1e293b';

    if ($overall_prop_status === 'Approved') {
        $nsb_class = 'approved';
        $nsb_icon = 'party-popper';
        $nsb_action = 'All requirements cleared — download your clearance form!';
    } else {
        $rev_item = $noupload_item = $review_item = null;
        foreach ($checklist_items as $ci) {
            $st = $item_statuses[$ci['item_id']]['status'];
            if ($st === 'Revision Requested' && !$rev_item) $rev_item = $ci['item_name'];
            if ($st === 'No Upload' && !in_array($ci['item_id'], [13, 15, 16]) && !$noupload_item) $noupload_item = $ci['item_name'];
            if (($st === 'Pending' || $st === 'Under Review') && !$review_item) $review_item = $ci['item_name'];
        }
        if ($rev_item) {
            $nsb_class = 'revision';
            $nsb_icon = 'alert-triangle';
            $nsb_icon_bg = '#fef2f2';
            $nsb_icon_color = '#dc2626';
            $nsb_text_color = '#991b1b';
            $nsb_title_color = '#dc2626';
            $nsb_action = '<span style="font-size:11px; opacity:0.8; line-height:1; display:block; margin-bottom:4px; text-transform:uppercase; letter-spacing:0.5px;">Revision needed on:</span><strong style="font-size:13px; line-height:1.3; display:block;">' . htmlspecialchars($rev_item) . '</strong>';
        } elseif ($noupload_item) {
            $nsb_class = '';
            $nsb_icon = 'upload';
            $nsb_icon_bg = '#f0fdf4';
            $nsb_icon_color = '#10b981';
            $nsb_title_color = '#1e293b';
            $nsb_action = '<span style="font-size:11px; opacity:0.8; line-height:1; display:block; margin-bottom:4px; text-transform:uppercase; letter-spacing:0.5px;">Upload your:</span><strong style="font-size:13px; line-height:1.3; display:block;">' . htmlspecialchars($noupload_item) . '</strong>';
        } elseif ($review_item) {
            $nsb_class = 'review';
            $nsb_icon = 'clock';
            $nsb_icon_bg = '#fffbeb';
            $nsb_icon_color = '#d97706';
            $nsb_title_color = '#d97706';
            $nsb_text_color = '#92400e';
            $nsb_action = '<span style="font-size:11px; opacity:0.8; line-height:1; display:block; margin-bottom:4px; text-transform:uppercase; letter-spacing:0.5px;">Under review:</span><strong style="font-size:13px; line-height:1.3; display:block;">' . htmlspecialchars($review_item) . '</strong>';
        } else {
            $nsb_class = 'review';
            $nsb_icon = 'clock';
            $nsb_icon_bg = '#fffbeb';
            $nsb_icon_color = '#d97706';
            $nsb_title_color = '#d97706';
            $nsb_text_color = '#92400e';
            $nsb_action = '<span style="font-size:13px; line-height:1.4; display:block;">Keep going — review each requirement below.</span>';
        }
    }
    ?>

    <div class="proposal-hero-stack">
        <?php if ($overall_prop_status === 'Approved'): ?>
            <div class="next-step-banner-compact" style="display:flex; flex-direction:column; gap:6px; background:linear-gradient(135deg, #ecfdf5 0%, #d1fae5 100%); border: 1px solid #10b981; border-radius:16px; padding:12px 16px; box-shadow: 0 8px 16px -4px rgba(0, 0, 0, 0.05); box-sizing: border-box;">
                <div style="display:flex; align-items:center; gap:8px; width:100%;">
                    <i data-lucide="check-circle-2" style="width:18px;height:18px;color:#059669; flex-shrink: 0;"></i>
                    <strong style="color:#064e3b; font-size:13px; font-family:'Inter', sans-serif; line-height:1.2; text-transform: uppercase;">INSTITUTIONAL CLEARANCE OBTAINED!</strong>
                </div>
                <p style="margin:0; font-size:11px; color:#065f46; font-family:'Inter', sans-serif; line-height: 1.25;">Your Capsule Proposal Stage is fully evaluated and cleared. You may now download your form.</p>
                <button onclick="openDownloadModal('../proposal_cleared.pdf', 'Proposal Clearing Form')" class="btn" style="width: 100%; justify-content: center; background:linear-gradient(135deg, #10b981 0%, #059669 100%); color:white; border:none; padding: 8px 12px; font-size: 12px; font-weight:600; font-family:'Inter', sans-serif; box-shadow: 0 4px 12px rgba(16,185,129,0.2);"><i data-lucide="download" style="width:14px;height:14px;"></i> Download Form</button>
            </div>
        <?php else: ?>
            <div class="next-step-banner-compact <?= $nsb_class ?>" style="display:flex; align-items:center; gap:10px; background:<?= $nsb_bg ?>; border: 1px solid <?= $nsb_border ?>; border-radius:16px; padding:10px 16px; box-shadow: <?= $nsb_shadow ?>; box-sizing: border-box;">
                <div style="width:30px; height:30px; border-radius:9px; background:<?= $nsb_icon_bg ?>; display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                    <i data-lucide="<?= $nsb_icon ?>" style="width:15px;height:15px;color:<?= $nsb_icon_color ?>;"></i>
                </div>
                <div style="min-width:0;">
                    <strong style="color:<?= $nsb_title_color ?>; font-size:10.5px; font-weight:700; letter-spacing:0.5px; font-family:'Inter', sans-serif; line-height:1.2; text-transform: uppercase; display:block;">NEXT STEP</strong>
                    <p style="margin:2px 0 0; font-size:12.5px; color:<?= $nsb_text_color ?>; font-family:'Inter', sans-serif; line-height:1.35;"><?= $nsb_action ?></p>
                </div>
            </div>
        <?php endif; ?>

        <div class="status-filter-tabs">
            <button type="button" class="status-filter-tab active" data-filter="all">All <span class="tab-count"><?= $total_items ?></span></button>
            <button type="button" class="status-filter-tab" data-filter="needs-you">Needs you <span class="tab-count"><?= $needs_you_items ?></span></button>
            <button type="button" class="status-filter-tab" data-filter="cleared">Cleared <span class="tab-count"><?= $approved_items ?></span></button>
        </div>
    </div>

    <div class="mobile-stack-hint">
        <i data-lucide="hand-pointer" style="width:14px;height:14px;"></i> Tap any card to manage
    </div>

    <div class="items-grid">
        <?php
        $card_index = 0;
        foreach ($checklist_items as $item):
            $card_index++;
            $status_data = $item_statuses[$item['item_id']];
            $current_status = $status_data['status'];

            $status_class = 'pending';
            $pill_text = 'Pending';
            $icon = 'circle-dashed';

            if ($current_status === 'Approved') {
                $status_class = 'approved';
                $pill_text = 'Approved';
                $icon = 'check';
            } elseif ($current_status === 'Under Review' || $current_status === 'Pending') {
                $status_class = 'review';
                $pill_text = 'Under Review';
                $icon = 'clock';
            } elseif ($current_status === 'Revision Requested') {
                $status_class = 'revision';
                $pill_text = 'Revision Needed';
                $icon = 'alert-triangle';
            } elseif ($current_status === 'No Upload') {
                $status_class = 'no-upload';
                $pill_text = 'No Upload';
            }

            // Same bucket the "All / Needs you / Cleared" tabs above use
            $is_cascaded_item = in_array($item['item_id'], [13, 15, 16]);
            $filter_bucket = 'other';
            if ($current_status === 'Approved') {
                $filter_bucket = 'cleared';
            } elseif (!$is_cascaded_item && in_array($current_status, ['No Upload', 'Revision Requested'])) {
                $filter_bucket = 'needs-you';
            }
        ?>
            <div class="item-card <?= $status_class ?>" data-filter="<?= $filter_bucket ?>" onclick="expandWalletCard(this, event)" style="z-index: <?= $card_index ?>;">
                <div class="card-inner-bg">


                    <div class="card-header">
                        <div class="status-icon-box num-indicator"><?= str_pad($card_index, 2, '0', STR_PAD_LEFT) ?></div>
                        <div>
                            <h3 class="card-title"><?= htmlspecialchars($item['item_name']) ?></h3>
                            <?php if (in_array($item['item_id'], [13, 15, 16])): ?>
                                <p class="card-meta">State updates automatically depending on the evaluation outcomes of your primary Capsule Proposal document.</p>
                            <?php else: ?>
                                <p class="card-meta"><?= htmlspecialchars($item['description']) ?></p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="card-body" onclick="event.stopPropagation()">

                        <!-- Status + History button -->
                        <div style="display:flex; align-items:center; justify-content:space-between; gap:8px; margin-bottom:16px;">
                            <div class="status-pill <?= $status_class ?>" style="margin-bottom:0;"><?= $pill_text ?></div>
                            <?php if (!empty($status_data['history'])): ?>
                                <button onclick="openHistoryPanel(<?= $item['item_id'] ?>)" class="btn-history">
                                    <i data-lucide="clock" style="width:13px;height:13px;"></i> History
                                </button>
                            <?php endif; ?>
                        </div>

                        <!-- Workflow steps -->
                        <?php if ($item['item_id'] == 11 || $item['item_id'] == 12): ?>
                            <div style="background: #f8fafc; border-radius: 12px; padding: 0 12px; margin-bottom: 16px; border: 1px solid #f1f5f9;">
                                <div style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; display: flex; align-items: center; gap: 12px; font-size: 13px; color: #334155;">
                                    <div style="background: #e2e8f0; width:22px; height:22px; border-radius: 50%; display:flex; align-items:center; justify-content:center; font-weight:600; font-size:11px; color:#475569; flex-shrink:0;">1</div>
                                    Download the blank form below
                                </div>
                                <div style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; display: flex; align-items: center; gap: 12px; font-size: 13px; color: #334155;">
                                    <div style="background: #e2e8f0; width:22px; height:22px; border-radius: 50%; display:flex; align-items:center; justify-content:center; font-weight:600; font-size:11px; color:#475569; flex-shrink:0;">2</div>
                                    Hand to adviser for signature
                                </div>
                                <div style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; display: flex; align-items: center; gap: 12px; font-size: 13px; color: #334155;">
                                    <div style="background: #e2e8f0; width:22px; height:22px; border-radius: 50%; display:flex; align-items:center; justify-content:center; font-weight:600; font-size:11px; color:#475569; flex-shrink:0;">3</div>
                                    Capture photo or scan
                                </div>
                                <div style="padding: 10px 0; display: flex; align-items: center; gap: 12px; font-size: 13px; color: #334155;">
                                    <div style="background: #e2e8f0; width:22px; height:22px; border-radius: 50%; display:flex; align-items:center; justify-content:center; font-weight:600; font-size:11px; color:#475569; flex-shrink:0;">4</div>
                                    Upload using the button below
                                </div>
                            </div>
                        <?php elseif ($item['item_id'] == 14): ?>
                            <div style="background: #f8fafc; border-radius: 12px; padding: 0 12px; margin-bottom: 16px; border: 1px solid #f1f5f9;">
                                <div style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; display: flex; align-items: center; gap: 12px; font-size: 13px; color: #334155;">
                                    <div style="background: #e2e8f0; width:22px; height:22px; border-radius: 50%; display:flex; align-items:center; justify-content:center; font-weight:600; font-size:11px; color:#475569; flex-shrink:0;">1</div>
                                    Download template below
                                </div>
                                <div style="padding: 10px 0; border-bottom: 1px solid #f1f5f9; display: flex; align-items: center; gap: 12px; font-size: 13px; color: #334155;">
                                    <div style="background: #e2e8f0; width:22px; height:22px; border-radius: 50%; display:flex; align-items:center; justify-content:center; font-weight:600; font-size:11px; color:#475569; flex-shrink:0;">2</div>
                                    Complete your capsule proposal
                                </div>
                                <div style="padding: 10px 0; display: flex; align-items: center; gap: 12px; font-size: 13px; color: #334155;">
                                    <div style="background: #e2e8f0; width:22px; height:22px; border-radius: 50%; display:flex; align-items:center; justify-content:center; font-weight:600; font-size:11px; color:#475569; flex-shrink:0;">3</div>
                                    Upload completed file below
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- Cascaded items notice -->
                        <?php if (in_array($item['item_id'], [13, 15, 16])): ?>
                            <?php if ($current_status !== 'Approved'): ?>
                                <div class="instruction-box">Awaiting Capsule Proposal Evaluation.</div>
                            <?php else: ?>
                                <div class="instruction-box" style="background:#ecfdf5;color:#047857;"><i data-lucide="check-circle-2" style="width:16px;height:16px;display:inline-block;vertical-align:middle;"></i> Cleared via Capsule Proposal.</div>
                            <?php endif; ?>
                        <?php endif; ?>

                        <!-- Remarks -->
                        <?php if (!empty($status_data['remarks'])): ?>
                            <div class="instruction-box" style="background:#fffbeb; color:#b45309; border:1px solid #fde68a; margin-bottom:16px;">
                                <strong>Remarks:</strong> "<?= htmlspecialchars($status_data['remarks']) ?>"
                            </div>
                        <?php endif; ?>

                        <!-- Download button -->
                        <?php if (in_array($item['item_id'], [11, 12, 13, 14])): ?>
                            <div style="margin-bottom:14px;">
                                <?php if ($item['item_id'] === 11): ?>
                                    <button onclick="openDownloadModal('../assigned_adviser.pdf', 'Assigned Adviser Form')" class="btn btn-outline"><i data-lucide="download" style="width:18px;height:18px;"></i> Download Blank Form</button>
                                <?php elseif ($item['item_id'] === 12): ?>
                                    <button onclick="openDownloadModal('../endorsement.pdf', 'Endorsement Form')" class="btn btn-outline"><i data-lucide="download" style="width:18px;height:18px;"></i> Download Blank Form</button>
                                <?php elseif ($item['item_id'] === 13): ?>
                                    <button onclick="openDownloadModal('../proposal_review.pdf', 'Proposal Review Reference')" class="btn btn-outline"><i data-lucide="download" style="width:18px;height:18px;"></i> Reference Form</button>
                                <?php elseif ($item['item_id'] === 14): ?>
                                    <button onclick="openDownloadModal('../capsule_form.pdf', 'Capsule Proposal Template')" class="btn btn-outline"><i data-lucide="download" style="width:18px;height:18px;"></i> Download Template</button>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <!-- Upload actions -->
                        <?php if (!in_array($item['item_id'], [13, 15, 16]) && $current_status !== 'Approved'): ?>
                            <form action="upload_handler.php" method="POST" enctype="multipart/form-data" target="_parent" onsubmit="handleUploadStart(this)" style="display:flex;flex-direction:column;gap:0;">
                                <input type="hidden" name="module_context" value="proposal">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                <input type="hidden" name="item_id" value="<?= $item['item_id'] ?>">
                                <input type="file" name="research_file" id="file-input-<?= $item['item_id'] ?>" style="display:none;" accept="image/*,.jpg,.jpeg,.png,.pdf,.doc,.docx" onchange="handleFileChange(this)">
                                <?php if (in_array($item['item_id'], [11, 12])): ?>
                                    <div style="display:flex; gap:12px; margin-top:4px;">
                                        <button type="button" id="file-btn-<?= $item['item_id'] ?>" class="btn btn-primary" style="flex:1;" onclick="triggerFilePicker(<?= $item['item_id'] ?>)">
                                            <i data-lucide="upload" style="width:18px;height:18px;"></i> Upload File
                                        </button>
                                        <button type="button" id="cam-btn-<?= $item['item_id'] ?>" class="btn btn-outline" style="width:60px; flex-shrink:0;" onclick="triggerCamera(<?= $item['item_id'] ?>)">
                                            <i data-lucide="camera" style="width:18px;height:18px;"></i>
                                        </button>
                                    </div>
                                <?php else: ?>
                                    <button type="button" id="file-btn-<?= $item['item_id'] ?>" class="btn btn-primary" style="margin-top:4px;" onclick="triggerFilePicker(<?= $item['item_id'] ?>)">
                                        <i data-lucide="upload" style="width:18px;height:18px;"></i> Upload File
                                    </button>
                                <?php endif; ?>
                            </form>
                        <?php endif; ?>

                        <!-- Latest submission card -->
                        <?php if ($status_data['file_path']):
                            $sub_fname = $status_data['original_filename'];
                            $sub_fpath = $status_data['file_path'];
                            $sub_fdate = $status_data['uploaded_at'] ? date('M j, Y', strtotime($status_data['uploaded_at'])) : '';
                            $sub_ftime = $status_data['uploaded_at'] ? date('g:i A', strtotime($status_data['uploaded_at'])) : '';
                            $sub_ext = strtolower(pathinfo($sub_fname, PATHINFO_EXTENSION));
                            $sub_is_img = in_array($sub_ext, ['jpg', 'jpeg', 'png', 'gif', 'webp']);
                            // Optional: Truncate long filename
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
                                    <?php if ($current_status === 'Pending'): // Only an un-reviewed draft can be deleted; once reviewed it is locked into history 
                                    ?>
                                        <button type="button" class="afc-btn afc-delete" onclick="deleteUpload(<?= $status_data['upload_id'] ?? 0 ?>, 'proposal'); event.stopPropagation();">
                                            <i data-lucide="trash-2" style="width:14px;height:14px;"></i>
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- Form 008 viewer -->
                        <?php if (in_array($item['item_id'], [13, 14]) && !empty($item_statuses[14]['form_008_data'])): ?>
                            <button onclick="openStudentForm008(<?= htmlspecialchars(json_encode($item_statuses[14]['form_008_data'])) ?>, <?= $item_statuses[14]['form_008_score'] ?: 0 ?>, '<?= $item_statuses[14]['form_008_decision'] ?>')" class="btn btn-warning" style="margin-top:12px;"><i data-lucide="eye" style="width:18px;height:18px;"></i> View Form 008 Findings</button>
                        <?php endif; ?>

                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- Web only: the items-grid becomes a horizontal scroller at ≥1025px (see dashboard-cards.css) -->
    <div class="items-scroll-nav">
        <button type="button" class="items-scroll-btn" id="itemsScrollPrev" title="Previous">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6" /></svg>
        </button>
        <button type="button" class="items-scroll-btn" id="itemsScrollNext" title="Next">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6" /></svg>
        </button>
    </div>


    <?php if ($message): ?>
        <div id="upload-toast" class="toast toast-<?= htmlspecialchars($message_type) ?>">
            <i data-lucide="<?= $message_type === 'success' ? 'check-circle' : 'alert-circle' ?>" style="width:16px;height:16px;"></i>
            <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>

    <!-- Download Preview Modal -->
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

    <!-- History Panels -->
    <?php foreach ($checklist_items as $hist_item):
        $hist = $item_statuses[$hist_item['item_id']]['history'] ?? [];
        if (empty($hist)) continue;
    ?>
        <div id="history-panel-<?= $hist_item['item_id'] ?>" class="history-panel">
            <div class="history-panel-header">
                <div>
                    <div style="font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:0.07em;color:#94a3b8;margin-bottom:2px;">Submission History</div>
                    <h3><?= htmlspecialchars($hist_item['item_name']) ?></h3>
                </div>
                <button onclick="closeHistoryPanel()" class="history-close-btn">
                    <i data-lucide="x" style="width:18px;height:18px;"></i>
                </button>
            </div>
            <div class="history-panel-body">
                <?php
                $hist_total = count($hist);
                foreach ($hist as $hi => $hu):
                    $hu_st = $hu['verification_status'];
                    if ($hu_st === 'Approved') $hu_sc = 'approved';
                    elseif ($hu_st === 'Revision Requested') $hu_sc = 'revision';
                    else $hu_sc = 'review';
                    // $hist is ordered newest-first; number versions chronologically (oldest = Version 1)
                    $hu_label = 'Version ' . ($hist_total - $hi) . ($hi === 0 ? ' (Latest Reviewed)' : '');
                    $hu_date = $hu['uploaded_at'] ? date('M j, Y \a\t g:i A', strtotime($hu['uploaded_at'])) : '';
                ?>
                    <div class="history-item">
                        <!-- History is a read-only audit trail: processed versions cannot be deleted -->
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
    <?php endforeach; ?>
    <div id="history-backdrop" class="history-backdrop" onclick="closeHistoryPanel()"></div>

    <script>
        window.CSRF_TOKEN = '<?= htmlspecialchars($_SESSION['csrf_token']) ?>';
    </script>
    <script>
        // All / Needs you / Cleared filter tabs — same behavior on mobile and web
        document.querySelectorAll('.status-filter-tab').forEach(function(tab) {
            tab.addEventListener('click', function() {
                document.querySelectorAll('.status-filter-tab').forEach(function(t) {
                    t.classList.remove('active');
                });
                tab.classList.add('active');
                var filter = tab.getAttribute('data-filter');
                var firstVisible = null;
                document.querySelectorAll('.item-card').forEach(function(card) {
                    var show = (filter === 'all' || card.getAttribute('data-filter') === filter);
                    card.style.display = show ? '' : 'none';
                    // Reset any previous override — the mobile wallet stack only cancels the
                    // cascading -85px overlap margin on the true DOM :first-child (via CSS), so
                    // after filtering hides that card the next VISIBLE one needs the same reset,
                    // otherwise it overlaps upward into the filter pills above it.
                    card.style.marginTop = '';
                    if (show && !firstVisible) firstVisible = card;
                });
                if (firstVisible) firstVisible.style.marginTop = '0';
            });
        });

        // Web-only horizontal scroller nav (items-grid becomes a single scrollable row at ≥1025px)
        (function() {
            var grid = document.querySelector('.items-grid');
            var prevBtn = document.getElementById('itemsScrollPrev');
            var nextBtn = document.getElementById('itemsScrollNext');
            if (!grid || !prevBtn || !nextBtn) return;
            var scrollAmount = 304;

            prevBtn.addEventListener('click', function() {
                grid.scrollBy({
                    left: -scrollAmount,
                    behavior: 'smooth'
                });
            });
            nextBtn.addEventListener('click', function() {
                grid.scrollBy({
                    left: scrollAmount,
                    behavior: 'smooth'
                });
            });

            function updateScrollNavState() {
                prevBtn.disabled = grid.scrollLeft <= 4;
                nextBtn.disabled = grid.scrollLeft >= (grid.scrollWidth - grid.clientWidth - 4);
            }
            grid.addEventListener('scroll', updateScrollNavState);
            window.addEventListener('resize', updateScrollNavState);
            updateScrollNavState();
        })();
    </script>
    <script src="../assets/js/dashboard-cards.js"></script>
    <?php include 'components/form008_modal.php'; ?>

</body>

</html>