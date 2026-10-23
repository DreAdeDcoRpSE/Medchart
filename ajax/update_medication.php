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
$id = (int)($_POST['id'] ?? 0);
$name = encryptData($_POST['name'] ?? '');
$dose = encryptData($_POST['dose'] ?? '');
$frequency = encryptData($_POST['frequency'] ?? '');

$stmt = $conn->prepare("UPDATE medications SET name=?, dose=?, frequency=? WHERE id=? AND user_id=?");
$stmt->bind_param("sssii", $name, $dose, $frequency, $id, $user_id);
$stmt->execute();
