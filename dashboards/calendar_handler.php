<?php
session_start();
require_once '../config/db.php';

// Only Coordinator or Director can manage the calendar
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['Research Coordinator', 'Research Director'])) {
    header("Location: ../index.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $referer = $_SERVER['HTTP_REFERER'] ?? 'director.php';

    if ($action === 'add') {
        $title = trim($_POST['title']);
        $description = trim($_POST['description']);
        $event_date = $_POST['event_date'];
        $created_by = $_SESSION['user_id'];

        if (!empty($title) && !empty($event_date)) {
            $stmt = $pdo->prepare("INSERT INTO calendar_events (title, description, event_date, created_by) VALUES (?, ?, ?, ?)");
            $stmt->execute([$title, $description, $event_date, $created_by]);
            $_SESSION['message'] = "Event added successfully.";
            $_SESSION['message_type'] = "success";
        } else {
            $_SESSION['message'] = "Title and date are required.";
            $_SESSION['message_type'] = "error";
        }
    } elseif ($action === 'delete') {
        $event_id = $_POST['event_id'] ?? 0;
        if ($event_id) {
            $stmt = $pdo->prepare("DELETE FROM calendar_events WHERE event_id = ?");
            $stmt->execute([$event_id]);
            $_SESSION['message'] = "Event deleted successfully.";
            $_SESSION['message_type'] = "success";
        }
    }
    
    header("Location: " . $referer);
    exit();
}
