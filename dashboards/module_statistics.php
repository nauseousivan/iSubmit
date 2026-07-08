<?php
require_once '../config/db.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Student') { exit("Access Denied"); }

$user_id = $_SESSION['user_id'];
// Determine effective user_id
$stmt_leader_check = $pdo->prepare("SELECT leader_id FROM users WHERE user_id = ?");
$stmt_leader_check->execute([$user_id]);
$u_data = $stmt_leader_check->fetch();
$leader_id_for_current_user = $u_data['leader_id'];
$effective_user_id = $leader_id_for_current_user ?? $user_id;

// Fetch state
$stmt_form = $pdo->prepare("SELECT * FROM form_stat_treatment WHERE user_id = ? ORDER BY date_submitted DESC LIMIT 1");
$stmt_form->execute([$effective_user_id]);
$stats_data = $stmt_form->fetch();

$stats_status = $stats_data['status'] ?? 'Phase 1: Pending Coded Data';
$control_no = $stats_data['formatted_control_no'] ?? '';
$remarks = $stats_data['statistician_remarks'] ?? '';
$result_file = $stats_data['result_file'] ?? '';

// Fetch checklist_items for form_id = 3
$checklist_stmt = $pdo->prepare("SELECT * FROM checklist_items WHERE form_id = 3 ORDER BY item_id ASC");
$checklist_stmt->execute();
$checklist_items = $checklist_stmt->fetchAll();

// Prepare an array to hold the latest status and remarks for each item
$uploads = [];
foreach ($checklist_items as $item) {
    $stmt = $pdo->prepare("SELECT upload_id, verification_status, remarks, file_path, original_filename, uploaded_at FROM uploads WHERE user_id = ? AND item_id = ? ORDER BY uploaded_at DESC LIMIT 1");
    $stmt->execute([$effective_user_id, $item['item_id']]);
    $latest_upload = $stmt->fetch();

    // Upload history for the timeline panel (read-only).
    $hist_stmt = $pdo->prepare("SELECT upload_id, verification_status, remarks, file_path, original_filename, uploaded_at FROM uploads WHERE user_id = ? AND item_id = ? AND verification_status IN ('Under Review', 'Approved', 'Revision Requested') ORDER BY uploaded_at DESC");
    $hist_stmt->execute([$effective_user_id, $item['item_id']]);
    $upload_history = $hist_stmt->fetchAll();

    if ($latest_upload) {
        $uploads[$item['item_id']] = [
            'upload_id' => $latest_upload['upload_id'],
            'verification_status' => $latest_upload['verification_status'],
            'remarks' => $latest_upload['remarks'],
            'file_path' => $latest_upload['file_path'],
            'original_filename' => $latest_upload['original_filename'],
            'uploaded_at' => $latest_upload['uploaded_at'],
            'history' => $upload_history
        ];
    } else {
        $uploads[$item['item_id']] = ['history' => $upload_history];
    }
}

// Determine current step (1-4)
$current_step = 1;
if (in_array($stats_status, ['Phase 1: Pending Coded Data', 'Phase 1: Coded Data Review', 'Phase 1: Coded Data Rejected'])) { $current_step = 1; }
elseif (in_array($stats_status, ['Phase 2: Form Download', 'Phase 4: Payment Verification'])) { $current_step = 2; }
elseif (in_array($stats_status, ['Phase 5: Registered', 'Phase 6: Under Review', 'Phase 6: Revision Requested'])) { $current_step = 3; }
elseif (in_array($stats_status, ['Phase 7: Statistical Treatment', 'Phase 7: Completed'])) { $current_step = 4; }

// Count uploaded deliverables for step 3 (Items 31, 32, 33, 34, 35)
$deliverable_count = 0;
foreach ([31,32,33,34,35] as $did) {
    if (isset($uploads[$did]['upload_id'])) $deliverable_count++;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Statistical Treatment Module</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=JetBrains+Mono:wght@400;500;700&display=swap" rel="stylesheet">
    <link href="../assets/css/dashboard-cards.css" rel="stylesheet">
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        :root, body.theme-default, body.theme-blue {
            /* Neutral slate accent (Apple-style) — no more blue headings */
            --teal: #0f172a;
            --teal-light: #1e293b;
            --teal-glow: rgba(15, 23, 42, 0.10);
            --gold: #d97706;
            --gold-soft: rgba(217, 119, 6, 0.15);
            --emerald: #059669;
            --emerald-soft: rgba(5, 150, 105, 0.1);
            --sky: #0ea5e9;
            --sky-soft: rgba(14, 165, 233, 0.1);
            --amber: #d97706;
            --amber-soft: rgba(217, 119, 6, 0.1);
            --rose: #e11d48;
            --rose-soft: rgba(225, 29, 72, 0.08);
            --bg: #ffffff;
            --bg-card: rgba(255, 255, 255, 0.85);
            --text: #0f172a;
            --text-secondary: #475569;
            --border: #e2e8f0;
            --shadow-sm: 0 1px 3px rgba(0,0,0,0.04), 0 1px 2px rgba(0,0,0,0.03);
            --shadow-md: 0 4px 20px rgba(0,0,0,0.06);
            --shadow-lg: 0 12px 40px rgba(0,0,0,0.08);
            --shadow-glow: 0 0 30px rgba(30, 64, 175, 0.08);
            --radius: 16px;
            --radius-sm: 10px;
            --font: 'Inter', system-ui, -apple-system, sans-serif;
            --mono: 'JetBrains Mono', monospace;
        }

        body.theme-red { --bg: #fef2f2; --bg-card: rgba(255, 255, 255, 0.85); --text: #450a0a; --text-secondary: #7f1d1d; --border: #fee2e2; --teal: #b91c1c; --teal-light: #7f1d1d; --teal-glow: rgba(185, 28, 28, 0.12); --shadow-glow: 0 0 30px rgba(185, 28, 28, 0.08); }
        body.theme-pink, body.theme-rose { --bg: #fde8f5; --bg-card: rgba(255, 255, 255, 0.85); --text: #4c2346; --text-secondary: #7a3870; --border: #f3c7dc; --teal: #c56ba8; --teal-light: #ac5e94; --teal-glow: rgba(197, 107, 168, 0.12); --shadow-glow: 0 0 30px rgba(197, 107, 168, 0.08); }
        body.theme-green { --bg: #e8f6ea; --bg-card: rgba(255, 255, 255, 0.85); --text: #2f4a33; --text-secondary: #4a7550; --border: #c9dec9; --teal: #4a9e7b; --teal-light: #3a8565; --teal-glow: rgba(74, 158, 123, 0.12); --shadow-glow: 0 0 30px rgba(74, 158, 123, 0.08); }
        body.theme-purple, body.theme-lavender { --bg: #f5f3ff; --bg-card: rgba(255, 255, 255, 0.85); --text: #4c1d95; --text-secondary: #5b21b6; --border: #ddd6fe; --teal: #6d28d9; --teal-light: #4c1d95; --teal-glow: rgba(109, 40, 217, 0.12); --shadow-glow: 0 0 30px rgba(109, 40, 217, 0.08); }
        body.theme-orange, body.theme-amber { --bg: #fffbeb; --bg-card: rgba(255, 255, 255, 0.85); --text: #78350f; --text-secondary: #92400e; --border: #fde68a; --teal: #b45309; --teal-light: #78350f; --teal-glow: rgba(180, 83, 9, 0.12); --shadow-glow: 0 0 30px rgba(180, 83, 9, 0.08); }
        body.theme-dark { --bg: #1a1d21; --bg-card: #24282d; --text: #e0e0e0; --text-secondary: #b0ada8; --border: #3a3f45; --teal: #4e9cae; --teal-light: #5fb3c8; --teal-glow: rgba(78, 156, 174, 0.15); --shadow-glow: 0 0 30px rgba(78, 156, 174, 0.08); }

        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: var(--font);
            background: var(--bg);
            color: var(--text);
            padding: 0;
            margin: 0;
            min-height: 100vh;
            -webkit-font-smoothing: antialiased;
        }

        /* ── Animations ─────────────────────────────── */
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-12px); }
            to { opacity: 1; transform: translateY(0); }
        }
        @keyframes pulseGlow {
            0%, 100% { box-shadow: 0 0 0 0 rgba(5, 150, 105, 0.4); }
            50% { box-shadow: 0 0 0 8px rgba(5, 150, 105, 0); }
        }
        @keyframes shimmer {
            0% { background-position: -200% 0; }
            100% { background-position: 200% 0; }
        }
        @keyframes float {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-6px); }
        }
        @keyframes scaleIn {
            from { opacity: 0; transform: scale(0.92); }
            to { opacity: 1; transform: scale(1); }
        }
        @keyframes borderPulse {
            0%, 100% { border-color: var(--emerald); }
            50% { border-color: var(--gold); }
        }

        .animate-in { animation: fadeInUp 0.5s ease forwards; opacity: 0; }
        .delay-1 { animation-delay: 0.08s; }
        .delay-2 { animation-delay: 0.16s; }
        .delay-3 { animation-delay: 0.24s; }
        .delay-4 { animation-delay: 0.32s; }
        .delay-5 { animation-delay: 0.40s; }
        .delay-6 { animation-delay: 0.48s; }

        /* ── Page Container ─────────────────────────── */
        .page-wrapper {
            max-width: 960px;
            margin: 0 auto;
            padding: 22px 28px 60px;
        }

        /* ── Control-number chip row ────────────────── */
        .hero-header {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            margin-bottom: 18px;
        }

        .control-no-chip {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: var(--teal);
            color: white;
            padding: 8px 16px;
            border-radius: 30px;
            font-size: 12px;
            font-weight: 700;
            font-family: var(--mono);
            letter-spacing: 0.5px;
            box-shadow: var(--shadow-md);
            flex-shrink: 0;
        }

        .control-no-chip i { opacity: 0.7; }

        /* ── Success/Error Flash ────────────────────── */
        .flash-msg {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 14px 18px;
            border-radius: var(--radius-sm);
            margin-bottom: 24px;
            font-size: 13.5px;
            font-weight: 600;
            animation: slideDown 0.4s ease;
        }
        .flash-msg.success { background: var(--emerald-soft); color: #065f46; border: 1px solid rgba(5, 150, 105, 0.2); }
        .flash-msg.error { background: var(--rose-soft); color: #9f1239; border: 1px solid rgba(225, 29, 72, 0.15); }

        /* ── Step Tracker (Horizontal) ──────────────── */
        .step-tracker {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            margin-bottom: 26px;
            position: relative;
            padding: 0 10px;
        }

        .step-tracker::before {
            content: '';
            position: absolute;
            top: 20px;
            left: 40px;
            right: 40px;
            height: 3px;
            background: #e5e7eb;
            border-radius: 2px;
            z-index: 0;
        }

        .step-tracker .progress-line {
            position: absolute;
            top: 20px;
            left: 40px;
            height: 3px;
            background: linear-gradient(90deg, var(--emerald), var(--teal));
            border-radius: 2px;
            z-index: 1;
            transition: width 0.8s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .step-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 10px;
            position: relative;
            z-index: 2;
            flex: 1;
        }

        .step-circle {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            font-weight: 800;
            border: 3px solid #e5e7eb;
            background: white;
            color: #9ca3af;
            transition: all 0.4s ease;
            position: relative;
        }

        .step-item.completed .step-circle {
            background: var(--emerald);
            border-color: var(--emerald);
            color: white;
            box-shadow: 0 3px 12px rgba(5, 150, 105, 0.3);
        }

        .step-item.active .step-circle {
            background: var(--teal);
            border-color: var(--teal);
            color: white;
            box-shadow: 0 3px 12px rgba(12, 52, 61, 0.3);
            animation: pulseGlow 2s infinite;
        }

        .step-label {
            font-size: 11px;
            font-weight: 700;
            color: #9ca3af;
            text-align: center;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            max-width: 100px;
            line-height: 1.3;
        }

        .step-item.completed .step-label,
        .step-item.active .step-label { color: var(--teal); }

        /* ── Current Step Card — clean white / soft shadow (Apple widget style) ── */
        .step-alert {
            background: #ffffff;
            border: 1px solid #f3f4f6;
            border-radius: 20px;
            padding: 18px 20px;
            margin-bottom: 24px;
            display: flex;
            gap: 14px;
            align-items: flex-start;
            box-shadow: 0 8px 16px -4px rgba(0, 0, 0, 0.05);
            position: relative;
            overflow: hidden;
        }

        .step-alert-icon {
            width: 40px;
            height: 40px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .step-alert.info .step-alert-icon { background: var(--sky-soft); color: var(--sky); }
        .step-alert.warning .step-alert-icon { background: var(--amber-soft); color: var(--amber); }
        .step-alert.success .step-alert-icon { background: var(--emerald-soft); color: var(--emerald); }
        .step-alert.gold .step-alert-icon { background: var(--gold-soft); color: var(--gold); }

        .step-alert-body h3 {
            font-size: 15px;
            font-weight: 800;
            color: #0f172a;
            margin-bottom: 4px;
        }

        .step-alert-body p {
            font-size: 13px;
            color: #475569;
            line-height: 1.55;
        }

        .step-alert-body p strong { color: #0f172a; font-weight: 700; }

        .remarks-inline {
            margin-top: 12px;
            padding: 10px 14px;
            background: var(--amber-soft);
            border-radius: var(--radius-sm);
            font-size: 12.5px;
            color: #92400e;
            border-left: 3px solid var(--amber);
            line-height: 1.5;
        }

        .remarks-inline strong { color: #78350f; }

        .btn-inline {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-top: 14px;
            padding: 10px 20px;
            border-radius: var(--radius-sm);
            font-size: 13px;
            font-weight: 700;
            font-family: var(--font);
            cursor: pointer;
            border: none;
            text-decoration: none;
            transition: all 0.25s ease;
        }

        .btn-inline.primary {
            background: var(--teal);
            color: white;
            box-shadow: 0 2px 8px rgba(12, 52, 61, 0.2);
        }
        .btn-inline.primary:hover { transform: translateY(-1px); box-shadow: 0 4px 16px rgba(12, 52, 61, 0.25); }

        .btn-inline.outline {
            background: white;
            color: var(--teal);
            border: 1.5px solid var(--border);
        }
        .btn-inline.outline:hover { background: #faf9f6; border-color: var(--teal); }

        /* ── Deliverables Grid ─────────────────────── */
        .section-heading {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 20px;
        }

        .section-heading h2 {
            font-size: 18px;
            font-weight: 800;
            color: var(--teal);
        }

        .section-heading .count-pill {
            background: var(--teal);
            color: white;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 800;
        }

        /* ── Deliverable cards ──────────────────────────────────
           These now reuse the shared Apple-Wallet card system from
           dashboard-cards.css (identical to Module Proposal): overlapping
           colour-coded cards on mobile (green / orange / red / purple),
           the draggable full-screen sheet, and the shared status-pill,
           History button and file-card / View button placements.
           Only a couple of statistics-specific tweaks live here. */
        /* Locked (Phase 7) cards are dimmed but STILL tappable so the draggable
           sheet always opens for viewing — the upload button is hidden instead. */
        .item-card.locked {
            opacity: 0.72;
            filter: grayscale(0.25);
        }

        /* ── Upload Modal ──────────────────────────── */
        .modal-backdrop {
            position: fixed;
            inset: 0;
            background: rgba(10, 30, 36, 0.5);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            display: none;
            justify-content: center;
            align-items: center;
            /* Above the wallet-active card (9999) and history/download sheets */
            z-index: 100000;
            padding: 20px;
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .modal-backdrop.active {
            display: flex;
            opacity: 1;
        }

        .modal-box {
            background: white;
            width: 100%;
            max-width: 480px;
            border-radius: 20px;
            box-shadow: 0 25px 60px rgba(0, 0, 0, 0.2);
            animation: scaleIn 0.35s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            overflow: hidden;
        }

        .modal-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 22px 24px;
            border-bottom: 1.5px solid var(--border);
            background: #faf9f6;
        }

        .modal-head-left {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .modal-head-left .modal-icon {
            width: 40px;
            height: 40px;
            background: var(--teal);
            color: white;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .modal-head-left h3 {
            font-size: 17px;
            font-weight: 700;
            color: var(--teal);
        }

        .modal-head-left p {
            font-size: 11.5px;
            color: var(--text-secondary);
            margin-top: 1px;
        }

        .modal-close {
            background: none;
            border: none;
            color: var(--text-secondary);
            cursor: pointer;
            padding: 6px;
            border-radius: 8px;
            transition: all 0.2s;
            display: flex;
        }
        .modal-close:hover { background: #fee2e2; color: var(--rose); }

        .modal-body { padding: 24px; }

        .drop-zone {
            border: 2px dashed #d1d5db;
            border-radius: 14px;
            padding: 36px 20px;
            text-align: center;
            cursor: pointer;
            transition: all 0.25s ease;
            background: #faf9f8;
            position: relative;
        }

        .drop-zone:hover,
        .drop-zone.dragover {
            border-color: var(--sky);
            background: var(--sky-soft);
            transform: scale(1.01);
        }

        .drop-zone input[type="file"] {
            position: absolute;
            inset: 0;
            opacity: 0;
            cursor: pointer;
        }

        .drop-zone-icon {
            width: 52px;
            height: 52px;
            border-radius: 14px;
            background: var(--sky-soft);
            color: var(--sky);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 14px;
            animation: float 3s ease-in-out infinite;
        }

        .drop-zone h4 {
            font-size: 15px;
            font-weight: 700;
            color: var(--teal);
            margin-bottom: 4px;
        }

        .drop-zone p {
            font-size: 12.5px;
            color: var(--text-secondary);
        }

        .drop-zone p span { font-weight: 700; color: var(--sky); }

        .file-preview {
            display: none;
            align-items: center;
            gap: 12px;
            padding: 14px 16px;
            background: var(--emerald-soft);
            border: 1px solid rgba(5, 150, 105, 0.15);
            border-radius: var(--radius-sm);
            margin-top: 16px;
        }

        .file-preview.visible { display: flex; }

        .file-preview-icon {
            width: 36px;
            height: 36px;
            background: var(--emerald);
            color: white;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .file-preview-name {
            font-size: 13px;
            font-weight: 600;
            color: #065f46;
            flex: 1;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .btn-submit-modal {
            width: 100%;
            padding: 14px;
            margin-top: 20px;
            background: linear-gradient(135deg, var(--teal), var(--teal-light));
            color: white;
            border: none;
            border-radius: var(--radius-sm);
            font-family: var(--font);
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: all 0.3s ease;
            box-shadow: 0 4px 16px rgba(12, 52, 61, 0.2);
        }

        .btn-submit-modal:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 24px rgba(12, 52, 61, 0.3);
        }

        /* ── Completion Card ───────────────────────── */
        .completion-card {
            background: linear-gradient(135deg, #ecfdf5, #d1fae5);
            border: 2px solid rgba(5, 150, 105, 0.2);
            border-radius: var(--radius);
            padding: 32px;
            text-align: center;
            margin-bottom: 32px;
            position: relative;
            overflow: hidden;
        }

        .completion-card::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(5, 150, 105, 0.06) 0%, transparent 70%);
            animation: float 6s ease-in-out infinite;
        }

        .completion-icon {
            width: 64px;
            height: 64px;
            background: var(--emerald);
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 16px;
            box-shadow: 0 4px 20px rgba(5, 150, 105, 0.3);
            position: relative;
        }

        .completion-card h3 {
            font-size: 20px;
            font-weight: 800;
            color: #065f46;
            margin-bottom: 8px;
        }

        .completion-card p {
            font-size: 14px;
            color: #047857;
            line-height: 1.6;
        }

        /* ── Responsive ────────────────────────────── */
        @media (max-width: 600px) {
            .page-wrapper { padding: 20px 16px 40px; }
            .hero-header { flex-direction: column; }
            .step-tracker { gap: 0; }
            .step-label { font-size: 9px; max-width: 70px; }
            .step-circle { width: 34px; height: 34px; font-size: 12px; }
        }
    </style>
</head>
<body>
    <div class="page-wrapper">
        <!-- Control-number chip only (title lives in the overlay chrome) -->
        <?php if ($control_no): ?>
            <div class="hero-header animate-in">
                <div class="control-no-chip">
                    <i data-lucide="hash" style="width:14px; height:14px;"></i>
                    <?= htmlspecialchars($control_no) ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- Flash Messages -->
        <?php if (isset($_GET['msg'])): ?>
            <div class="flash-msg <?= ($_GET['type'] ?? 'success') === 'error' ? 'error' : 'success' ?>">
                <i data-lucide="<?= ($_GET['type'] ?? 'success') === 'error' ? 'alert-triangle' : 'check-circle-2' ?>" style="width:18px; height:18px; flex-shrink:0;"></i>
                <?= htmlspecialchars($_GET['msg']) ?>
            </div>
        <?php endif; ?>

        <!-- Step Tracker -->
        <div class="step-tracker animate-in delay-1">
            <?php
            $step_progress = 0;
            if ($current_step >= 2) $step_progress = 33;
            if ($current_step >= 3) $step_progress = 66;
            if ($current_step >= 4) $step_progress = 100;

            $steps = [
                ['num' => 1, 'label' => 'Data Upload', 'icon' => 'upload-cloud'],
                ['num' => 2, 'label' => 'Finance Payment', 'icon' => 'wallet'],
                ['num' => 3, 'label' => 'Requirements', 'icon' => 'file-stack'],
                ['num' => 4, 'label' => 'Release', 'icon' => 'award'],
            ];
            ?>
            <div class="progress-line" style="width: calc(<?= $step_progress ?>% * 0.85);"></div>
            <?php foreach ($steps as $s): 
                $state = '';
                if ($s['num'] < $current_step || ($s['num'] == $current_step && $stats_status === 'Phase 7: Completed')) $state = 'completed';
                elseif ($s['num'] == $current_step && $stats_status !== 'Phase 7: Completed') $state = 'active';
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

        <!-- Step Alert Cards -->
        <?php if ($stats_status === 'Phase 1: Pending Coded Data' || $stats_status === 'Phase 1: Coded Data Rejected'): ?>
            <div class="step-alert warning animate-in delay-2">
                <div class="step-alert-icon">
                    <i data-lucide="alert-circle" style="width:22px; height:22px;"></i>
                </div>
                <div class="step-alert-body">
                    <h3>Step 1: Initial Coded Data</h3>
                    <p>Upload your <strong>Initial Coded Data (Excel)</strong> for the Statistician to verify. Once approved, you'll be directed to proceed with the Finance Office.</p>
                    <?php if ($remarks): ?>
                        <div class="remarks-inline">
                            <strong>Statistician Remarks:</strong> <?= nl2br(htmlspecialchars($remarks)) ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php elseif ($stats_status === 'Phase 1: Coded Data Review'): ?>
            <div class="step-alert info animate-in delay-2">
                <div class="step-alert-icon">
                    <i data-lucide="clock" style="width:22px; height:22px;"></i>
                </div>
                <div class="step-alert-body">
                    <h3>Step 1: Data Under Verification</h3>
                    <p>Your initial coded data has been submitted and is currently <strong>being verified</strong> by the Research Statistician. You will receive a notification once verified.</p>
                </div>
            </div>
        <?php elseif ($stats_status === 'Phase 2: Form Download'): ?>
            <div class="step-alert gold animate-in delay-2">
                <div class="step-alert-icon">
                    <i data-lucide="wallet" style="width:22px; height:22px;"></i>
                </div>
                <div class="step-alert-body">
                    <h3>Step 2: Proceed to Finance Office</h3>
                    <p>Your initial data has been <strong>approved</strong>! Please follow these exact steps:<br><br>
                    1. <strong>Print and fill out</strong> the Statistical Treatment Form (RDC Form No. 011). You can download it if you don't have it yet.<br>
                    2. <strong>Proceed to the Finance Office</strong> to pay the processing fee.<br>
                    3. Get the form <strong>Validated</strong> and secure your <strong>Official Receipt</strong>.<br>
                    4. <strong>Upload both documents below</strong> — or present your Official Receipt physically at the Research Office and the Statistician will register you directly.</p>
                    <?php if ($remarks): ?>
                        <div class="remarks-inline">
                            <strong>Statistician Remarks:</strong> <?= nl2br(htmlspecialchars($remarks)) ?>
                        </div>
                    <?php endif; ?>
                    <div style="display: flex; gap: 10px; margin-top: 14px; flex-wrap: wrap;">
                        <a href="downloads/rdc.jpg" target="_blank" class="btn-inline outline">
                            <i data-lucide="download" style="width:15px; height:15px;"></i> Download RDC Form No. 011
                        </a>
                    </div>
                </div>
            </div>
        <?php elseif ($stats_status === 'Phase 4: Payment Verification'): ?>
            <div class="step-alert info animate-in delay-2">
                <div class="step-alert-icon">
                    <i data-lucide="clock" style="width:22px; height:22px;"></i>
                </div>
                <div class="step-alert-body">
                    <h3>Step 2: Payment Verification</h3>
                    <p>Your payment documents have been submitted and are currently <strong>being verified</strong> by the Statistician. Please wait while your Official Control Number is registered.</p>
                </div>
            </div>
        <?php elseif (in_array($stats_status, ['Phase 5: Registered', 'Phase 6: Under Review', 'Phase 6: Revision Requested'])): ?>
            <div class="step-alert <?= $stats_status === 'Phase 6: Revision Requested' ? 'warning' : 'success' ?> animate-in delay-2">
                <div class="step-alert-icon">
                    <i data-lucide="<?= $stats_status === 'Phase 6: Revision Requested' ? 'alert-circle' : 'check-circle-2' ?>" style="width:22px; height:22px;"></i>
                </div>
                <div class="step-alert-body">
                    <h3>Step 3: <?= $stats_status === 'Phase 6: Revision Requested' ? 'Revision Needed on a Requirement' : 'Upload Remaining Requirements' ?></h3>
                    <p><?php if ($stats_status === 'Phase 6: Revision Requested'): ?>
                        The Statistician requested a <strong>revision</strong> on one or more of your requirements. Check the remarks on the flagged card below and re-upload the corrected file.
                    <?php else: ?>
                        Your payment is verified and your Official Control Number has been registered. You may now upload the remaining deliverables below.
                    <?php endif; ?>
                    <?php if ($control_no): ?>
                        Your Control Number is: <strong style="font-family: var(--mono);"><?= htmlspecialchars($control_no) ?></strong>
                    <?php endif; ?>
                    </p>
                    <?php if ($remarks && $stats_status === 'Phase 6: Revision Requested'): ?>
                        <div class="remarks-inline">
                            <strong>Statistician Remarks:</strong> <?= nl2br(htmlspecialchars($remarks)) ?>
                        </div>
                    <?php endif; ?>
                    <?php if ($deliverable_count > 0): ?>
                        <div style="margin-top: 12px; display: flex; align-items: center; gap: 8px;">
                            <div style="flex: 1; height: 6px; background: #e5e7eb; border-radius: 3px; overflow: hidden;">
                                <div style="width: <?= ($deliverable_count / 5) * 100 ?>%; height: 100%; background: var(--emerald); border-radius: 3px; transition: width 0.5s ease;"></div>
                            </div>
                            <span style="font-size: 11px; font-weight: 700; color: var(--emerald);"><?= $deliverable_count ?>/5</span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php elseif ($stats_status === 'Phase 7: Statistical Treatment'): ?>
            <div class="step-alert info animate-in delay-2">
                <div class="step-alert-icon">
                    <i data-lucide="loader" style="width:22px; height:22px;"></i>
                </div>
                <div class="step-alert-body">
                    <h3>Step 4: Under Statistical Treatment</h3>
                    <p>All of your requirements have been <strong>approved</strong>. The Research Statistician is now processing your data. Your final results will appear here once released — you will also be notified in your activity feed.</p>
                </div>
            </div>
        <?php elseif ($stats_status === 'Phase 7: Completed'): ?>
            <div class="completion-card animate-in delay-2">
                <div class="completion-icon">
                    <i data-lucide="award" style="width:30px; height:30px;"></i>
                </div>
                <h3>Statistical Treatment Completed</h3>
                <p>The Statistician has finalized your data treatment.<br>You may download your processed results below.</p>
                <div class="step-alert success" style="max-width: 600px; margin: 20px auto; text-align: left;">
                    <div class="step-alert-icon"><i data-lucide="info" style="width:22px; height:22px;"></i></div>
                    <div class="step-alert-body">
                        <h3>Final Step</h3>
                        <p><strong>Please proceed to the Research Office</strong> to claim your physical copies of the Statistical Treatment Results.</p>
                    </div>
                </div>
                <?php if ($result_file): ?>
                    <div style="margin-top: 16px;">
                        <a href="<?= htmlspecialchars($result_file) ?>" target="_blank" class="btn-inline primary">
                            <i data-lucide="download" style="width:16px; height:16px;"></i> Download Final Results
                        </a>
                    </div>
                <?php endif; ?>
                <?php if ($remarks): ?>
                    <div class="remarks-inline" style="text-align: left; max-width: 600px; margin: 16px auto 0; background: rgba(5, 150, 105, 0.08); color: #065f46; border-left-color: var(--emerald);">
                        <strong>Statistician Remarks:</strong> <?= nl2br(htmlspecialchars($remarks)) ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- Deliverable Cards Grid -->
        <?php 
        // Filter checklist items based on current step
        $visible_items = [];
        foreach ($checklist_items as $item) {
            if ($current_step == 1 && $item['item_id'] == 30) {
                $visible_items[] = $item;
            } elseif ($current_step == 2 && in_array($item['item_id'], [36, 37])) {
                $visible_items[] = $item;
            } elseif ($current_step >= 3 && in_array($item['item_id'], [31, 32, 33, 34, 35])) {
                $visible_items[] = $item;
            }
        }
        ?>

        <?php if (count($visible_items) > 0 && $stats_status !== 'Phase 7: Completed'): ?>
            <div class="mobile-stack-hint">
                <i data-lucide="hand-pointer" style="width:14px;height:14px;"></i> Tap any card to manage
            </div>

            <div class="items-grid">
                <?php
                $card_index = 0;
                foreach ($visible_items as $item):

                    // Cards stay interactive while a submission is still Pending so the group can
                    // delete or replace it before review (same lifecycle as the proposal module).
                    // Everything locks once the request reaches Phase 7.
                    $is_locked = in_array($stats_status, ['Phase 7: Statistical Treatment', 'Phase 7: Completed']);

                    $upload_data = $uploads[$item['item_id']] ?? null;
                    $has_upload = $upload_data && isset($upload_data['upload_id']);

                    // Map to the shared wallet status classes:
                    //   no-upload = purple · review = orange · approved = green · revision = red
                    $card_status = 'no-upload';
                    $status_text = 'No Upload';

                    if ($has_upload) {
                        $vs = strtolower($upload_data['verification_status'] ?? 'pending');
                        if ($vs == 'approved') {
                            $card_status = 'approved';
                            $status_text = 'Approved';
                        } elseif ($vs == 'revision requested' || strpos($vs, 'reject') !== false) {
                            $card_status = 'revision';
                            $status_text = 'Revision Needed';
                        } else {
                            $card_status = 'review';
                            $status_text = 'Under Review';
                        }
                    }

                    $card_index++;
                ?>
                    <div class="item-card <?= $card_status ?> <?= $is_locked ? 'locked' : '' ?>" onclick="expandWalletCard(this, event)" style="z-index: <?= $card_index ?>;">
                        <div class="card-inner-bg">
                            <div class="card-header">
                                <div class="status-icon-box num-indicator"><?= str_pad($card_index, 2, '0', STR_PAD_LEFT) ?></div>
                                <div>
                                    <h3 class="card-title"><?= htmlspecialchars($item['item_name']) ?></h3>
                                    <p class="card-meta"><?= htmlspecialchars($item['description']) ?></p>
                                </div>
                            </div>

                            <div class="card-body" onclick="event.stopPropagation()">

                                <!-- Status + History -->
                                <div style="display:flex; align-items:center; justify-content:space-between; gap:8px; margin-bottom:16px;">
                                    <div class="status-pill <?= $card_status ?>" style="margin-bottom:0;"><?= $status_text ?></div>
                                    <?php if (!empty($upload_data['history'])): ?>
                                        <button onclick="openHistoryPanel(<?= $item['item_id'] ?>)" class="btn-history">
                                            <i data-lucide="clock" style="width:13px;height:13px;"></i> History
                                        </button>
                                    <?php endif; ?>
                                </div>

                                <!-- Remarks -->
                                <?php if (!empty($upload_data['remarks'])): ?>
                                    <div class="instruction-box" style="background:#fffbeb; color:#b45309; border:1px solid #fde68a; margin-bottom:16px;">
                                        <strong>Remarks:</strong> "<?= htmlspecialchars($upload_data['remarks']) ?>"
                                    </div>
                                <?php endif; ?>

                                <!-- Latest submission -->
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
                                            <?php if (!$is_locked && $card_status === 'review'): // un-reviewed draft can be removed ?>
                                                <button type="button" class="afc-btn afc-delete" onclick="deleteUpload(<?= $upload_data['upload_id'] ?? 0 ?>, 'stats'); event.stopPropagation();">
                                                    <i data-lucide="trash-2" style="width:14px;height:14px;"></i>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <!-- Upload / Re-upload -->
                                <?php if (!$is_locked && $card_status !== 'approved'): ?>
                                    <button type="button" class="btn btn-primary" style="margin-top:14px;" onclick="openUploadModal(<?= $item['item_id'] ?>, '<?= htmlspecialchars($item['item_name'], ENT_QUOTES) ?>', '<?= $item['required_file_type'] ?? 'Any' ?>')">
                                        <i data-lucide="<?= $has_upload ? 'refresh-cw' : 'upload' ?>" style="width:18px;height:18px;"></i>
                                        <?= $has_upload ? 'Re-upload' : 'Upload ' . htmlspecialchars($item['required_file_type'] ?? 'File') ?>
                                    </button>
                                <?php elseif ($is_locked): ?>
                                    <div class="instruction-box" style="margin-bottom:0;"><i data-lucide="lock" style="width:15px;height:15px;display:inline-block;vertical-align:middle;"></i> Locked while your data is under statistical treatment.</div>
                                <?php endif; ?>

                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Download Preview Modal (shared behaviour with Module Proposal) -->
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
                        <h3 id="modalTitle">Upload Document</h3>
                        <p>Required format: <strong id="modalFormat"></strong></p>
                    </div>
                </div>
                <button class="modal-close" onclick="closeUploadModal()">
                    <i data-lucide="x" style="width:20px; height:20px;"></i>
                </button>
            </div>

            <div class="modal-body">
                <form action="stat_upload_handler.php" method="POST" enctype="multipart/form-data" id="uploadForm">
                    <input type="hidden" name="action" value="submit_stat_requirement">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                    <input type="hidden" name="item_id" id="modalItemId">

                    <div class="drop-zone" id="dropArea">
                        <div class="drop-zone-icon">
                            <i data-lucide="cloud-upload" style="width:24px; height:24px;"></i>
                        </div>
                        <h4>Drop your file here</h4>
                        <p>or <span>click to browse</span> from your device</p>
                        <input type="file" name="research_file" id="fileInput" required>
                    </div>

                    <div class="file-preview" id="filePreview">
                        <div class="file-preview-icon">
                            <i data-lucide="file-check" style="width:18px; height:18px;"></i>
                        </div>
                        <span class="file-preview-name" id="fileName"></span>
                        <i data-lucide="check-circle-2" style="width:18px; height:18px; color: var(--emerald); flex-shrink: 0;"></i>
                    </div>

                    <button type="submit" class="btn-submit-modal">
                        <i data-lucide="send" style="width:16px; height:16px;"></i>
                        Submit Document
                    </button>
                </form>
            </div>
        </div>
    </div>

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

        function openUploadModal(itemId, itemName, requiredFormat) {
            document.getElementById('modalItemId').value = itemId;
            document.getElementById('modalTitle').textContent = 'Upload ' + itemName;
            document.getElementById('modalFormat').textContent = (requiredFormat || 'Any').toUpperCase();

            if (requiredFormat && requiredFormat.toLowerCase() === 'pdf') fileInput.accept = '.pdf';
            else if (requiredFormat && (requiredFormat.toLowerCase() === 'xlsx' || requiredFormat.toLowerCase() === 'excel')) fileInput.accept = '.xls,.xlsx';
            else if (requiredFormat && requiredFormat.toLowerCase() === 'image') fileInput.accept = '.pdf,.jpg,.jpeg,.png';
            else fileInput.accept = '';

            fileInput.value = '';
            filePreview.classList.remove('visible');
            dropArea.style.display = 'block';
            modal.classList.add('active');

            // Re-init icons inside modal
            setTimeout(() => lucide.createIcons(), 50);
        }

        function closeUploadModal() {
            modal.classList.remove('active');
        }

        // Close on backdrop click
        modal.addEventListener('click', function(e) {
            if (e.target === modal) closeUploadModal();
        });

        // Close on Escape key
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

        // Drag and drop
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

        // Staggered card entrance animations
        document.querySelectorAll('.animate-in').forEach((el, i) => {
            el.style.animationDelay = (i * 0.06) + 's';
        });
    </script>
    <!-- History Panels -->
    <?php foreach ($checklist_items as $hist_item):
        $hist = $uploads[$hist_item['item_id']]['history'] ?? [];
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
    <?php endforeach; ?>
    <div id="history-backdrop" class="history-backdrop" onclick="closeHistoryPanel()"></div>

    <script>window.CSRF_TOKEN = '<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>';</script>
    <script src="../assets/js/dashboard-cards.js"></script>

</body>
</html>
