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
    $stmt = $pdo->prepare("SELECT verification_status, remarks, file_path, original_filename, uploaded_at, form_008_data, form_008_score, form_008_decision FROM uploads WHERE user_id = ? AND item_id = ? ORDER BY uploaded_at DESC LIMIT 1");
    $stmt->execute([$effective_user_id, $item['item_id']]);
    $latest_upload = $stmt->fetch();

    // Full upload history for the timeline panel (read-only)
    $hist_stmt = $pdo->prepare("SELECT upload_id, verification_status, remarks, file_path, original_filename, uploaded_at FROM uploads WHERE user_id = ? AND item_id = ? ORDER BY uploaded_at DESC");
    $hist_stmt->execute([$effective_user_id, $item['item_id']]);
    $upload_history = $hist_stmt->fetchAll();

    $item_statuses[$item['item_id']] = [
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
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap');

        body {
            font-family: 'Inter', sans-serif;
            margin: 0;
            padding: 20px;
            background: #ffffff;
            color: #1f2937;
            padding-top: 10px;
        }

        * {
            box-sizing: border-box;
        }

        /* Shared Styles (Progress Widget) */
        .mobile-stack-hint {
            display: none;
        }

        .hero-widgets {
            display: flex;
            flex-direction: row;
            justify-content: flex-start;
            margin-bottom: 24px;
        }

        .widget-card {
            background: white;
            border-radius: 20px;
            padding: 16px;
            text-align: center;
            box-shadow: 0 8px 16px -4px rgba(0, 0, 0, 0.05);
            border: 1px solid #f3f4f6;
            width: 140px;
            height: 140px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }

        .circular-chart {
            display: block;
            margin: 0 auto;
            max-width: 60px;
            max-height: 60px;
        }

        .circle-bg {
            fill: none;
            stroke: #e2e8f0;
            stroke-width: 3.5;
        }

        .circle {
            fill: none;
            stroke-width: 3.5;
            stroke-linecap: round;
            animation: progress 1s ease-out forwards;
        }

        @keyframes progress {
            0% {
                stroke-dasharray: 0 100;
            }
        }

        .percentage {
            fill: #1e293b;
            font-family: 'Inter', sans-serif;
            font-size: 8px;
            font-weight: 800;
            text-anchor: middle;
            dominant-baseline: central;
        }

        .widget-label {
            font-size: 11px;
            font-weight: 700;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            margin-top: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
        }

        .widget-value {
            font-size: 18px;
            font-weight: 800;
            color: #0f172a;
            margin: 4px 0 2px 0;
        }

        .widget-subtext {
            font-size: 11px;
            color: #64748b;
            margin: 0;
        }

        /* Buttons and Shared Form Elements */
        .status-pill {
            display: inline-flex;
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            padding: 6px 14px;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        .status-pill.review {
            background: #fffbeb;
            color: #b45309;
            border: 1px solid #fde68a;
        }

        .status-pill.approved {
            background: #ecfdf5;
            color: #047857;
            border: 1px solid #a7f3d0;
        }

        .status-pill.revision {
            background: #fef2f2;
            color: #b91c1c;
            border: 1px solid #fecaca;
        }

        .status-pill.pending {
            background: #f3f4f6;
            color: #4b5563;
            border: 1px solid #e5e7eb;
        }

        .status-pill.no-upload {
            background: #f3e8ff;
            color: #7e22ce;
            border: 1px solid #d8b4fe;
        }

        .instruction-box {
            background: #f0fdf4;
            color: #166534;
            padding: 16px;
            border-radius: 12px;
            font-size: 13px;
            line-height: 1.5;
            margin-bottom: 20px;
        }

        .instruction-box strong {
            font-weight: 700;
        }

        .btn {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            width: 100%;
            padding: 16px;
            border-radius: 14px;
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
            border: none;
            text-decoration: none;
            font-family: 'Inter', sans-serif;
            transition: opacity 0.2s;
        }

        .btn:active {
            opacity: 0.8;
        }

        .btn-primary {
            background: #0f172a;
            color: white;
        }

        .btn-outline {
            background: transparent;
            color: #0f172a;
            border: 1.5px solid #e2e8f0;
        }

        .btn-warning {
            background: #fffbeb;
            color: #b45309;
            border: 1px solid #fde68a;
        }

        .file-attachment {
            display: flex;
            align-items: center;
            gap: 12px;
            background: #f8fafc;
            padding: 16px;
            border-radius: 12px;
            margin-top: 10px;
        }

        .file-icon {
            color: #64748b;
        }

        .file-name {
            font-size: 14px;
            font-weight: 700;
            color: #0f172a;
            display: block;
        }

        .file-sub {
            font-size: 12px;
            color: #64748b;
            display: block;
            margin-top: 2px;
        }


        /* ========================================================
           MOBILE LAYOUT (Apple Wallet Stack)
           ======================================================== */
        @media (max-width: 768px) {
            body {
                padding: 12px 10px;
                /* Reduced from 20px to make cards touch closer to the edges */
            }

            .sheet-drag-handle {
                display: none !important;
            }

            .mobile-stack-hint {
                display: flex;
                align-items: center;
                gap: 6px;
                justify-content: flex-end;
                font-size: 11px;
                font-weight: 700;
                color: #94a3b8;
                text-transform: uppercase;
                letter-spacing: 0.05em;
                margin-bottom: 12px;
                margin-top: 8px;
                margin-right: 4px;
                animation: pulseHint 2.5s infinite;
            }

            @keyframes pulseHint {

                0%,
                100% {
                    opacity: 0.5;
                }

                50% {
                    opacity: 1;
                    transform: translateY(-2px);
                }
            }

            .items-grid {
                display: flex;
                flex-direction: column;
                padding-bottom: 40px;
                position: relative;
            }

            /* Aggressively disable text selection and tap highlights on ALL elements inside the card */
            .item-card,
            .item-card * {
                -webkit-touch-callout: none;
                /* iOS Safari */
                -webkit-user-select: none;
                /* Safari */
                -moz-user-select: none;
                /* Firefox */
                -ms-user-select: none;
                /* Internet Explorer/Edge */
                user-select: none;
                /* Non-prefixed version */
                -webkit-tap-highlight-color: transparent;
                /* Android Chrome Tap Highlight */
                -webkit-tap-highlight-color: rgba(0, 0, 0, 0);
            }

            .item-card {
                touch-action: pan-y;
                /* Prevent browser handling of long-press gestures, allow scrolling */
                position: relative;
                width: 100%;
                border-radius: 24px;
                margin-top: -85px;
                /* Slight adjustment for the new size */
                /* 3D Physical Card Effect: Inner highlight borders + Halo outer shadow */
                border-top: 1px solid rgba(255, 255, 255, 0.4);
                border-left: 1px solid rgba(255, 255, 255, 0.2);
                border-right: 1px solid rgba(255, 255, 255, 0.1);
                box-shadow:
                    0 -15px 30px -10px rgba(0, 0, 0, 0.25),
                    /* Deep stacking shadow */
                    0 15px 25px -10px rgba(0, 0, 0, 0.2);
                /* Drop shadow */
                transition: all 0.45s cubic-bezier(0.2, 0.8, 0.2, 1);
                cursor: pointer;
                overflow: hidden;
            }

            /* Custom Colored Halo Shadows for each card status */
            .item-card.approved {
                box-shadow: 0 -15px 35px -10px rgba(16, 185, 129, 0.4), 0 10px 20px -5px rgba(0, 0, 0, 0.2);
            }

            .item-card.review,
            .item-card.pending {
                box-shadow: 0 -15px 35px -10px rgba(245, 158, 11, 0.4), 0 10px 20px -5px rgba(0, 0, 0, 0.2);
            }

            .item-card.revision {
                box-shadow: 0 -15px 35px -10px rgba(239, 68, 68, 0.4), 0 10px 20px -5px rgba(0, 0, 0, 0.2);
            }

            .item-card.no-upload {
                box-shadow: 0 -15px 35px -10px rgba(124, 58, 237, 0.4), 0 10px 20px -5px rgba(0, 0, 0, 0.2);
            }

            .item-card:first-child {
                margin-top: 0;
                box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            }

            .card-inner-bg {
                width: 100%;
                min-height: 140px;
                transition: all 0.3s ease;
            }

            .item-card.review .card-inner-bg,
            .item-card.pending .card-inner-bg,
            .item-card.revision .card-inner-bg {
                background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            }

            .item-card.approved .card-inner-bg {
                background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            }

            .item-card.no-upload .card-inner-bg {
                background: linear-gradient(135deg, #7c3aed 0%, #5b21b6 100%);
            }

            .card-header {
                padding: 28px 24px;
                display: flex;
                align-items: flex-start;
                gap: 16px;
                color: white;
            }

            .status-icon-box {
                width: 44px;
                height: 44px;
                border-radius: 12px;
                background: rgba(255, 255, 255, 0.2);
                display: flex;
                align-items: center;
                justify-content: center;
                flex-shrink: 0;
            }

            .card-title {
                margin: 0 0 4px 0;
                font-size: 14px;
                font-weight: 800;
                text-transform: uppercase;
                letter-spacing: 0;
                line-height: 1.2;
                /* Allow 2 lines max, but 14px should fit most on 1 line */
                display: -webkit-box;
                -webkit-line-clamp: 2;
                -webkit-box-orient: vertical;
                overflow: hidden;
            }

            .card-meta {
                margin: 0;
                font-size: 13.5px;
                color: rgba(255, 255, 255, 0.95);
                line-height: 1.4;
                display: -webkit-box;
                -webkit-line-clamp: 2;
                -webkit-box-orient: vertical;
                overflow: hidden;
            }

            body.has-active-wallet {
                overflow: hidden;
            }

            .items-grid.has-active-card .item-card:not(.wallet-active) {
                transform: translateY(100vh);
                opacity: 0;
                pointer-events: none;
            }

            .item-card.wallet-active {
                position: fixed;
                top: 5vh;
                left: 0;
                right: 0;
                bottom: 0;
                margin-top: 0;
                border-radius: 24px 24px 0 0;
                box-shadow: 0 -4px 20px rgba(0,0,0,0.05);
                z-index: 9999 !important;
                cursor: default;
                background-color: white !important;
            }

            .item-card.wallet-active .card-inner-bg {
                height: 100%;
                display: flex;
                flex-direction: column;
            }

            .item-card.wallet-active .card-header {
                padding-top: 30px;
                padding-bottom: 40px;
            }

            .item-card.wallet-active .card-meta {
                -webkit-line-clamp: unset;
            }



            .card-body {
                background: white;
                flex: 1;
                border-radius: 0;
                margin-top: -20px;
                padding: 30px 24px;
                display: none;
                overflow-y: auto;
                box-shadow: 0 -4px 15px rgba(0, 0, 0, 0.05);
            }

            .item-card.wallet-active .card-body {
                display: block;
                animation: slideBodyUp 0.4s ease forwards;
            }

            @keyframes slideBodyUp {
                from {
                    transform: translateY(40px);
                    opacity: 0;
                }

                to {
                    transform: translateY(0);
                    opacity: 1;
                }
            }
        }


        /* ========================================================
           DESKTOP LAYOUT (Grid Cards)
           ======================================================== */
        @media (min-width: 769px) {
            .sheet-drag-handle {
                display: none;
            }

            .wallet-close-btn {
                display: none !important;
            }

            .mobile-stack-hint {
                display: none;
            }

            .hero-widgets {
                flex-direction: row;
                gap: 24px;
            }

            .hero-widgets .widget-card {
                flex: 1;
            }

            .items-grid {
                display: grid;
                grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
                gap: 24px;
                margin-top: 30px;
            }

            /* Aggressively disable text selection and tap highlights on ALL elements inside the card */
            .item-card,
            .item-card * {
                -webkit-touch-callout: none;
                /* iOS Safari */
                -webkit-user-select: none;
                /* Safari */
                -moz-user-select: none;
                /* Firefox */
                -ms-user-select: none;
                /* Internet Explorer/Edge */
                user-select: none;
                /* Non-prefixed version */
                -webkit-tap-highlight-color: transparent;
                /* Android Chrome Tap Highlight */
                -webkit-tap-highlight-color: rgba(0, 0, 0, 0);
            }

            .item-card {
                touch-action: pan-y;
                /* Prevent browser handling of long-press gestures, allow scrolling */
                background: white;
                border-radius: 16px;
                border: 1px solid #e5e7eb;
                border-left: 5px solid #e5e7eb;
                box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
                overflow: hidden;
                display: flex;
                flex-direction: column;
                transition: transform 0.2s, box-shadow 0.2s;
            }

            .item-card:hover {
                transform: translateY(-4px);
                box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
            }

            .item-card.approved {
                border-left-color: #10b981;
            }

            .item-card.review,
            .item-card.pending {
                border-left-color: #f59e0b;
            }

            .item-card.revision {
                border-left-color: #ef4444;
            }

            .card-header {
                padding: 24px 24px 16px 24px;
                display: flex;
                align-items: flex-start;
                gap: 16px;
            }

            .status-icon-box {
                width: 44px;
                height: 44px;
                border-radius: 12px;
                display: flex;
                align-items: center;
                justify-content: center;
                flex-shrink: 0;
            }

            .item-card.approved .status-icon-box {
                background: #d1fae5;
                color: #047857;
            }

            .item-card.review .status-icon-box,
            .item-card.pending .status-icon-box {
                background: #fef3c7;
                color: #b45309;
            }

            .item-card.revision .status-icon-box {
                background: #fee2e2;
                color: #b91c1c;
            }

            .card-title {
                margin: 0 0 6px 0;
                font-size: 17px;
                font-weight: 800;
                color: #0f172a;
                text-transform: uppercase;
                letter-spacing: 0.02em;
            }

            .card-meta {
                margin: 0;
                font-size: 13px;
                color: #64748b;
                line-height: 1.5;
            }

            .card-body {
                padding: 0 24px 24px 24px;
                display: flex !important;
                /* Force visible on desktop */
                flex-direction: column;
                flex: 1;
            }

            /* On desktop, the status pill is redundant with the left border, but we can keep it */
            .status-pill {
                align-self: flex-start;
                margin-bottom: 16px;
            }

            .instruction-box {
                margin-bottom: 16px;
            }

            .item-card form {
                margin-top: auto;
                /* Push to bottom */
            }

            /* Remove wallet functionality on desktop */
            /* Aggressively disable text selection and tap highlights on ALL elements inside the card */
            .item-card,
            .item-card * {
                -webkit-touch-callout: none;
                /* iOS Safari */
                -webkit-user-select: none;
                /* Safari */
                -moz-user-select: none;
                /* Firefox */
                -ms-user-select: none;
                /* Internet Explorer/Edge */
                user-select: none;
                /* Non-prefixed version */
                -webkit-tap-highlight-color: transparent;
                /* Android Chrome Tap Highlight */
                -webkit-tap-highlight-color: rgba(0, 0, 0, 0);
            }

            .item-card {
                touch-action: pan-y;
                /* Prevent browser handling of long-press gestures, allow scrolling */
                cursor: default;
            }
        }

        .num-indicator {
            font-family: 'JetBrains Mono', 'Poppins', sans-serif;
            font-size: 20px;
            font-weight: 800;
            letter-spacing: -1px;
        }

        /* ---- APPLE HIG WORKFLOW STEPS ---- */
        .apple-workflow {
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            gap: 4px;
            margin-bottom: 20px;
            padding: 0;
        }
        .aw-step {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
            font-weight: 500;
            color: #334155;
        }
        .aw-step span {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 18px;
            height: 18px;
            background: #e2e8f0;
            color: #475569;
            border-radius: 50%;
            font-size: 10px;
            font-weight: 800;
        }
        .aw-arrow {
            margin-left: 5px;
            color: #94a3b8;
            font-size: 12px;
        }

        /* ---- HISTORY BUTTON ---- */
        .btn-history {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            background: transparent;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            color: #64748b;
            font-size: 12px;
            font-weight: 700;
            padding: 6px 10px;
            cursor: pointer;
            font-family: 'Inter', sans-serif;
            transition: all 0.2s;
            flex-shrink: 0;
        }
        .btn-history:active { opacity: 0.7; }

        /* ---- UPLOAD OR DIVIDER ---- */
        .upload-divider {
            display: flex;
            align-items: center;
            gap: 10px;
            margin: 10px 0;
            color: #94a3b8;
            font-size: 12px;
            font-weight: 600;
        }
        .upload-divider::before,
        .upload-divider::after {
            content: '';
            flex: 1;
            height: 1px;
            background: #e2e8f0;
        }

        /* ---- APPLE HIG FILE CARD ---- */
        .apple-file-card {
            display: flex;
            align-items: center;
            gap: 12px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            padding: 12px;
            margin-top: 14px;
            -webkit-tap-highlight-color: transparent;
        }
        .afc-thumb-wrap {
            width: 44px;
            height: 44px;
            border-radius: 10px;
            overflow: hidden;
            flex-shrink: 0;
            background: #e2e8f0;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .afc-thumb {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .afc-info {
            flex: 1;
            min-width: 0;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }
        .afc-name {
            font-size: 13px;
            font-weight: 700;
            color: #0f172a;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            margin-bottom: 2px;
        }
        .afc-meta {
            font-size: 11px;
            color: #64748b;
        }
        .afc-actions {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-shrink: 0;
        }
        .afc-btn {
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 6px 10px;
            font-size: 12px;
            font-weight: 700;
            color: #334155;
            cursor: pointer;
            transition: background 0.15s;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .afc-btn:active { background: #f1f5f9; }
        .afc-delete { color: #dc2626; padding: 6px; }

        /* ---- UPLOAD SPINNER ---- */
        .upload-spinner {
            width: 15px;
            height: 15px;
            border: 2px solid rgba(255,255,255,0.4);
            border-top-color: white;
            border-radius: 50%;
            animation: spin 0.7s linear infinite;
            display: inline-block;
            vertical-align: middle;
            flex-shrink: 0;
        }
        @keyframes spin { to { transform: rotate(360deg); } }

        /* ---- TOAST ---- */
        .toast {
            position: fixed;
            bottom: 90px;
            left: 50%;
            transform: translateX(-50%) translateY(20px);
            background: #0f172a;
            color: white;
            padding: 12px 20px;
            border-radius: 30px;
            font-size: 14px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
            opacity: 0;
            transition: opacity 0.3s ease, transform 0.3s ease;
            z-index: 99999;
            white-space: nowrap;
            box-shadow: 0 10px 30px rgba(0,0,0,0.25);
            pointer-events: none;
        }
        .toast.toast-visible { opacity: 1; transform: translateX(-50%) translateY(0); }
        .toast.toast-success { background: #059669; }
        .toast.toast-error { background: #dc2626; }

        /* ---- DOWNLOAD PREVIEW MODAL ---- */
        .dl-modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0);
            z-index: 99998;
            align-items: flex-end;
            justify-content: center;
        }
        .dl-modal-overlay.open { display: flex; }
        .dl-modal-box {
            background: white;
            border-radius: 0;
            padding: 24px 24px 36px;
            width: 100%;
            max-width: 480px;
            animation: slideUpModal 0.32s cubic-bezier(0.2,0.8,0.2,1) both;
        }
        .dl-modal-handle {
            width: 36px;
            height: 4px;
            background: #e2e8f0;
            border-radius: 2px;
            margin: 0 auto 20px;
        }
        .dl-modal-header {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 15px;
            font-weight: 800;
            color: #0f172a;
            margin-bottom: 6px;
            padding-bottom: 14px;
            border-bottom: 1px solid #e2e8f0;
        }
        @keyframes slideUpModal {
            from { transform: translateY(100%); }
            to { transform: translateY(0); }
        }

        /* ---- FULL PAGE HISTORY MODAL / SHEET ---- */
        .history-backdrop {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0);
            z-index: 99990;
        }
        .history-backdrop.visible { display: block; }
        .history-panel {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            width: 100vw;
            background: white;
            z-index: 99991;
            display: flex;
            flex-direction: column;
            transform: translateY(100%);
            transition: transform 0.35s cubic-bezier(0.2,0.8,0.2,1);
            box-shadow: 0 -8px 40px rgba(0,0,0,0.12);
            border-radius: 0;
        }
        @media (min-width: 769px) {
            .history-panel {
                width: 400px;
                left: auto;
                top: 0;
                transform: translateX(100%);
                border-radius: 0;
            }
            .history-panel.open { transform: translateX(0); }
        }
        @media (max-width: 768px) {
            .history-panel.open { transform: translateY(0); }
        }
        .history-panel-header {
            padding: 18px 20px 16px;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-shrink: 0;
        }
        .history-panel-header h3 {
            margin: 0;
            font-size: 14px;
            font-weight: 800;
            color: #0f172a;
            text-transform: uppercase;
            letter-spacing: 0.02em;
        }
        
        .history-close-btn {
            background: #f1f5f9;
            border: none;
            border-radius: 50%;
            width: 36px;
            height: 36px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            color: #64748b;
            flex-shrink: 0;
        }
        .history-panel-body {
            flex: 1;
            overflow-y: auto;
            padding: 16px;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        .history-item {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            padding: 14px 16px;
        }
        .history-item-label {
            font-size: 10px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: #94a3b8;
            margin-bottom: 6px;
        }
        .history-status-pill {
            display: inline-block;
            font-size: 10px;
            font-weight: 800;
            text-transform: uppercase;
            padding: 4px 10px;
            border-radius: 6px;
            margin-bottom: 8px;
        }
        .history-status-pill.approved { background: #ecfdf5; color: #047857; }
        .history-status-pill.revision { background: #fef2f2; color: #b91c1c; }
        .history-status-pill.review { background: #fffbeb; color: #b45309; }
        .history-filename {
            font-size: 13px;
            font-weight: 700;
            color: #0f172a;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            margin-bottom: 3px;
        }
        .history-date { font-size: 12px; color: #64748b; }
        .history-remarks {
            margin-top: 8px;
            font-size: 12px;
            color: #92400e;
            background: #fffbeb;
            border-left: 3px solid #f59e0b;
            padding: 8px 10px;
            border-radius: 6px;
            font-style: italic;
        }

        /* ---- DARK THEME — new components ---- */
        body.theme-dark .workflow-steps { background: rgba(255,255,255,0.03); border-color: rgba(255,255,255,0.06); }
        body.theme-dark .step-num { background: rgba(255,255,255,0.15); }
        body.theme-dark .step-text { color: #94a3b8; }
        body.theme-dark .step-arrow { color: #334155; }
        body.theme-dark .btn-history { color: #64748b; border-color: rgba(255,255,255,0.1); }
        body.theme-dark .submission-card { background: rgba(255,255,255,0.04); border-color: rgba(255,255,255,0.06); }
        body.theme-dark .submission-card:active { background: rgba(255,255,255,0.08); }
        body.theme-dark .file-thumb-doc { background: rgba(255,255,255,0.05); }
        body.theme-dark .upload-divider { color: #475569; }
        body.theme-dark .upload-divider::before,
        body.theme-dark .upload-divider::after { background: rgba(255,255,255,0.08); }
        body.theme-dark .history-panel { background: #1e293b; }
        body.theme-dark .history-panel-header { border-color: rgba(255,255,255,0.06); }
        body.theme-dark .history-panel-header h3 { color: #f8fafc; }
        body.theme-dark .history-close-btn { background: rgba(255,255,255,0.08); color: #94a3b8; }
        @media (max-width: 768px) { body.theme-dark .history-close-btn { display: none !important; } }
        body.theme-dark .history-item { background: #0f172a; border-color: rgba(255,255,255,0.05); }
        body.theme-dark .history-filename { color: #f1f5f9; }
        body.theme-dark .dl-modal-box { background: #1e293b; }
        body.theme-dark .dl-modal-header { color: #f1f5f9; border-color: rgba(255,255,255,0.06); }
    </style>
    <script src="https://unpkg.com/lucide@latest"></script>
    <script>
        document.addEventListener("DOMContentLoaded", function() {
            const savedTheme = localStorage.getItem('rd-portal-theme');
            if (savedTheme) {
                document.body.className = savedTheme;
            }
        });
        window.addEventListener('storage', function(e) {
            if (e.key === 'rd-portal-theme' && e.newValue) {
                document.body.className = e.newValue;
            }
        });
    </script>
    <style>
        body.theme-dark {
            background: #0f172a !important;
            color: #f8fafc;
        }

        body.theme-dark .card-body,
        body.theme-dark .item-card.wallet-active .card-body {
            background: #1e293b !important;
            color: #f1f5f9;
        }

        body.theme-dark .item-card {
            background: #1e293b !important;
            border-color: rgba(255, 255, 255, 0.06);
            color: #f8fafc;
        }

        body.theme-dark .card-meta,
        body.theme-dark .card-description {
            color: #94a3b8;
        }

        body.theme-dark .wallet-close-btn {
            background: rgba(255, 255, 255, 0.1);
            color: #f1f5f9;
        }

        body.theme-dark .mobile-stack-hint {
            color: #cbd5e1;
            background: rgba(255, 255, 255, 0.05);
        }

        body.theme-dark .card-title {
            color: #f8fafc;
        }

        body.theme-dark .status-pill {
            background: #0f172a;
            border-color: rgba(255, 255, 255, 0.1);
        }

        body.theme-dark .upload-btn {
            background: rgba(255, 255, 255, 0.1);
            color: #f8fafc;
        }

        body.theme-dark .upload-btn:hover {
            background: rgba(255, 255, 255, 0.2);
        }

        body.theme-dark .feedback-box {
            background: rgba(22, 101, 52, 0.2) !important;
        }

        body.theme-dark .feedback-box p {
            color: #4ade80 !important;
        }

        /* Other theme colors */
        body.theme-pink,
        body.theme-rose {
            background: #fde8f5 !important;
        }

        body.theme-green {
            background: #e8f6ea !important;
        }

        body.theme-orange,
        body.theme-amber {
            background: #fffbeb !important;
        }

        body.theme-purple,
        body.theme-lavender {
            background: #ffffff !important;
        }
    </style>
</head>

<body>


    <?php
    $total_items = count($checklist_items);
    $approved_items = 0;
    foreach ($item_statuses as $s) {
        if ($s['status'] === 'Approved') $approved_items++;
    }
    $progress_pct = $total_items > 0 ? round(($approved_items / $total_items) * 100) : 0;
    $dash_array = "$progress_pct, 100";
    $stroke_color = '#3b82f6'; // Always blue like screenshot
    ?>

    <div class="hero-widgets">
        <!-- Progress Widget -->
        <div class="widget-card">
            <svg viewBox="0 0 36 36" class="circular-chart">
                <path class="circle-bg" d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" />
                <path class="circle" stroke="<?= $stroke_color ?>" stroke-dasharray="<?= $dash_array ?>" d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" />
                <text x="18" y="21" class="percentage"><?= $progress_pct ?>%</text>
            </svg>
            <div class="widget-label"><i data-lucide="target" style="width:14px;height:14px;"></i> PROGRESS</div>
            <div class="widget-value"><?= $approved_items ?> / <?= $total_items ?></div>
            <p class="widget-subtext">Reqs Cleared</p>
        </div>

        <?php if ($overall_prop_status === 'Approved'): ?>
        <!-- Premium Institutional Clearance Widget -->
        <div class="widget-card clearance-widget" style="flex: 1; margin-left: 16px; border: 1px solid rgba(16, 185, 129, 0.3); box-shadow: 0 8px 24px rgba(16, 185, 129, 0.15); justify-content: space-between; padding: 16px 12px; position: relative; overflow: hidden;">
            <div style="position: absolute; top: -20px; right: -20px; width: 60px; height: 60px; background: radial-gradient(circle, rgba(16, 185, 129, 0.15) 0%, transparent 70%); border-radius: 50%;"></div>
            
            <div style="display:flex; flex-direction:column; align-items:center; gap: 4px; z-index: 1;">
                <i data-lucide="trophy" style="width:28px;height:28px;color:#10b981; filter: drop-shadow(0 2px 4px rgba(16,185,129,0.3));"></i>
                <strong style="color:#064e3b; font-size:12px; font-family:'Inter', sans-serif; line-height:1.2; margin-top:2px; text-align:center;">Institutional<br>Clearance</strong>
            </div>
            
            <button onclick="openDownloadModal('../proposal_cleared.pdf', 'Proposal Clearing Form')" class="btn premium-download-btn" style="width:100%; background:linear-gradient(135deg, #10b981 0%, #059669 100%); color:white; border:none; padding: 8px 0; font-weight:600; font-family:'Inter', sans-serif; font-size: 11px; border-radius: 8px; box-shadow: 0 4px 12px rgba(16,185,129,0.2); transition: all 0.2s ease; z-index: 1; margin-top: auto;">
                <i data-lucide="download" style="width:14px;height:14px;margin-right:4px;"></i> Download
            </button>
        </div>
        <?php endif; ?>
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
        ?>
            <div class="item-card <?= $status_class ?>" onclick="expandWalletCard(this, event)" style="z-index: <?= $card_index ?>;">
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
                            $sub_is_img = in_array($sub_ext, ['jpg','jpeg','png','gif','webp']);
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
                                <button type="button" class="afc-btn afc-delete" onclick="deleteUpload(<?= $latest_upload['upload_id'] ?? 0 ?>, 'proposal'); event.stopPropagation();">
                                    <i data-lucide="trash-2" style="width:14px;height:14px;"></i>
                                </button>
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
                <i data-lucide="file" style="width:18px;height:18px;color:#64748b;"></i>
                <span id="dm-name">Document</span>
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
            <?php foreach ($hist as $hi => $hu):
                $hu_st = $hu['verification_status'];
                if ($hu_st === 'Approved') $hu_sc = 'approved';
                elseif ($hu_st === 'Revision Requested') $hu_sc = 'revision';
                else $hu_sc = 'review';
                if ($hi === 0) $hu_label = 'Current Submission';
                elseif ($hi === 1) $hu_label = 'Previous Submission';
                else $hu_label = 'Older Submission';
                $hu_date = $hu['uploaded_at'] ? date('M j, Y \a\t g:i A', strtotime($hu['uploaded_at'])) : '';
            ?>
            <div class="history-item">
                <div style="display:flex; justify-content:space-between; align-items:flex-start;">
                    <div>
                        <div class="history-item-label"><?= $hu_label ?></div>
                        <div class="history-status-pill <?= $hu_sc ?>"><?= htmlspecialchars($hu_st) ?></div>
                    </div>
                    <button type="button" class="afc-btn afc-delete" style="padding:4px;" onclick="deleteUpload(<?= $hu['upload_id'] ?>, 'proposal'); event.stopPropagation();">
                        <i data-lucide="trash-2" style="width:14px;height:14px;"></i>
                    </button>
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
        lucide.createIcons();

        // Toast
        (function() {
            const toast = document.getElementById('upload-toast');
            if (!toast) return;
            setTimeout(() => toast.classList.add('toast-visible'), 120);
            setTimeout(() => toast.classList.remove('toast-visible'), 2700);
        })();

        // Download preview modal
        function openDownloadModal(url, name) {
            document.getElementById('dm-name').textContent = name;
            document.getElementById('dm-preview-btn').onclick = function() { window.open(url, '_blank'); };
            document.getElementById('dm-download-btn').onclick = function() {
                const a = document.createElement('a');
                a.href = url; a.download = ''; document.body.appendChild(a); a.click(); document.body.removeChild(a);
            };
            document.getElementById('download-modal').classList.add('open');
            lucide.createIcons();
            try { history.pushState({ layer: 'download' }, '', ''); } catch(e) {}
        }
        function closeDlModal(fromPopstate = false) { 
            document.getElementById('download-modal').classList.remove('open'); 
            if (!fromPopstate) {
                history.back();
            }
        }

        // Camera / file picker
        function triggerCamera(itemId) {
            const input = document.getElementById('file-input-' + itemId);
            input.setAttribute('accept', 'image/*');
            input.setAttribute('capture', 'environment');
            input.click();
        }
        function triggerFilePicker(itemId) {
            const input = document.getElementById('file-input-' + itemId);
            input.setAttribute('accept', 'image/*,.jpg,.jpeg,.png,.pdf,.doc,.docx');
            input.removeAttribute('capture');
            input.click();
        }
        function handleFileChange(input) {
            if (!input.files || !input.files.length) return;
            const form = input.closest('form');
            form.querySelectorAll('button').forEach(b => {
                b.disabled = true;
                b.style.opacity = '0.65';
                b.style.pointerEvents = 'none';
            });
            const primary = form.querySelector('.btn-primary');
            if (primary) primary.innerHTML = '<span class="upload-spinner"></span> Uploading...';
            form.submit();
        }
        function handleUploadStart(form) {
            form.querySelectorAll('button').forEach(b => { b.disabled = true; });
        }

                // Delete Upload Action
        function deleteUpload(uploadId, context) {
            if (!confirm('Are you sure you want to delete this file?')) return;
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'upload_handler.php';
            
            const actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'action';
            actionInput.value = 'delete_upload';
            form.appendChild(actionInput);
            
            const idInput = document.createElement('input');
            idInput.type = 'hidden';
            idInput.name = 'upload_id';
            idInput.value = uploadId;
            form.appendChild(idInput);
            
            const contextInput = document.createElement('input');
            contextInput.type = 'hidden';
            contextInput.name = 'module_context';
            contextInput.value = context;
            form.appendChild(contextInput);
            
            document.body.appendChild(form);
            form.submit();
        }

        // History panel
        function openHistoryPanel(itemId) {
            const panel = document.getElementById('history-panel-' + itemId);
            const backdrop = document.getElementById('history-backdrop');
            if (!panel) return;
            panel.classList.add('open');
            backdrop.classList.add('visible');
            document.body.style.overflow = 'hidden';
            lucide.createIcons();
            
            try {
                history.pushState({ layer: 'history' }, '', '');
            } catch(e) {}
        }
        function closeHistoryPanel(fromPopstate = false) {
            document.querySelectorAll('.history-panel.open').forEach(p => p.classList.remove('open'));
            const backdrop = document.getElementById('history-backdrop');
            if (backdrop) backdrop.classList.remove('visible');
            if (!document.querySelector('.wallet-active')) {
                document.body.style.overflow = '';
            }
            if (!fromPopstate) {
                history.back();
            }
        }
        


        function expandWalletCard(cardEl, event) {
            if (window.innerWidth > 768) return;
            if (cardEl.classList.contains('wallet-active')) return;
            // Calculate exact position for seamless FLIP animation
            const grid = document.querySelector('.items-grid');
            cardEl.style.transition = 'none';
            cardEl.style.transform = 'translateY(100vh)';
            
            grid.classList.add('has-active-card');
            document.body.classList.add('has-active-wallet');
            cardEl.classList.add('wallet-active');

            // Force reflow
            void cardEl.offsetWidth;

            cardEl.style.transition = 'transform 0.35s cubic-bezier(0.2,0.8,0.2,1)';
            cardEl.style.transform = 'translateY(0)';

            // PUSH STATE LOGIC for back button
            try {
                const correctHash = parent.document.querySelector('.fullscreen-zoom-overlay.active').id.replace('zoom-', '');
                history.pushState({ walletOpen: true }, '', '#' + correctHash);
            } catch (e) {
                history.pushState({ walletOpen: true }, '', '');
            }
        }



        // Consolidated popstate listener for back button navigation
        window.addEventListener('popstate', function(event) {
            // Priority 0: Form008 Modal
            const form008Modal = document.getElementById('studentForm008Modal');
            if (form008Modal && form008Modal.classList.contains('open')) {
                window.closeForm008Modal(true);
                return;
            }

            // Priority 1: Download Modal
            const dlModal = document.getElementById('download-modal');
            if (dlModal && dlModal.classList.contains('open')) {
                closeDlModal(true);
                return;
            }
            
            // Priority 2: History Panel
            const openHistory = document.querySelector('.history-panel.open');
            if (openHistory) {
                closeHistoryPanel(true);
                return;
            }

            // Priority 3: Wallet Card
            const openCard = document.querySelector('.wallet-active');
            if (openCard) {
                window.collapseCard(openCard, true);
            }
        });

        window.collapseCard = function(cardEl, fromPopstate = false) {
            try {
                if (typeof window.event !== 'undefined' && window.event) {
                    window.event.stopPropagation();
                }
            } catch (e) {}
            
            // Animate card down
            cardEl.style.transition = 'transform 0.35s cubic-bezier(0.2,0.8,0.2,1)';
            cardEl.style.transform = 'translateY(100vh)';
            
            setTimeout(() => {
                const grid = document.querySelector('.items-grid');
                cardEl.classList.remove('wallet-active');
                cardEl.style.transition = '';
                cardEl.style.transform = '';
                grid.classList.remove('has-active-card');
                document.body.classList.remove('has-active-wallet');
                
                // Remove the placeholder so the card snaps perfectly back into its slot
                if (cardEl._walletPlaceholder) {
                    cardEl._walletPlaceholder.remove();
                    cardEl._walletPlaceholder = null;
                }
            }, 350);

            if (!fromPopstate) {
                history.back();
            }
        };
    </script>
    <style>
        #studentForm008Modal {
            transform: translateY(100%);
            transition: transform 0.35s cubic-bezier(0.2,0.8,0.2,1);
        }
        #studentForm008Modal.open {
            transform: translateY(0);
        }
    </style>
    <div id="studentForm008Modal" style="display:none; position:fixed; top:5vh; left:0; right:0; bottom:0; background:white; z-index:99999; flex-direction:column; border-radius:24px 24px 0 0; box-shadow:0 -4px 20px rgba(0,0,0,0.05); overflow:hidden;">
        <div style="background:white; width:100%; height:100%; display:flex; flex-direction:column; overflow:hidden;">
            <div style="background:#0f172a; color:white; padding:20px 25px; display:flex; justify-content:space-between; align-items:center;">
                <div>
                    <span style="font-size:11px; font-family:'Inter', sans-serif; text-transform:uppercase; letter-spacing:0.05em; opacity:0.8;">Feedback Panel</span>
                    <h3 style="margin:0; font-family:'Inter', sans-serif; font-size:18px;">Form 008 Evaluation Sheet</h3>
                </div>
            </div>
            <div style="flex:1; overflow-y:auto; padding:25px; background:#f8fafc; font-family:'Inter', sans-serif;">
                <div style="display:flex; justify-content:space-between; align-items:center; background:white; padding:20px; border-radius:16px; border:1px solid #e2e8f0; margin-bottom:24px;">
                    <div>
                        <span style="font-size:12px; color:#64748b; font-weight:800; text-transform:uppercase;">Evaluated Score</span>
                        <h2 style="margin:4px 0 0 0; color:#0f172a; font-size:28px;" id="sModalScore">0 / 22</h2>
                    </div>
                    <div style="text-align:right;">
                        <span style="font-size:12px; color:#64748b; font-weight:800; text-transform:uppercase;">Overall Decision</span>
                        <h3 style="margin:4px 0 0 0; color:#d97706;" id="sModalDecision">Pending</h3>
                    </div>
                </div>
                <div id="sModalContent" style="display:flex; flex-direction:column; gap:20px;"></div>
            </div>
        </div>
    </div>

    <script>
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

        window.openStudentForm008 = function(jsonString, score, decision) {
            try {
                const data = typeof jsonString === 'string' ? JSON.parse(jsonString) : jsonString;
                document.getElementById('sModalScore').textContent = `${score} / 22`;
                const decEl = document.getElementById('sModalDecision');
                decEl.textContent = decision ? decision.toUpperCase() : "EVALUATED";
                if (score >= 15) decEl.style.color = "#059669";
                else if (score >= 8) decEl.style.color = "#d97706";
                else decEl.style.color = "#dc2626";

                const container = document.getElementById('sModalContent');
                container.innerHTML = '';

                for (const [sectionTitle, questions] of Object.entries(form008Questions)) {
                    let sectionHTML = `
                        <div style="background:white; border:1px solid #e2e8f0; border-radius:16px; padding:20px;">
                            <h4 style="margin:0 0 16px 0; color:#0f172a; font-size:15px; border-bottom:1px solid #e2e8f0; padding-bottom:12px; font-weight:800;">${sectionTitle}</h4>
                            <div style="display:flex; flex-direction:column; gap:16px;">
                    `;
                    for (const [qKey, qText] of Object.entries(questions)) {
                        const answerData = data && data[qKey] ? data[qKey] : {
                            val: "N/A",
                            comment: ""
                        };
                        let badgeStyle = "background:#f1f5f9; color:#475569;";
                        if (answerData.val === "YES") badgeStyle = "background:#ecfdf5; color:#047857; border:1px solid #a7f3d0;";
                        if (answerData.val === "NO") badgeStyle = "background:#fef2f2; color:#b91c1c; border:1px solid #fecaca;";

                        let commentHTML = "";
                        if (answerData.comment && answerData.comment.trim() !== "") {
                            commentHTML = `
                                <div style="margin-top:10px; background:#fffbeb; border-left:4px solid #f59e0b; padding:12px; border-radius:8px; font-size:13px; color:#92400e; font-style:italic;">
                                    <strong>Evaluator Note:</strong> "${answerData.comment}"
                                </div>
                            `;
                        }

                        sectionHTML += `
                            <div style="display:flex; flex-direction:column;">
                                <div style="display:flex; gap:12px; align-items:flex-start;">
                                    <span style="padding:6px 12px; border-radius:8px; font-size:12px; font-weight:800; height:fit-content; ${badgeStyle}">${answerData.val}</span>
                                    <p style="margin:0; font-size:14px; color:#334155; line-height:1.5;">${qText}</p>
                                </div>
                                ${commentHTML}
                            </div>
                        `;
                    }
                    sectionHTML += `</div></div>`;
                    container.innerHTML += sectionHTML;
                }
                window.openForm008Modal();
            } catch (e) {
                console.error(e);
                alert("Error loading evaluation details.");
            }
        };

        window.openForm008Modal = function() {
            const m = document.getElementById('studentForm008Modal');
            m.style.display = 'flex';
            setTimeout(() => m.classList.add('open'), 10);
            try { history.pushState({ layer: 'form008' }, '', ''); } catch(e) {}
        };

        window.closeForm008Modal = function(fromPopstate = false) {
            const m = document.getElementById('studentForm008Modal');
            m.classList.remove('open');
            setTimeout(() => { m.style.display = 'none'; }, 350);
            if (!fromPopstate) history.back();
        };

        // Drag to dismiss for wallet cards
        document.addEventListener('DOMContentLoaded', () => {
            let cardStartY = 0;
            let cardCurrentY = 0;
            let isCardDragging = false;
            let activeDraggingCard = null;

            document.addEventListener('touchstart', (e) => {
                const card = e.target.closest('.item-card.wallet-active');
                if (!card) return;

                // Only allow drag from the card header, not the scrollable body!
                const header = e.target.closest('.card-inner-bg');
                const body = e.target.closest('.card-body');
                if (body) return; // if they are touching the body (which might scroll), don't drag

                if (header) {
                    cardStartY = e.touches[0].clientY;
                    isCardDragging = true;
                    activeDraggingCard = card;
                    card.style.transition = 'none'; // disable CSS transition while dragging
                }
            }, {
                passive: true
            });

            document.addEventListener('touchmove', (e) => {
                if (!isCardDragging || !activeDraggingCard) return;

                cardCurrentY = e.touches[0].clientY;
                const deltaY = cardCurrentY - cardStartY;

                // Only drag downwards
                if (deltaY > 0) {
                    activeDraggingCard.style.transform = `translateY(${deltaY}px)`;
                }
            }, {
                passive: true
            });

            document.addEventListener('touchend', (e) => {
                if (!isCardDragging || !activeDraggingCard) return;
                isCardDragging = false;

                activeDraggingCard.style.transition = 'all 0.45s cubic-bezier(0.2, 0.8, 0.2, 1)';

                const deltaY = cardCurrentY - cardStartY;

                if (deltaY > 120) { // threshold
                    // Trigger close button
                    const backBtn = activeDraggingCard.querySelector('.wallet-back-btn');
                        backBtn.click();
                    } else {
                        // fallback
                        activeDraggingCard.classList.remove('wallet-active');
                        document.body.classList.remove('has-active-wallet');
                        document.querySelector('.items-grid').classList.remove('has-active-card');
                    }
                }

                // Reset transform
                activeDraggingCard.style.transform = '';
                activeDraggingCard = null;
            });
        });
    </script>
    <style>
        .premium-download-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(16,185,129,0.3) !important;
            filter: brightness(1.05);
        }
        .premium-download-btn:active {
            transform: translateY(0);
            box-shadow: 0 2px 8px rgba(16,185,129,0.2) !important;
        }

        /* Mascot Styles *        /* Mascot Styles */
        #mascot-celebration-container {
            position: fixed;
            top: 20px;
            right: 0;
            width: 250px;
            height: 250px;
            pointer-events: none;
            z-index: 999999;
            transform: translateX(100%);
            will-change: transform;
        }

        #mascot-celebration-container img {
            width: 100%;
            height: 100%;
            object-fit: contain;
        }

        .mascot-peek {
            animation: mascotPeek 7.5s cubic-bezier(0.2, 0.8, 0.2, 1) forwards;
        }

        @keyframes mascotPeek {
            0% { transform: translateX(100%); }
            10% { transform: translateX(45%); } /* Half body peek */
            90% { transform: translateX(45%); } /* Hold half body */
            100% { transform: translateX(100%); } /* Exit */
        }
    </style>

    <div id="mascot-celebration-container">
        <img src="../assets/images/mascott_party.svg" alt="Party Mascot">
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const isApproved = <?= $overall_prop_status === 'Approved' ? 'true' : 'false' ?>;

            if (isApproved) {
                // Check for reduced motion
                const prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
                
                if (!prefersReducedMotion) {
                    setTimeout(() => {
                        const mascot = document.getElementById('mascot-celebration-container');
                        mascot.classList.add('mascot-peek');

                        // Launch confetti immediately as it comes out
                        launchConfetti();
                    }, 500);
                }
            }
            
            function launchConfetti() {
                const colors = ['#10b981', '#34d399', '#fcd34d', '#fbbf24', '#f87171', '#818cf8'];
                const shapes = ['circle', 'square', 'triangle'];
                
                // Continuous slow rain, huge amount
                let rainInterval = setInterval(() => {
                    for (let i = 0; i < 8; i++) createParticle(colors, shapes);
                }, 100);
                
                // Stop spawning after 6.5 seconds so it ends around when the mascot leaves
                setTimeout(() => clearInterval(rainInterval), 6500);
            }
            
            function createParticle(colors, shapes) {
                const p = document.createElement('div');
                const shape = shapes[Math.floor(Math.random() * shapes.length)];
                
                p.style.position = 'fixed';
                // Origin near the mascot's horn at the top right
                p.style.left = `calc(100vw - 120px + ${Math.random() * 60 - 30}px)`;
                p.style.top = `calc(120px + ${Math.random() * 40 - 20}px)`;
                p.style.width = `${Math.random() * 6 + 6}px`;
                p.style.height = shape === 'triangle' ? '0' : `${Math.random() * 6 + 6}px`;
                
                if (shape === 'circle') p.style.borderRadius = '50%';
                if (shape === 'triangle') {
                    p.style.width = '0';
                    p.style.borderLeft = '5px solid transparent';
                    p.style.borderRight = '5px solid transparent';
                    p.style.borderBottom = `10px solid ${colors[Math.random() * colors.length | 0]}`;
                    p.style.backgroundColor = 'transparent';
                } else {
                    p.style.backgroundColor = colors[Math.random() * colors.length | 0];
                }
                
                p.style.zIndex = '999998';
                p.style.pointerEvents = 'none';
                document.body.appendChild(p);
                
                // Random physics (gentle floating)
                const angle = Math.random() * Math.PI * 0.5 + Math.PI * 0.75; // Shoot up/left slightly
                const velocity = Math.random() * 2 + 1; // Extremely slow velocity
                let vx = Math.cos(angle) * velocity - (Math.random() * 1); // Slight left wind
                let vy = -Math.sin(angle) * velocity;
                let rot = Math.random() * 360;
                let drot = (Math.random() - 0.5) * 2; // Very slow rotation
                
                let x = 0;
                let y = 0;
                let opacity = 1;
                
                function animate() {
                    vy += 0.03; // very light gravity for floating effect
                    vx *= 0.99; // very slight air resistance
                    x += vx;
                    y += vy;
                    rot += drot;
                    
                    if (y > window.innerHeight * 0.7) opacity -= 0.01; // Fade out near bottom
                    
                    p.style.transform = `translate(${x}px, ${y}px) rotate(${rot}deg)`;
                    p.style.opacity = opacity;
                    
                    if (opacity > 0 && (y + 120) < window.innerHeight) {
                        requestAnimationFrame(animate);
                    } else {
                        p.remove();
                    }
                }
                
                requestAnimationFrame(animate);
            }
        });
    </script>
</body>

</html>