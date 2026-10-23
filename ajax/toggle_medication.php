<?php
require_once __DIR__ . '/../includes/security.php';
require_once '../includes/db.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('POST required'); }
$user_id = med_require_user($conn);
if ($_SERVER['REQUEST_METHOD'] !== 'GET') med_require_csrf();
$user_id = $_SESSION['user_id'];
$id = (int)$_POST['id'];

$stmt = $conn->prepare("UPDATE medications SET is_active = NOT is_active WHERE id = ? AND user_id = ?");
$stmt->bind_param("ii", $id, $user_id);
$stmt->execute();