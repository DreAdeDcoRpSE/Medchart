<?php
require_once __DIR__ . '/../includes/security.php';
require_once '../includes/db.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('POST required'); }
$user_id = med_require_user($conn);
if ($_SERVER['REQUEST_METHOD'] !== 'GET') med_require_csrf();
require_once '../includes/functions.php';
require_once '../secret/secret.php';
$user_id = $_SESSION['user_id'];
$allergy = trim($_POST['allergy'] ?? '');
if ($allergy !== '') {
    $allergy_enc = encryptData($allergy);
    // Get the current max sort_order
    $stmt = $conn->prepare("SELECT MAX(sort_order) AS max_order FROM allergy_list WHERE user_id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $max = $result->fetch_assoc()['max_order'] ?? 0;
    $next_order = $max + 1;
    $stmt = $conn->prepare("INSERT INTO allergy_list (user_id, allergy, sort_order) VALUES (?, ?, ?)");
    $stmt->bind_param("isi", $user_id, $allergy_enc, $next_order);
    $stmt->execute();
}
