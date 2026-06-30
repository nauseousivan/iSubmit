<?php
require_once '../config/db.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['Research Coordinator', 'Research Director'])) { exit("Access Denied"); }

$role = $_SESSION['role'];
$message = "";

// Process Document Verifications
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_type']) && $_POST['action_type'] === 'verify_upload') {
    $upload_id = $_POST['upload_id'];
    $status = $_POST['verification_status'];
    $remarks = trim($_POST['remarks']);
    $student_user_id = $_POST['student_user_id'];
    $req_name = $_POST['req_name'] ?? 'Document';

    // Pass to director queue if Coordinator approves
    if ($role === 'Research Coordinator') {
        $upload_status = ($status === 'Approved') ? 'Under Review' : $status;
    } else {
        $upload_status = $status;
    }

    $stmt = $pdo->prepare("UPDATE uploads SET verification_status = ?, remarks = ? WHERE upload_id = ?");
    $stmt->execute([$upload_status, $remarks, $upload_id]);
    
    $message = "Student compliance verification updated successfully.";

    // Log activity to notify the student
    $log_title = "Document Review - " . $status;
    $log_desc = "Your " . htmlspecialchars($req_name) . " has been reviewed by the " . $role . ". Status: " . $status . ".";
    $log_status = ($status === 'Approved') ? 'success' : 'warning';
    $log_stmt = $pdo->prepare("INSERT INTO activity_logs (user_id, title, description, status_type, created_at) VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)");
    $log_stmt->execute([$student_user_id, $log_title, $log_desc, $log_status]);
}

// Fetch checklist items for Proposal (form_id = 1)
$checklist_stmt = $pdo->prepare("SELECT * FROM checklist_items WHERE form_id = 1 ORDER BY CASE WHEN item_id = 12 THEN 10.5 WHEN item_id = 13 THEN 14.1 WHEN item_id = 15 THEN 14.2 WHEN item_id = 16 THEN 14.3 ELSE item_id END ASC");
$checklist_stmt->execute();
$checklist_items = $checklist_stmt->fetchAll();

// Fetch ONLY the most recent upload per group per item for Proposal Defense using a smart subquery mapping
$uploads_query = "
    SELECT up.upload_id, up.item_id, up.file_path, up.original_filename, up.verification_status, up.remarks, up.uploaded_at,
           u.username, u.research_group_name, u.user_id as student_user_id
    FROM uploads up
    JOIN users u ON up.user_id = u.user_id
    INNER JOIN (
        SELECT user_id, item_id, MAX(uploaded_at) as max_date 
        FROM uploads 
        WHERE item_id IN (SELECT item_id FROM checklist_items WHERE form_id = 1) 
        GROUP BY user_id, item_id
    ) latest ON up.user_id = latest.user_id AND up.item_id = latest.item_id AND up.uploaded_at = latest.max_date
    ORDER BY up.uploaded_at DESC
";
$uploads_stmt = $pdo->query($uploads_query);
$all_uploads = $uploads_stmt->fetchAll();

// Map uploads into array groups mapped by the requirement item ID
$uploads_by_item = [];
foreach ($all_uploads as $up) {
    $uploads_by_item[$up['item_id']][] = $up;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Admin - Proposal Defense Requirements</title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;600;800&display=swap');
        :root { 
            --mcnp-teal: #0c343d; --bg-beige: #f9f7f2; --border-line: #e5e7eb; 
            --ui-sans: 'Inter', system-ui, sans-serif;
            --success: #059669; --warning: #d97706; --danger: #dc2626; --info: #3b82f6;
        }
        body { font-family: var(--ui-sans); padding: 30px; background: transparent; color: #1f2937; margin: 0; }
        .page-header { margin-bottom: 25px; }
        .page-title { font-size: 24px; font-weight: 800; color: var(--mcnp-teal); margin-bottom: 5px; }
        .page-subtitle { font-size: 14px; color: #6b7280; }
        
        .alert { background: #ecfdf5; color: #065f46; padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; font-size: 14px; font-weight: 600; border-left: 4px solid var(--success); }

        .req-card { background: white; border: 1px solid var(--border-line); border-radius: 12px; margin-bottom: 15px; box-shadow: 0 4px 6px rgba(0,0,0,0.02); overflow: hidden; }
        .req-header { background: #fbf9f4; padding: 18px 24px; cursor: pointer; display: flex; justify-content: space-between; align-items: center; transition: 0.2s; border-bottom: 1px solid transparent; }
        .req-header:hover { background: #f4f0e6; }
        .req-header.active { border-bottom-color: var(--border-line); }
        .req-title { font-size: 16px; font-weight: 800; color: var(--mcnp-teal); display: flex; align-items: center; gap: 10px; }
        .req-meta { display: flex; align-items: center; gap: 15px; }
        .badge { background: var(--mcnp-teal); color: white; padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: bold; }
        .chevron { transition: transform 0.3s ease; }
        .req-header.active .chevron { transform: rotate(180deg); }
        
        .req-body { display: none; padding: 0; background: white; }
        .req-body.active { display: block; }
        
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 16px 24px; border-bottom: 1px solid var(--border-line); text-align: left; font-size: 13px; vertical-align: middle; }
        th { background: white; color: #6b7280; font-size: 11px; text-transform: uppercase; font-weight: 800; letter-spacing: 0.05em; }
        
        .status-pill { padding: 4px 10px; border-radius: 20px; font-size: 10px; font-weight: 800; text-transform: uppercase; }
        .status-pill.pending { background: #f3f4f6; color: #4b5563; }
        .status-pill.review { background: #fef3c7; color: #92400e; }
        .status-pill.approved { background: #d1fae5; color: #065f46; }
        .status-pill.revision { background: #fee2e2; color: #991b1b; }

        select, input[type="text"] { padding: 8px 12px; border-radius: 8px; border: 1.5px solid var(--border-line); font-size: 13px; font-family: inherit; width: 100%; outline: none; }
        select:focus, input[type="text"]:focus { border-color: var(--mcnp-teal); }
        
        .action-form { display: flex; gap: 10px; align-items: center; }
        .btn-update { background: var(--mcnp-teal); color: white; border: none; padding: 8px 16px; border-radius: 8px; cursor: pointer; font-size: 12px; font-weight: bold; transition: 0.2s; white-space: nowrap; }
        .btn-update:hover { opacity: 0.9; transform: translateY(-1px); }
        
        .empty-state { padding: 40px; text-align: center; color: #6b7280; font-size: 14px; }
        .file-link { color: var(--info); font-weight: 700; text-decoration: none; display: flex; align-items: center; gap: 5px; }
        .file-link:hover { text-decoration: underline; }
    </style>
</head>
<body>

    <div class="page-header">
        <h1 class="page-title">Proposal Defense Validations</h1>
        <p class="page-subtitle">Manage, review, and approve individual prerequisite documents uploaded by research groups.</p>
    </div>

    <?php if($message): ?>
        <div class="alert"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <?php foreach($checklist_items as $item): 
        $item_id = $item['item_id'];
        $submissions = $uploads_by_item[$item_id] ?? [];
        
        // Count how many are pending action to show an attention badge to the coordinator
        $pending_count = 0;
        foreach($submissions as $sub) {
            if ($role === 'Research Coordinator' && $sub['verification_status'] === 'Pending') $pending_count++;
            if ($role === 'Research Director' && $sub['verification_status'] === 'Under Review') $pending_count++;
        }
    ?>
    <div class="req-card">
        <div class="req-header" onclick="toggleReq(<?= $item_id ?>, this)">
            <div class="req-title">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>
                <?= htmlspecialchars($item['item_name']) ?>
            </div>
            <div class="req-meta">
                <?php if($pending_count > 0): ?>
                    <span class="badge" style="background: var(--danger);"><?= $pending_count ?> Action Needed</span>
                <?php endif; ?>
                <span class="badge"><?= count($submissions) ?> Uploads</span>
                <svg class="chevron" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"></polyline></svg>
            </div>
        </div>
        
        <div class="req-body" id="body-<?= $item_id ?>">
            <?php if(empty($submissions)): ?>
                <div class="empty-state">No student groups have uploaded this requirement yet.</div>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th style="width: 25%;">Research Group</th>
                            <th style="width: 20%;">Document</th>
                            <th style="width: 15%;">Status</th>
                            <th style="width: 40%;">Review Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($submissions as $sub): 
                            // Determine pill class
                            $pill_class = 'pending';
                            if ($sub['verification_status'] === 'Approved') $pill_class = 'approved';
                            if ($sub['verification_status'] === 'Under Review') $pill_class = 'review';
                            if ($sub['verification_status'] === 'Revision Requested') $pill_class = 'revision';
                        ?>
                        <tr>
                            <td>
                                <strong style="color: var(--mcnp-teal); font-size: 14px;"><?= htmlspecialchars($sub['research_group_name']) ?></strong><br>
                                <span style="color: #6b7280; font-size: 11px;">Uploaded by <?= htmlspecialchars($sub['username']) ?></span>
                            </td>
                            <td>
                                <a href="<?= htmlspecialchars($sub['file_path']) ?>" target="_blank" class="file-link">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path></svg>
                                    View Asset
                                </a>
                                <div style="font-size: 10px; color: #9ca3af; margin-top: 4px;"><?= date('M d, h:i A', strtotime($sub['uploaded_at'])) ?></div>
                            </td>
                            <td><span class="status-pill <?= $pill_class ?>"><?= htmlspecialchars($sub['verification_status']) ?></span></td>
                            <td>
                                <?php if($item_id == 14): // Item 14 is Complete Proposal Capsule ?>
                                    <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
                                        <button type="button" onclick="openForm008Modal(<?= $sub['upload_id'] ?>, <?= $sub['student_user_id'] ?>, '<?= htmlspecialchars($sub['research_group_name'], ENT_QUOTES) ?>')" style="background: #7c3aed; color: white; border: none; padding: 8px 14px; border-radius: 6px; cursor: pointer; font-size: 12px; font-weight: 600; white-space: nowrap; transition: 0.2s;">
                                            Form 008 Review
                                        </button>
                                    </div>
                                <?php else: ?>
                                    <form method="POST" class="action-form">
                                        <input type="hidden" name="action_type" value="verify_upload">
                                        <input type="hidden" name="upload_id" value="<?= $sub['upload_id'] ?>">
                                        <input type="hidden" name="student_user_id" value="<?= $sub['student_user_id'] ?>">
                                        <input type="hidden" name="req_name" value="<?= htmlspecialchars($item['item_name']) ?>">
                                        
                                        <select name="verification_status" style="width: 130px;" required>
                                            <option value="" disabled selected>Action...</option>
                                            <option value="Approved">Approve</option>
                                            <option value="Revision Requested">Reject / Revise</option>
                                        </select>
                                        <input type="text" name="remarks" placeholder="Add feedback notes..." value="<?= htmlspecialchars($sub['remarks'] ?? '') ?>" required>
                                        <button type="submit" class="btn-update">Update</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>

    <!-- Form 008 Modal -->
    <div id="form008Modal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 999; overflow-y: auto;">
        <div style="background: white; max-width: 900px; margin: 30px auto; border-radius: 12px; box-shadow: 0 10px 40px rgba(0,0,0,0.2);">
            <!-- Modal Header -->
            <div style="background: #fbf9f4; padding: 24px; border-bottom: 1px solid var(--border-line); display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <h2 style="margin: 0; color: var(--mcnp-teal); font-size: 22px; font-weight: 800;">Form 008: Research Proposal Review</h2>
                    <p style="margin: 5px 0 0 0; color: #6b7280; font-size: 13px;">ISAP Research Proposal Assessment Rubric</p>
                </div>
                <button onclick="closeForm008Modal()" style="background: none; border: none; font-size: 24px; cursor: pointer; color: #6b7280;">×</button>
            </div>

            <!-- Modal Body -->
            <form id="form008Form" style="padding: 30px;">
                <input type="hidden" name="action_type" value="save_form_008">
                <input type="hidden" name="upload_id" id="form008UploadId">
                <input type="hidden" name="student_user_id" id="form008StudentId">

                <!-- Project Info Section -->
                <div style="background: #f3f4f6; padding: 20px; border-radius: 8px; margin-bottom: 25px;">
                    <h3 style="margin: 0 0 15px 0; color: var(--mcnp-teal); font-size: 14px; font-weight: 800; text-transform: uppercase;">Project Information</h3>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                        <div>
                            <label style="display: block; font-size: 12px; color: #6b7280; font-weight: 600; margin-bottom: 5px;">Research Group</label>
                            <input type="text" name="research_group_name" style="padding: 10px 12px; border: 1.5px solid var(--border-line); border-radius: 6px; font-size: 13px; width: 100%; box-sizing: border-box;" required readonly>
                        </div>
                        <div>
                            <label style="display: block; font-size: 12px; color: #6b7280; font-weight: 600; margin-bottom: 5px;">Department</label>
                            <input type="text" name="department" style="padding: 10px 12px; border: 1.5px solid var(--border-line); border-radius: 6px; font-size: 13px; width: 100%; box-sizing: border-box;" required>
                        </div>
                        <div>
                            <label style="display: block; font-size: 12px; color: #6b7280; font-weight: 600; margin-bottom: 5px;">Adviser</label>
                            <input type="text" name="adviser_name" style="padding: 10px 12px; border: 1.5px solid var(--border-line); border-radius: 6px; font-size: 13px; width: 100%; box-sizing: border-box;" required>
                        </div>
                        <div>
                            <label style="display: block; font-size: 12px; color: #6b7280; font-weight: 600; margin-bottom: 5px;">Proponents</label>
                            <input type="text" name="proponents" style="padding: 10px 12px; border: 1.5px solid var(--border-line); border-radius: 6px; font-size: 13px; width: 100%; box-sizing: border-box;" required>
                        </div>
                    </div>
                    <div style="margin-top: 15px;">
                        <label style="display: block; font-size: 12px; color: #6b7280; font-weight: 600; margin-bottom: 5px;">Approved Title</label>
                        <textarea name="approved_title" style="padding: 10px 12px; border: 1.5px solid var(--border-line); border-radius: 6px; font-size: 13px; width: 100%; box-sizing: border-box; min-height: 60px; resize: vertical;" required></textarea>
                    </div>
                </div>

                <!-- Assessment Criteria Section -->
                <div style="margin-bottom: 25px;">
                    <h3 style="margin: 0 0 15px 0; color: var(--mcnp-teal); font-size: 14px; font-weight: 800; text-transform: uppercase;">Assessment Criteria (22 Items)</h3>
                    <div style="background: #f9fafb; border: 1px solid var(--border-line); border-radius: 8px; overflow: hidden;">
                        <table style="width: 100%; border-collapse: collapse;">
                            <thead>
                                <tr style="background: #f3f4f6;">
                                    <th style="padding: 12px 15px; text-align: left; font-size: 11px; font-weight: 800; color: #6b7280; border-bottom: 2px solid var(--border-line);">#</th>
                                    <th style="padding: 12px 15px; text-align: left; font-size: 11px; font-weight: 800; color: #6b7280; border-bottom: 2px solid var(--border-line);">Assessment Statement</th>
                                    <th style="padding: 12px 15px; text-align: center; font-size: 11px; font-weight: 800; color: #6b7280; border-bottom: 2px solid var(--border-line);" width="80">YES</th>
                                    <th style="padding: 12px 15px; text-align: center; font-size: 11px; font-weight: 800; color: #6b7280; border-bottom: 2px solid var(--border-line);" width="80">NO</th>
                                    <th style="padding: 12px 15px; text-align: left; font-size: 11px; font-weight: 800; color: #6b7280; border-bottom: 2px solid var(--border-line);">Comments</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $criteria = [
                                    "Clarity of Research Objectives",
                                    "Relevance and Currency of Literature Review",
                                    "Theoretical Framework Adequacy",
                                    "Research Design/Methodology Appropriateness",
                                    "Data Collection Methods Adequacy",
                                    "Data Analysis Methodology",
                                    "Significance and Impact of Research",
                                    "Feasibility of Research Plan",
                                    "Ethical Considerations",
                                    "Presentation and Communication Quality",
                                    "Timeline and Milestones",
                                    "Budget Justification",
                                    "Originality and Innovation",
                                    "Literature Coverage",
                                    "Hypothesis Clarity",
                                    "Variables Identification",
                                    "Sample Size Justification",
                                    "Reliability and Validity",
                                    "Limitation Acknowledgment",
                                    "Expected Outcomes",
                                    "Contribution to Field",
                                    "Overall Quality Assessment"
                                ];
                                
                                for ($i = 1; $i <= 22; $i++): 
                                    $criterion = $criteria[$i - 1] ?? "Criterion $i";
                                ?>
                                <tr style="border-bottom: 1px solid var(--border-line);">
                                    <td style="padding: 12px 15px; font-size: 12px; font-weight: 700; color: var(--mcnp-teal);"><?= $i ?></td>
                                    <td style="padding: 12px 15px; font-size: 13px; color: #374151;"><?= htmlspecialchars($criterion) ?></td>
                                    <td style="padding: 12px 15px; text-align: center;">
                                        <input type="radio" name="criteria_<?= $i ?>" value="yes" style="cursor: pointer;">
                                    </td>
                                    <td style="padding: 12px 15px; text-align: center;">
                                        <input type="radio" name="criteria_<?= $i ?>" value="no" style="cursor: pointer;">
                                    </td>
                                    <td style="padding: 12px 15px;">
                                        <textarea name="comment_<?= $i ?>" style="width: 100%; padding: 6px 8px; border: 1px solid var(--border-line); border-radius: 4px; font-size: 12px; min-height: 35px; box-sizing: border-box; resize: vertical;"></textarea>
                                    </td>
                                </tr>
                                <?php endfor; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Scoring Guide -->
                <div style="background: #eff6ff; border-left: 4px solid var(--info); padding: 15px; border-radius: 6px; margin-bottom: 25px; font-size: 12px;">
                    <strong style="color: var(--info); display: block; margin-bottom: 8px;">Scoring Guide:</strong>
                    <div style="color: #1e40af;">
                        • <strong>22 Points</strong> = 100% (ACCEPT)<br>
                        • <strong>15-21 Points</strong> = 90% (MINOR REVISION)<br>
                        • <strong>8-14 Points</strong> = 80% (MAJOR REVISION)<br>
                        • <strong>1-7 Points</strong> = 70% (DEFER)
                    </div>
                </div>

                <!-- Final Decision -->
                <div style="background: #f3f4f6; padding: 20px; border-radius: 8px; margin-bottom: 25px;">
                    <label style="display: block; font-size: 12px; color: #6b7280; font-weight: 800; margin-bottom: 10px; text-transform: uppercase;">Final Decision</label>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 10px;">
                        <label style="display: flex; align-items: center; padding: 10px 12px; background: white; border: 1.5px solid var(--border-line); border-radius: 6px; cursor: pointer; transition: 0.2s;">
                            <input type="radio" name="final_decision" value="ACCEPT" style="margin-right: 8px; cursor: pointer;" required> ACCEPT
                        </label>
                        <label style="display: flex; align-items: center; padding: 10px 12px; background: white; border: 1.5px solid var(--border-line); border-radius: 6px; cursor: pointer; transition: 0.2s;">
                            <input type="radio" name="final_decision" value="MINOR_REVISION" style="margin-right: 8px; cursor: pointer;" required> MINOR REVISION
                        </label>
                        <label style="display: flex; align-items: center; padding: 10px 12px; background: white; border: 1.5px solid var(--border-line); border-radius: 6px; cursor: pointer; transition: 0.2s;">
                            <input type="radio" name="final_decision" value="MAJOR_REVISION" style="margin-right: 8px; cursor: pointer;" required> MAJOR REVISION
                        </label>
                        <label style="display: flex; align-items: center; padding: 10px 12px; background: white; border: 1.5px solid var(--border-line); border-radius: 6px; cursor: pointer; transition: 0.2s;">
                            <input type="radio" name="final_decision" value="DEFER" style="margin-right: 8px; cursor: pointer;" required> DEFER
                        </label>
                    </div>
                </div>

                <!-- Modal Footer -->
                <div style="display: flex; gap: 10px; justify-content: flex-end; padding-top: 20px; border-top: 1px solid var(--border-line);">
                    <button type="button" onclick="closeForm008Modal()" style="background: #e5e7eb; color: #374151; border: none; padding: 10px 20px; border-radius: 6px; font-weight: 600; cursor: pointer; font-size: 13px;">Cancel</button>
                    <button type="submit" style="background: var(--mcnp-teal); color: white; border: none; padding: 10px 20px; border-radius: 6px; font-weight: 600; cursor: pointer; font-size: 13px; transition: 0.2s;">Save Form 008 Review</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function toggleReq(id, headerEl) {
            const body = document.getElementById('body-' + id);
            if (!body.classList.contains('active')) {
                body.classList.add('active');
                headerEl.classList.add('active');
            } else {
                body.classList.remove('active');
                headerEl.classList.remove('active');
            }
        }

        // Form 008 Modal Functions
        function openForm008Modal(uploadId, studentId, groupName) {
            document.getElementById('form008Modal').style.display = 'block';
            document.getElementById('form008UploadId').value = uploadId;
            document.getElementById('form008StudentId').value = studentId;
            document.getElementById('form008Form').querySelector('[name="research_group_name"]').value = groupName;
            
            // Load existing review if it exists
            loadForm008Review(uploadId);
        }

        function closeForm008Modal() {
            document.getElementById('form008Modal').style.display = 'none';
            document.getElementById('form008Form').reset();
        }

        function loadForm008Review(uploadId) {
            fetch(`form_008_handler.php?action=fetch_form_008&upload_id=${uploadId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.review) {
                        const review = data.review;
                        document.getElementById('form008Form').querySelector('[name="department"]').value = review.department || '';
                        document.getElementById('form008Form').querySelector('[name="adviser_name"]').value = review.adviser_name || '';
                        document.getElementById('form008Form').querySelector('[name="proponents"]').value = review.proponents || '';
                        document.getElementById('form008Form').querySelector('[name="approved_title"]').value = review.approved_title || '';

                        // Load assessment responses
                        if (review.assessment_responses) {
                            review.assessment_responses.forEach(item => {
                                if (item.response) {
                                    document.querySelector(`input[name="criteria_${item.criteria_num}"][value="${item.response}"]`).checked = true;
                                    document.querySelector(`textarea[name="comment_${item.criteria_num}"]`).value = item.comment || '';
                                }
                            });
                        }

                        // Set final decision
                        if (review.final_decision) {
                            document.querySelector(`input[name="final_decision"][value="${review.final_decision}"]`).checked = true;
                        }
                    }
                })
                .catch(error => console.error('Error loading form:', error));
        }

        document.getElementById('form008Form').addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);

            fetch('form_008_handler.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert(`Form 008 Review Saved!\n\nFinal Score: ${data.final_score}%`);
                    closeForm008Modal();
                    location.reload(); // Reload to see updated status
                } else {
                    alert('Error: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred while saving the form.');
            });
        });

        // Close modal when clicking outside
        document.getElementById('form008Modal').addEventListener('click', function(e) {
            if (e.target === this) closeForm008Modal();
        });
    </script>
</body>
</html>