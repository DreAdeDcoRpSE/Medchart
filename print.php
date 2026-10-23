<?php
require_once __DIR__ . '/includes/security.php';
require_once 'includes/db.php';
med_require_user($conn);
require_once 'includes/functions.php';
require_once 'secret/secret.php';
$is_pdf = isset($is_pdf) ? $is_pdf : false;
if (!isset($_SESSION['user_id'])) {
    die("Unauthorized");
}
$user_id = $_SESSION['user_id'];

// Get name, DOB, notes
$name = $dob = $notes = '';
$stmt = $conn->prepare("SELECT name, dob, notes FROM medical_data WHERE user_id = ? LIMIT 1");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result && $result->num_rows > 0) {
    $row = $result->fetch_assoc();
    $name = decryptData($row['name']);
    $dob = decryptData($row['dob']);
    $notes = decryptData($row['notes']);
}
$dob_formatted = $dob ? htmlspecialchars(date("m/d/Y", strtotime($dob))) . ' <em><small>(MM/DD/YYYY)</small></em>' : '';

// Get medications
$active_meds = [];
$inactive_meds = [];
$stmt = $conn->prepare("SELECT name, dose, frequency, is_active FROM medications WHERE user_id = ? ORDER BY sort_order ASC");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$meds = $stmt->get_result();
while ($med = $meds->fetch_assoc()) {
    $med['name'] = decryptData($med['name']);
    $med['dose'] = decryptData($med['dose']);
    $med['frequency'] = decryptData($med['frequency']);
    if ($med['is_active']) {
        $active_meds[] = $med;
    } else {
        $inactive_meds[] = $med;
    }
}

// Get medical team
$team_list = [];
$stmt = $conn->prepare("SELECT name, role, phone FROM medical_team WHERE user_id = ? ORDER BY sort_order ASC");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$team = $stmt->get_result();
while ($member = $team->fetch_assoc()) {
    $member['name'] = decryptData($member['name']);
    $member['role'] = decryptData($member['role']);
    $member['phone'] = decryptData($member['phone']);
    $team_list[] = $member;
}

// Get allergy list
$allergies = [];
$stmt = $conn->prepare("SELECT allergy FROM allergy_list WHERE user_id = ? ORDER BY sort_order ASC");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$allergy_result = $stmt->get_result();
while ($row = $allergy_result->fetch_assoc()) {
    $allergies[] = decryptData($row['allergy']);
}
?>
<!DOCTYPE html>
<html>

<head>
    <title>Printable Medical Chart</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 20px;
        }

        .bodywrap {
            width: 100%;
            max-width: 750px;
            margin: 0 auto;
            box-sizing: border-box;
        }

        h2 {
            border-bottom: 1px solid #333;
            padding-bottom: 5px;
            margin-top: 5px;
        }

        .section {
            padding: 5px 15px;
            margin-bottom: 20px;
            border: 1px solid #999;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 5px;
        }

        th,
        td {
            border: 1px solid #666;
            padding: 6px 10px;
            min-height: 35px;
        }

        th,
        td {
            word-wrap: break-word;
            white-space: normal;
            overflow-wrap: break-word;
        }

        .allergy-table th,
        .allergy-table td {
            border: 0px !important;
            padding: 2px 0px 2px 10px;
            min-height: 20px;
        }

        .medication-table th:nth-of-type(1),
        .team-table th:nth-of-type(1),
        .allergy-table th:nth-of-type(1) {
            text-align: left;
            padding-left: 15px;
        }

        /* Active/inactive medications table rows */
        .medication-table tbody tr:nth-child(even),
        .team-table tbody tr:nth-child(even) {
            background-color: #e9e9e9;
        }

        .medication-table th:nth-child(1),
        .medication-table td:nth-child(1) {
            width: 70%;
        }

        .medication-table th:nth-child(2),
        .medication-table td:nth-child(2) {
            width: 10%;
            text-align: center;
        }

        .medication-table th:nth-child(3),
        .medication-table td:nth-child(3) {
            width: 20%;
        }

        .team-table th:nth-child(1),
        .team-table td:nth-child(1) {
            width: 85%;
        }

        .team-table th:nth-child(2),
        .team-table td:nth-child(2) {
            width: 15%;
        }

        @media print {
            .no-print {
                display: none !important;
            }

            thead {
                display: table-header-group;
            }

            tfoot {
                display: table-footer-group;
            }

            tr {
                page-break-inside: avoid;
            }
        }
    </style>
</head>

<body>
    <?php if (!$is_pdf): ?>
        <div class="no-print">
            <form action="export_pdf.php" method="post" style="display:inline;">
                <button type="submit">Export to PDF</button>
            </form>
            <button onclick="window.print()">Print This Page</button>
        </div>
    <?php endif; ?>


    <div class="bodywrap">
        <h1>Medical Summary</h1>

        <div class="section">
            <p><strong>Name:</strong> <?php echo htmlspecialchars($name); ?></p>
            <p><strong>DOB:</strong> <?php echo $dob_formatted; ?></p>
        </div>

        <?php if (!empty($active_meds)) { ?>
            <div class="section">
                <h2>Active Medications</h2>
                <table class="medication-table">
                    <thead>
                        <tr>
                            <th>Medication Name</th>
                            <th>Dose</th>
                            <th style="min-width: 200px;">Frequency</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($active_meds as $med) { ?>
                            <tr>
                                <td><?php echo htmlspecialchars($med['name']); ?></td>
                                <td><?php echo htmlspecialchars($med['dose']); ?></td>
                                <td><?php echo htmlspecialchars($med['frequency']); ?></td>
                            </tr>
                        <?php } ?>
                    </tbody>
                </table>
            </div>
        <?php } ?>

        <?php if (!empty($inactive_meds)) { ?>
            <div class="section">
                <h2>Medications No Longer Taken</h2>
                <table class="medication-table">
                    <thead>
                        <tr>
                            <th>Medication Name</th>
                            <th>Dose</th>
                            <th style="min-width: 200px;">Frequency</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($inactive_meds as $med) { ?>
                            <tr>
                                <td><?php echo htmlspecialchars($med['name']); ?></td>
                                <td><?php echo htmlspecialchars($med['dose']); ?></td>
                                <td><?php echo htmlspecialchars($med['frequency']); ?></td>
                            </tr>
                        <?php } ?>
                    </tbody>
                </table>
            </div>
        <?php } ?>

        <?php if (!empty($team_list)) { ?>
            <div class="section">
                <h2>Medical Team</h2>
                <table class="team-table">
                    <thead>
                        <tr>
                            <th>Name & Role</th>
                            <th style="min-width: 110px">Phone</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($team_list as $member) { ?>
                            <tr>
                                <td><?php echo htmlspecialchars($member['name']); ?></td>
                                <td><?php echo htmlspecialchars($member['phone']); ?></td>
                            </tr>
                        <?php } ?>
                    </tbody>
                </table>
            </div>
        <?php } ?>

        <?php if (!empty($allergies)) { ?>
            <div class="section">
                <h2>Allergies</h2>
                <table class="allergy-table">
                    <tbody>
                        <?php foreach ($allergies as $item) { ?>
                            <tr>
                                <td>&nbsp;<strong>&bull;</strong> <?php echo htmlspecialchars($item); ?></td>
                            </tr>
                        <?php } ?>
                    </tbody>
                </table>
            </div>
        <?php } ?>

        <?php if ($notes) { ?>
            <div class="section">
                <h2>Notes</h2>
                <p><?php echo nl2br(htmlspecialchars($notes)); ?></p>
            </div>
        <?php } ?>
    </div>
</body>

</html>