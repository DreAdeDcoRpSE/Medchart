<?php
if (function_exists('apache_setenv')) {
    apache_setenv('no-gzip', 1);
}
header('Content-Type: text/html');
require_once __DIR__ . '/../includes/security.php';
require_once '../includes/db.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('POST required'); }
$user_id = med_require_user($conn);
if ($_SERVER['REQUEST_METHOD'] !== 'GET') med_require_csrf();
require_once '../includes/functions.php';
require_once '../secret/secret.php';

$user_id = $_SESSION['user_id'];
$name = encryptData($_POST['name'] ?? '');
$dose = encryptData($_POST['dose'] ?? '');
$frequency = encryptData($_POST['frequency'] ?? '');

// Get the current max sort_order for this user (prepared)
$stmt = $conn->prepare("SELECT MAX(sort_order) AS max_order FROM medications WHERE user_id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();
$next_sort_order = isset($row['max_order']) ? ((int)$row['max_order'] + 1) : 1;

// Insert with prepared statement
$stmt = $conn->prepare("INSERT INTO medications (user_id, name, dose, frequency, is_active, sort_order) VALUES (?, ?, ?, ?, 1, ?)");
$stmt->bind_param("isssi", $user_id, $name, $dose, $frequency, $next_sort_order);
$stmt->execute();
?>