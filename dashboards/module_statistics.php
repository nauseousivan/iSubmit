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

$stats_status = $stats_data['status'] ?? 'Pending Initial Data';
$control_no = $stats_data['formatted_control_no'] ?? '';
$remarks = $stats_data['statistician_remarks'] ?? '';
$result_file = $stats_data['result_file'] ?? '';

// We will fetch checklist_items for form_id = 3
$checklist_stmt = $pdo->prepare("SELECT * FROM checklist_items WHERE form_id = 3 ORDER BY item_id ASC");
$checklist_stmt->execute();
$checklist_items = $checklist_stmt->fetchAll();

// We also need the user's uploads for these items
$uploads_stmt = $pdo->prepare("SELECT item_id, file_path, verification_status, remarks, uploaded_at FROM uploads WHERE user_id = ?");
$uploads_stmt->execute([$effective_user_id]);
$uploads = [];
while ($row = $uploads_stmt->fetch()) {
    $uploads[$row['item_id']] = $row;
}

// Determine current step (1-4)
$current_step = 1;
if (in_array($stats_status, ['Initial Data Uploaded'])) { $current_step = 1; }
elseif (in_array($stats_status, ['Waiting for Payment'])) { $current_step = 2; }
elseif (in_array($stats_status, ['Payment Acknowledged', 'Requirements Uploaded', 'Under Review'])) { $current_step = 3; }
elseif ($stats_status === 'Completed') { $current_step = 4; }

// Count uploaded deliverables for step 3
$deliverable_count = 0;
foreach ([31,32,33,34,35] as $did) {
    if (isset($uploads[$did])) $deliverable_count++;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Statistical Treatment Module</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=JetBrains+Mono:wght@400;500;700&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        :root, body.theme-default, body.theme-blue {
            --teal: #1e40af;
            --teal-light: #172554;
            --teal-glow: rgba(30, 64, 175, 0.12);
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
            --bg: #f3f7fa;
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
            padding: 36px 28px 60px;
        }

        /* ── Hero Header ────────────────────────────── */
        .hero-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 20px;
            margin-bottom: 32px;
            flex-wrap: wrap;
        }

        .hero-header .hero-text h1 {
            font-size: 26px;
            font-weight: 800;
            color: var(--teal);
            letter-spacing: -0.5px;
            line-height: 1.2;
        }

        .hero-header .hero-text p {
            font-size: 14px;
            color: var(--text-secondary);
            margin-top: 6px;
            line-height: 1.5;
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
            margin-bottom: 36px;
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

        /* ── Current Step Alert Card ───────────────── */
        .step-alert {
            background: var(--bg-card);
            backdrop-filter: blur(12px);
            border: 1.5px solid var(--border);
            border-radius: var(--radius);
            padding: 24px;
            margin-bottom: 32px;
            display: flex;
            gap: 18px;
            align-items: flex-start;
            box-shadow: var(--shadow-md);
            position: relative;
            overflow: hidden;
        }

        .step-alert::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 4px;
            height: 100%;
            border-radius: 4px 0 0 4px;
        }

        .step-alert.info::before { background: var(--sky); }
        .step-alert.warning::before { background: var(--amber); }
        .step-alert.success::before { background: var(--emerald); }
        .step-alert.gold::before { background: var(--gold); }

        .step-alert-icon {
            width: 44px;
            height: 44px;
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
            font-size: 16px;
            font-weight: 700;
            color: var(--teal);
            margin-bottom: 6px;
        }

        .step-alert-body p {
            font-size: 13.5px;
            color: var(--text-secondary);
            line-height: 1.6;
        }

        .step-alert-body p strong { color: var(--teal); }

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

        .items-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 18px;
            margin-bottom: 36px;
        }

        .item-card {
            background: var(--bg-card);
            backdrop-filter: blur(8px);
            border: 1.5px solid var(--border);
            border-radius: 24px;
            padding: 22px;
            display: flex;
            flex-direction: column;
            box-shadow: 0 4px 14px rgba(0,0,0,0.06);
            transition: all 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            position: relative;
            overflow: hidden;
        }

        .item-card::after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: linear-gradient(90deg, var(--teal), var(--gold));
            opacity: 0;
            transition: opacity 0.3s;
        }

        .item-card:hover {
            transform: scale(1.02) translateY(-4px);
            box-shadow: 0 12px 30px rgba(0,0,0,0.08), var(--shadow-glow);
            border-color: var(--teal-glow);
        }

        .item-card:hover::after { opacity: 1; }

        .item-card.locked {
            opacity: 0.45;
            pointer-events: none;
            filter: grayscale(0.3);
        }

        .item-card.locked::after { display: none; }

        .card-top {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            margin-bottom: 14px;
        }

        .card-icon {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .card-icon.spreadsheet { background: var(--emerald-soft); color: var(--emerald); }
        .card-icon.document { background: var(--sky-soft); color: var(--sky); }
        .card-icon.locked-icon { background: #f3f4f6; color: #9ca3af; }

        .status-chip {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 10px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .status-chip.pending { background: #f3f4f6; color: #6b7280; }
        .status-chip.review { background: var(--amber-soft); color: var(--amber); border: 1px solid rgba(217, 119, 6, 0.15); }
        .status-chip.approved { background: var(--emerald-soft); color: var(--emerald); border: 1px solid rgba(5, 150, 105, 0.15); }
        .status-chip.revision { background: var(--rose-soft); color: var(--rose); border: 1px solid rgba(225, 29, 72, 0.1); }

        .item-title {
            font-size: 14.5px;
            font-weight: 700;
            color: var(--teal);
            margin-bottom: 6px;
            line-height: 1.3;
        }

        .item-desc {
            font-size: 12.5px;
            color: var(--text-secondary);
            line-height: 1.5;
            flex-grow: 1;
            margin-bottom: 16px;
        }

        .uploaded-file-row {
            display: flex;
            align-items: center;
            gap: 10px;
            background: var(--sky-soft);
            border: 1px solid rgba(14, 165, 233, 0.15);
            border-radius: var(--radius-sm);
            padding: 10px 14px;
            margin-bottom: 10px;
        }

        .uploaded-file-row a {
            color: var(--teal);
            text-decoration: none;
            font-weight: 600;
            font-size: 12.5px;
            flex: 1;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .uploaded-file-row a:hover { text-decoration: underline; }

        .file-remarks {
            padding: 8px 12px;
            background: var(--rose-soft);
            border-radius: 8px;
            font-size: 11.5px;
            color: #9f1239;
            margin-bottom: 10px;
            line-height: 1.4;
            border-left: 3px solid var(--rose);
        }

        .btn-card {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            width: 100%;
            padding: 11px 16px;
            border-radius: var(--radius-sm);
            font-family: var(--font);
            font-size: 12.5px;
            font-weight: 700;
            cursor: pointer;
            border: none;
            text-decoration: none;
            text-align: center;
            transition: all 0.25s ease;
            margin-top: auto;
        }

        .btn-card.upload {
            background: linear-gradient(135deg, var(--sky), #2563eb);
            color: white;
            box-shadow: 0 2px 8px rgba(14, 165, 233, 0.25);
        }
        .btn-card.upload:hover { transform: translateY(-1px); box-shadow: 0 4px 16px rgba(14, 165, 233, 0.35); }

        .btn-card.reupload {
            background: white;
            color: var(--teal);
            border: 1.5px solid var(--border);
        }
        .btn-card.reupload:hover { background: #faf9f6; border-color: var(--teal); }

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
            z-index: 1000;
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
            .items-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <div class="page-wrapper">
        <!-- Hero Header -->
        <div class="hero-header animate-in">
            <div class="hero-text">
                <h1>Statistical Treatment Module</h1>
                <p>Follow the 4-step consultation pipeline to process your research data with the Statistician.</p>
            </div>
            <?php if ($control_no): ?>
                <div class="control-no-chip">
                    <i data-lucide="hash" style="width:14px; height:14px;"></i>
                    <?= htmlspecialchars($control_no) ?>
                </div>
            <?php endif; ?>
        </div>

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
                ['num' => 2, 'label' => 'Finance Payment', 'icon' => 'credit-card'],
                ['num' => 3, 'label' => 'Deliverables', 'icon' => 'file-stack'],
                ['num' => 4, 'label' => 'Completed', 'icon' => 'award'],
            ];
            ?>
            <div class="progress-line" style="width: calc(<?= $step_progress ?>% * 0.85);"></div>
            <?php foreach ($steps as $s): 
                $state = '';
                if ($s['num'] < $current_step || ($s['num'] == $current_step && $stats_status === 'Completed')) $state = 'completed';
                elseif ($s['num'] == $current_step && $stats_status !== 'Completed') $state = 'active';
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
        <?php if ($stats_status === 'Pending Initial Data' || $stats_status === 'Initial Data Rejected'): ?>
            <div class="step-alert warning animate-in delay-2">
                <div class="step-alert-icon">
                    <i data-lucide="alert-circle" style="width:22px; height:22px;"></i>
                </div>
                <div class="step-alert-body">
                    <h3>Step 1: Initial Coded Data Verification</h3>
                    <p>Upload your <strong>Initial Coded Data (Excel)</strong> for the Statistician to verify. Once approved, you'll be directed to proceed with the Finance Office for payment.</p>
                    <?php if ($remarks): ?>
                        <div class="remarks-inline">
                            <strong>Statistician Remarks:</strong> <?= nl2br(htmlspecialchars($remarks)) ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php elseif ($stats_status === 'Initial Data Uploaded'): ?>
            <div class="step-alert info animate-in delay-2">
                <div class="step-alert-icon">
                    <i data-lucide="clock" style="width:22px; height:22px;"></i>
                </div>
                <div class="step-alert-body">
                    <h3>Step 1: Data Under Verification</h3>
                    <p>Your initial coded data has been submitted and is currently <strong>being verified</strong> by the Research Statistician. You will receive a notification once verified.</p>
                </div>
            </div>
        <?php elseif ($stats_status === 'Waiting for Payment'): ?>
            <div class="step-alert gold animate-in delay-2">
                <div class="step-alert-icon">
                    <i data-lucide="wallet" style="width:22px; height:22px;"></i>
                </div>
                <div class="step-alert-body">
                    <h3>Step 2: Proceed to Finance Office</h3>
                    <p>Your initial data has been <strong>approved</strong>! Please follow these steps:<br><br>
                    1. Download the <strong>Statistical Treatment Form</strong> below.<br>
                    2. Print it and proceed to the Finance Office to pay the processing fee (₱200/head).<br>
                    3. Submit the physically cut form to the Research Office.<br><br>
                    <em>The module will unlock Step 3 once the Statistician acknowledges your payment.</em></p>
                    <div style="display: flex; gap: 10px; margin-top: 14px; flex-wrap: wrap;">
                        <a href="#" onclick="alert('Downloading form...'); return false;" class="btn-inline outline">
                            <i data-lucide="download" style="width:15px; height:15px;"></i> Download Form
                        </a>
                    </div>
                </div>
            </div>
        <?php elseif (in_array($stats_status, ['Payment Acknowledged', 'Requirements Uploaded', 'Under Review'])): ?>
            <div class="step-alert success animate-in delay-2">
                <div class="step-alert-icon">
                    <i data-lucide="check-circle-2" style="width:22px; height:22px;"></i>
                </div>
                <div class="step-alert-body">
                    <h3>Step 3: Upload Required Deliverables</h3>
                    <p>Payment verified! You may now upload the <strong>5 required deliverables</strong> below.
                    <?php if ($control_no): ?>
                        Your Control Number is: <strong style="font-family: var(--mono);"><?= htmlspecialchars($control_no) ?></strong>
                    <?php endif; ?>
                    </p>
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
        <?php elseif ($stats_status === 'Completed'): ?>
            <div class="completion-card animate-in delay-2">
                <div class="completion-icon">
                    <i data-lucide="award" style="width:30px; height:30px;"></i>
                </div>
                <h3>Statistical Treatment Completed</h3>
                <p>The Statistician has finalized your data treatment.<br>You may download your processed results below.</p>
                <?php if ($result_file): ?>
                    <div style="margin-top: 16px;">
                        <a href="<?= htmlspecialchars($result_file) ?>" target="_blank" class="btn-inline primary">
                            <i data-lucide="download" style="width:16px; height:16px;"></i> Download Final Results
                        </a>
                    </div>
                <?php endif; ?>
                <?php if ($remarks): ?>
                    <div class="remarks-inline" style="text-align: left; max-width: 500px; margin: 16px auto 0; background: rgba(5, 150, 105, 0.08); color: #065f46; border-left-color: var(--emerald);">
                        <strong>Statistician Remarks:</strong> <?= nl2br(htmlspecialchars($remarks)) ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- Deliverable Cards Grid -->
        <?php if (count($checklist_items) > 0): ?>
            <div class="section-heading animate-in delay-3">
                <i data-lucide="folder-open" style="width:20px; height:20px; color: var(--teal);"></i>
                <h2>Required Deliverables</h2>
                <span class="count-pill"><?= count($checklist_items) ?></span>
            </div>

            <div class="items-grid">
                <?php 
                $card_index = 0;
                foreach ($checklist_items as $item): 
                    $is_initial_data = ($item['item_id'] == 30);
                    
                    // Determine if card is locked
                    $is_locked = false;
                    if ($is_initial_data) {
                        if (!in_array($stats_status, ['Pending Initial Data', 'Initial Data Rejected'])) {
                            $is_locked = true;
                        }
                    } else {
                        if (!in_array($stats_status, ['Payment Acknowledged', 'Requirements Uploaded', 'Under Review'])) {
                            $is_locked = true;
                        }
                    }
                    
                    $upload_data = $uploads[$item['item_id']] ?? null;
                    $card_status = 'pending';
                    $status_text = 'Awaiting';
                    $status_icon = 'circle-dashed';
                    
                    if ($upload_data) {
                        $vs = strtolower($upload_data['verification_status'] ?? 'pending');
                        if ($vs == 'pending') {
                            $card_status = 'review';
                            $status_text = 'Under Review';
                            $status_icon = 'clock';
                        } elseif ($vs == 'approved') {
                            $card_status = 'approved';
                            $status_text = 'Approved';
                            $status_icon = 'check-circle-2';
                        } elseif ($vs == 'revision requested') {
                            $card_status = 'revision';
                            $status_text = 'Revision Needed';
                            $status_icon = 'alert-triangle';
                        }
                    }

                    $is_pdf = ($item['required_format'] ?? '') === 'PDF';
                    $icon_type = $is_pdf ? 'document' : 'spreadsheet';
                    $lucide_icon = $is_pdf ? 'file-text' : 'file-spreadsheet';
                    if ($is_locked) $icon_type = 'locked-icon';
                    
                    $card_index++;
                ?>
                    <div class="item-card <?= $is_locked ? 'locked' : '' ?> animate-in delay-<?= min($card_index + 3, 6) ?>">
                        <div class="card-top">
                            <div class="card-icon <?= $icon_type ?>">
                                <?php if ($is_locked): ?>
                                    <i data-lucide="lock" style="width:20px; height:20px;"></i>
                                <?php else: ?>
                                    <i data-lucide="<?= $lucide_icon ?>" style="width:20px; height:20px;"></i>
                                <?php endif; ?>
                            </div>
                            <?php if ($upload_data): ?>
                                <div class="status-chip <?= $card_status ?>">
                                    <i data-lucide="<?= $status_icon ?>" style="width:10px; height:10px;"></i>
                                    <?= $status_text ?>
                                </div>
                            <?php elseif (!$is_locked): ?>
                                <div class="status-chip pending">
                                    <i data-lucide="circle-dashed" style="width:10px; height:10px;"></i>
                                    Awaiting
                                </div>
                            <?php endif; ?>
                        </div>

                        <h3 class="item-title"><?= htmlspecialchars($item['item_name']) ?></h3>
                        <p class="item-desc"><?= htmlspecialchars($item['description']) ?></p>

                        <?php if ($upload_data): ?>
                            <div class="uploaded-file-row">
                                <i data-lucide="file-check" style="width:16px; height:16px; color: var(--sky); flex-shrink: 0;"></i>
                                <a href="<?= htmlspecialchars($upload_data['file_path']) ?>" target="_blank">View Uploaded File</a>
                                <i data-lucide="external-link" style="width:12px; height:12px; color: var(--text-secondary);"></i>
                            </div>
                            <?php if ($upload_data['remarks']): ?>
                                <div class="file-remarks">
                                    <strong>Remarks:</strong> <?= htmlspecialchars($upload_data['remarks']) ?>
                                </div>
                            <?php endif; ?>
                            <?php if (!$is_locked && $card_status !== 'approved'): ?>
                                <button class="btn-card reupload" onclick="openUploadModal(<?= $item['item_id'] ?>, '<?= htmlspecialchars($item['item_name'], ENT_QUOTES) ?>', '<?= $item['required_file_type'] ?>')">
                                    <i data-lucide="refresh-cw" style="width:14px; height:14px;"></i> Re-upload
                                </button>
                            <?php endif; ?>
                        <?php else: ?>
                            <button class="btn-card upload" onclick="openUploadModal(<?= $item['item_id'] ?>, '<?= htmlspecialchars($item['item_name'], ENT_QUOTES) ?>', '<?= $item['required_file_type'] ?>')">
                                <i data-lucide="upload" style="width:14px; height:14px;"></i> Upload <?= htmlspecialchars($item['required_format']) ?>
                            </button>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
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
            document.getElementById('modalFormat').textContent = requiredFormat.toUpperCase();

            if (requiredFormat === 'pdf') fileInput.accept = '.pdf';
            else if (requiredFormat === 'xlsx') fileInput.accept = '.xls,.xlsx';
            else fileInput.accept = '.*';

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
</body>
</html>
