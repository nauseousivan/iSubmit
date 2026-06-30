<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: auth/login.php");
    exit();
}

// Route users to their respective dashboards based on role
switch ($_SESSION['role']) {
    case 'Student':
        header("Location: dashboards/student.php");
        break;
    case 'Research Coordinator':
        header("Location: dashboards/coordinator.php");
        break;
    case 'Statistician':
        header("Location: dashboards/statistician.php");
        break;
    case 'Research Director':
        header("Location: dashboards/director.php");
        break;
    default:
        header("Location: auth/logout.php");
        break;
}
exit();
?>