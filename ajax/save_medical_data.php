<?php
require_once __DIR__ . '/../includes/security.php';
require_once '../includes/db.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('POST required'); }
$user_id = med_require_user($conn);
if ($_SERVER['REQUEST_METHOD'] !== 'GET') med_require_csrf();
require_once '../includes/functions.php';
require_once '../secret/secret.php';
$user_id = $_SESSION['user_id'];
$dob = encryptData($_POST['dob'] ?? '');
$notes = encryptData($_POST['notes'] ?? '');
$name = encryptData($_POST['name'] ?? '');

$stmt = $conn->prepare("SELECT user_id FROM medical_data WHERE user_id = ? LIMIT 1");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$res = $stmt->get_result();

if ($res->num_rows) {
    $stmt = $conn->prepare("UPDATE medical_data SET name=?, dob=?, notes=? WHERE user_id = ?");
    $stmt->bind_param("sssi", $name, $dob, $notes, $user_id);
    $stmt->execute();
} else {
    $stmt = $conn->prepare("INSERT INTO medical_data (user_id, name, dob, notes) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("isss", $user_id, $name, $dob, $notes);
    $stmt->execute();
}
echo "Saved successfully.";