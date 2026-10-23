<?php
require_once 'includes/auth.php';
require_once 'includes/db.php';
require_once 'secret/secret.php';
require_once 'includes/functions.php';
require_once 'includes/notifications.php';
$pendingAdminCount = !empty($_SESSION['is_admin']) ? med_pending_count($conn) : 0;
$recentSecurityAlerts = 0;
if (!empty($_SESSION['is_admin'])) {
    $securityResult = $conn->query("SELECT COUNT(*) AS total FROM security_events WHERE event_type='login_lockout' AND created_at >= NOW() - INTERVAL 24 HOUR");
    if ($securityResult) $recentSecurityAlerts = (int)$securityResult->fetch_assoc()['total'];
}


$user_id = $_SESSION['user_id'];

$name = '';
$dob = '';
$notes = '';

$stmt = $conn->prepare("SELECT name, dob, notes FROM medical_data WHERE user_id = ? LIMIT 1");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result && $result->num_rows > 0) {
    $row = $result->fetch_assoc();
    $name = decryptData($row['name']);
    $dob =  decryptData($row['dob']);
    $notes = decryptData($row['notes']);
}
?>

<!DOCTYPE html>
<html>

<head>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Medical Tracker</title>
    <link rel="stylesheet" href="css/style.css">
    <script>
        window.MEDCHART_CSRF = <?php echo json_encode(med_csrf()); ?>;
    </script>
    <link rel="stylesheet" href="css/theme.css">
    <script src="js/theme.js"></script>
</head>

<body>
    <div class="bodywrap">
        <?php if (!empty($_SESSION['is_admin']) && ($pendingAdminCount > 0 || $recentSecurityAlerts > 0)): ?>
            <div role="alert" style="background:#fff3cd;color:#664d03;border:2px solid #ffda6a;padding:14px;margin:12px 0;border-radius:6px;font-weight:bold;">
                Administrator attention required: <?= (int)$pendingAdminCount ?> pending account request(s); <?= (int)$recentSecurityAlerts ?> login lockout(s) in the past 24 hours.
                <a href="admin/index.php">Review requests</a>
            </div>
        <?php endif; ?>
        <!-- Mobile Scroll Helper (visible ≤768px) -->
        <div id="mobile-scroll-helper" aria-hidden="true"></div>
        <header>
            <button onclick="saveAll()">Save</button>
            <button onclick="window.open('print.php', '_blank')">Printer Friendly Version</button>
            <button type="button" onclick="window.location.href='change_password.php'">Change Password</button>
            <button type="button" onclick="window.location.href='transfer.php'">Import / Export</button>
            <?php if (!empty($_SESSION['is_admin'])): ?>
                <button type="button" onclick="window.location.href='admin/index.php'">Administration</button>
            <?php endif; ?>
            <button onclick="window.open('./logout.php', '_self')">Logout</button>
            <br>
            <h1>Medical Tracker</h1>
            <label>Name: <input type="text" id="name" value="<?php echo htmlspecialchars($name); ?>"></label><br><br>
            <label>DOB: <input type="date" id="dob" value="<?php echo htmlspecialchars($dob); ?>"></label><br><br>
        </header>
        <h2>Medications</h2>
        <div class="med-grid-header">
            <strong>Name</strong><strong>Dose</strong><strong>Frequency</strong><strong>Actions</strong>
        </div>
        <div id="medications-container" class="sortable meds"></div>
        <input type="text" id="new-med-name" placeholder="Name" style="min-width: 150px; max-width: 500px;">
        <input type="text" id="new-med-dose" placeholder="Dose (e.g. 200mg)">
        <input type="text" id="new-med-frequency" placeholder="Frequency (e.g. 1 tab 2x/day)">
        <button onclick="addMedication()" data-editing="false" id="addMedBtn">Add Medication</button><br><br>

        <h2>Medications No Longer Taking</h2>
        <div class="med-grid-header">
            <strong>Name</strong><strong>Dose</strong><strong>Frequency</strong><strong>Actions</strong>
        </div>
        <div id="inactive-medications-container" class="sortable meds"></div>
        <br><br>

        <h2>Medical Team</h2>
        <div class="team-grid-header">
            <strong>Name & Profession</strong><strong>Phone</strong><strong>Actions</strong>
        </div>
        <div id="medical-team-container" class="sortable team"></div>

        <input type="text" id="team-name" placeholder="Name, Title, Org" style="min-width: 150px; max-width: 500px;">
        <input type="text" id="team-role" placeholder="Phone" size="20">
        <button onclick="addTeamMember()">Add Team Member</button>
        <br><br>

        <h2>Allergies</h2>
        <div class="allergy-grid-header">
            <strong>Allergy List</strong><strong>Actions</strong>
        </div>
        <div id="allergy-container" class="sortable allergy"></div>
        <div style="margin-bottom: 10px;">
            <input type="text" id="allergy-input" placeholder="Enter allergy" style="min-width: 150px; max-width: 500px;">
            <button onclick="addAllergy()" data-editing="false" id="allergy-btn">Add Allergy</button>
        </div>
        <br><br>

        <h2>Notes</h2>
        <textarea id="notes" rows="10" cols="80"><?php echo htmlspecialchars($notes); ?></textarea><br><br>

        <button onclick="saveAll()">Save</button>
        <button onclick="window.open('print.php', '_blank')">Printer Friendly Version</button>

        <script src="js/script.js"></script>
        <script src="js/Sortable.min.js"></script>
    </div>
</body>

</html>