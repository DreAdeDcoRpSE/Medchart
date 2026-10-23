<?php
require_once __DIR__ . '/../includes/security.php';
require_once '../includes/db.php';
$user_id = med_require_user($conn);
if ($_SERVER['REQUEST_METHOD'] !== 'GET') med_require_csrf();
require_once '../secret/secret.php';
require_once '../includes/functions.php';
$user_id = $_SESSION['user_id'];
$active = $inactive = '';
$stmt = $conn->prepare("SELECT * FROM medications WHERE user_id = ? ORDER BY sort_order ASC");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    // Decrypt fields
    $row['name'] = decryptData($row['name']);
    $row['dose'] = decryptData($row['dose']);
    $row['frequency'] = decryptData($row['frequency']);
    $id = (int)$row['id'];
    $name = htmlspecialchars($row['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $dose = htmlspecialchars($row['dose'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $frequency = htmlspecialchars($row['frequency'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $payload = htmlspecialchars(json_encode($row, JSON_INVALID_UTF8_SUBSTITUTE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8');
    $row_html = "
<div class='med-row' data-id='{$id}'>
<button type='button' class='drag-handle' aria-label='Drag to reorder' title='Drag to reorder'><svg class='grip-dots' viewBox='0 0 16 24' width='16' height='22' aria-hidden='true' focusable='false'><circle cx='4' cy='4' r='2'/><circle cx='12' cy='4' r='2'/><circle cx='4' cy='12' r='2'/><circle cx='12' cy='12' r='2'/><circle cx='4' cy='20' r='2'/><circle cx='12' cy='20' r='2'/></svg></button>
<div>{$name}</div>
<div>{$dose}</div>
<div>{$frequency}</div>
<div>
<button data-medication='{$payload}' onclick='editMedication(JSON.parse(this.dataset.medication))'>Edit</button>
<button onclick='toggleMedication({$id})'>" . ($row['is_active'] ? "Deactivate" : "Reactivate") . "</button>
<button onclick='deleteMedication({$id})'>Delete</button>
</div>
</div>";
    if ($row['is_active']) $active .= $row_html;
    else $inactive .= $row_html;
}
echo $active . "<!--SPLIT-->" . $inactive;
