<?php
require '../config/db.php';
$stmt = $pdo->query('SHOW COLUMNS FROM activity_logs');
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
