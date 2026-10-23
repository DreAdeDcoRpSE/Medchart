<?php
require_once __DIR__ . '/../includes/security.php';
require_once '../includes/db.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('POST required'); }
$user_id = med_require_user($conn);
if ($_SERVER['REQUEST_METHOD'] !== 'GET') med_require_csrf();
$user_id = $_SESSION['user_id'];
$order = explode(',', $_POST['order']);

$stmt = $conn->prepare("UPDATE medications SET sort_order = ? WHERE id = ? AND user_id = ?");

foreach ($order as $i => $id) {
    $id = (int)$id;
    $stmt->bind_param("iii", $i, $id, $user_id);
    $stmt->execute();
}