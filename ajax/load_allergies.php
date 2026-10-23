<?php
require_once __DIR__ . '/../includes/security.php';
require_once '../includes/db.php';
$user_id = med_require_user($conn);
if ($_SERVER['REQUEST_METHOD'] !== 'GET') med_require_csrf();
require_once '../includes/functions.php';
require_once '../secret/secret.php';
$user_id = $_SESSION['user_id'];
$stmt = $conn->prepare("SELECT id, allergy FROM allergy_list WHERE user_id = ? ORDER BY sort_order ASC");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $id = (int)$row['id'];
    $plain = decryptData($row['allergy']);
    $text = htmlspecialchars($plain, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $payload = htmlspecialchars($plain, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    echo "<div class='allergy-row' data-id='$id'>
<button type='button' class='drag-handle' aria-label='Drag to reorder' title='Drag to reorder'><svg class='grip-dots' viewBox='0 0 16 24' width='16' height='22' aria-hidden='true' focusable='false'><circle cx='4' cy='4' r='2'/><circle cx='12' cy='4' r='2'/><circle cx='4' cy='12' r='2'/><circle cx='12' cy='12' r='2'/><circle cx='4' cy='20' r='2'/><circle cx='12' cy='20' r='2'/></svg></button>
<span>$text</span>
<div>
<button data-allergy='$payload' onclick='editAllergy($id, this.dataset.allergy)'>Edit</button>
<button onclick='deleteAllergy($id)'>Delete</button>
</div>
</div>";
}
