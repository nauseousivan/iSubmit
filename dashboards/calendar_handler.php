<?php
session_start();
require_once '../config/db.php';
require_once 'defense_lib.php';

// Only Coordinator or Director can manage the calendar
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['Research Coordinator', 'Research Director'])) {
    header("Location: ../index.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $referer = $_SERVER['HTTP_REFERER'] ?? 'director.php';

    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
        $_SESSION['message'] = "Invalid security token. Please refresh and try again.";
        $_SESSION['message_type'] = "error";
        header("Location: " . $referer);
        exit();
    }

    if ($action === 'add') {
        $title = trim($_POST['title']);
        $description = trim($_POST['description']);
        $event_date = $_POST['event_date'];
        $created_by = $_SESSION['user_id'];

        if (!empty($title) && !empty($event_date)) {
            $stmt = $pdo->prepare("INSERT INTO calendar_events (title, description, event_date, created_by) VALUES (?, ?, ?, ?)");
            $stmt->execute([$title, $description, $event_date, $created_by]);

            // Write through to defense scheduling so the office never has to enter a date twice.
            // Anything on the office calendar closes that date by default; if the event is not
            // actually a closure, the scheduling screen reopens it in one click (the event
            // itself is left alone). A date a group already holds is never closed from here —
            // they must be rejected or cancelled first, so the group learns what happened.
            // The held-check is deliberately phase-agnostic: a date carrying ANY group's booking
            // must not be closed from here, whichever defense it is. The closure itself is
            // written for BOTH phases, since the office being shut shuts it for everyone —
            // before proposal scheduling existed this only ever wrote 'final'.
            $note = "Office calendar: " . $title;
            $held = $pdo->prepare("SELECT COUNT(*) FROM defense_bookings
                                   WHERE defense_date = ? AND status IN ('Date Selected','Scheduled')");
            $held->execute([$event_date]);
            $blocked = false;
            if ((int)$held->fetchColumn() === 0 && $event_date >= df_today()) {
                $bo = $pdo->prepare("INSERT IGNORE INTO defense_blackouts (phase, block_date, reason, source, created_by)
                                     VALUES (?, ?, ?, 'event', ?)");
                foreach (['proposal', 'final'] as $bo_phase) {
                    $bo->execute([$bo_phase, $event_date, mb_substr($note, 0, 150), $created_by]);
                    $blocked = $blocked || $bo->rowCount() > 0;
                }
            }

            $_SESSION['message'] = $blocked
                ? "Event added. " . date('M j', strtotime($event_date)) . " is now closed for defenses — reopen it from Defense Scheduling if that is not right."
                : "Event added successfully.";
            $_SESSION['message_type'] = "success";
        } else {
            $_SESSION['message'] = "Title and date are required.";
            $_SESSION['message_type'] = "error";
        }
    } elseif ($action === 'reopen_day') {
        // Office calendar (2026-09-29): "Reopen this day" undoes whatever closed it, in one go —
        // the named office event(s) on that date AND every closure row, whatever made it
        // (event, a hand-closed day, an imported holiday), for both phases. A passed day is
        // history and is left alone.
        $day = (string)($_POST['event_date'] ?? '');
        $dt  = DateTime::createFromFormat('Y-m-d', $day);
        if (!$dt || $dt->format('Y-m-d') !== $day) {
            $_SESSION['message'] = "Invalid date.";
            $_SESSION['message_type'] = "error";
        } elseif ($day < df_today()) {
            $_SESSION['message'] = "That day has already passed.";
            $_SESSION['message_type'] = "error";
        } else {
            $pdo->prepare("DELETE FROM calendar_events WHERE event_date = ?")->execute([$day]);
            $pdo->prepare("DELETE FROM defense_blackouts WHERE block_date = ?")->execute([$day]);
            $_SESSION['message'] = date('F j', strtotime($day)) . " is open again.";
            $_SESSION['message_type'] = "success";
        }
    } elseif ($action === 'delete') {
        $event_id = $_POST['event_id'] ?? 0;
        if ($event_id) {
            // Capture the date before the row goes, so the paired closure can be cleaned up.
            $find = $pdo->prepare("SELECT event_date FROM calendar_events WHERE event_id = ?");
            $find->execute([$event_id]);
            $event_date = $find->fetchColumn();

            $stmt = $pdo->prepare("DELETE FROM calendar_events WHERE event_id = ?");
            $stmt->execute([$event_id]);

            // Only lift the closure this event created, and only if no other event still covers
            // that date. Closures the office made by hand (or imported holidays) are untouched.
            if ($event_date) {
                $others = $pdo->prepare("SELECT COUNT(*) FROM calendar_events WHERE event_date = ?");
                $others->execute([$event_date]);
                if ((int)$others->fetchColumn() === 0) {
                    $pdo->prepare("DELETE FROM defense_blackouts
                                   WHERE block_date = ? AND source = 'event'")->execute([$event_date]);
                }
            }

            $_SESSION['message'] = "Event deleted successfully.";
            $_SESSION['message_type'] = "success";
        }
    }
    
    header("Location: " . $referer);
    exit();
}
