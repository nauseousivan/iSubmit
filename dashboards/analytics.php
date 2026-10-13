<?php
// dashboards/analytics.php — RETIRED 2026-10-01, kept as a redirect for old bookmarks and windows.
// The office's Analytics is office_analytics.php (scoped, filterable, with names); the Admin's is
// admin_analytics.php (accounts, help desk, sign-ins — not papers). The old page is in git history.
require_once '../config/db.php';
if (session_status() === PHP_SESSION_NONE) session_start();
$role = (string)($_SESSION['role'] ?? '');
if (!isset($_SESSION['user_id'])) { header('Location: ../auth/login.php'); exit(); }
header('Location: ' . ($role === 'Admin' ? 'admin_analytics.php' : 'office_analytics.php'));
exit();
