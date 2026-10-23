<?php
require_once __DIR__ . '/../includes/security.php';
require_once '../includes/db.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('POST required'); }
$user_id = med_require_user($conn);
if ($_SERVER['REQUEST_METHOD'] !== 'GET') med_require_csrf();
require_once '../includes/functions.php';
require_once '../secret/secret.php';
$user_id = $_SESSION['user_id'];
$name = encryptData($_POST['name']);
$role = encryptData($_POST['role']);
$phone =encryptData($_POST['phone']);
$stmt = $conn->prepare("INSERT INTO medical_team (user_id, name, role, phone) VALUES (?, ?, ?, ?)");
$stmt->bind_param("isss", $user_id, $name, $role, $phone);
$stmt->execute();