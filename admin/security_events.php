<?php
require_once '../includes/security.php';
require_once '../includes/db.php';
$userId = med_require_user($conn);
$stmt = $conn->prepare('SELECT is_admin, is_approved FROM users WHERE id = ? LIMIT 1');
$stmt->bind_param('i', $userId);
$stmt->execute();
$admin = $stmt->get_result()->fetch_assoc();
if (!$admin || !(int)$admin['is_admin'] || !(int)$admin['is_approved']) {
    header('Location: ./access-denied.html', true, 302);
    exit;
}
function security_event_escape($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    med_require_csrf();
    if (($_POST['action'] ?? '') !== 'clear_security_events') {
        http_response_code(400);
        exit('Invalid action.');
    }
    if ($conn->query('DELETE FROM security_events')) {
        // Post/Redirect/Get prevents accidental repeat deletion on refresh.
        header('Location: security_events.php?cleared=1', true, 303);
        exit;
    }
    error_log('MedChart: Failed to clear security events');
    header('Location: security_events.php?error=1', true, 303);
    exit;
}
$events = $conn->query('SELECT event_type, username, ip_address, created_at FROM security_events ORDER BY id DESC LIMIT 100');
$countResult = $conn->query('SELECT COUNT(*) AS total FROM security_events');
$total = $countResult ? (int)$countResult->fetch_assoc()['total'] : 0;
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Recent Security Events — MedChart</title>
    <link rel="stylesheet" href="../css/theme.css">
    <script src="../js/theme.js" defer></script>
    <style>
        body { margin: 0; padding: 24px 16px; font-family: Arial, sans-serif; background: #f4f6f9; color: #202938; }
        .security-page { max-width: 1050px; margin: 0 auto; }
        .security-header { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px; margin-bottom: 16px; }
        .security-header h1 { margin: 0; font-size: 1.65rem; }
        .security-actions { display: flex; align-items: center; flex-wrap: wrap; gap: 10px; }
        .security-button { display: inline-block; padding: 10px 15px; border: 1px solid #8d9aaa; border-radius: 6px; background: #fff; color: #243247; font: 600 14px Arial, sans-serif; text-decoration: none; cursor: pointer; }
        .security-button:hover { background: #edf1f5; }
        .security-danger { background: #b42318; border-color: #b42318; color: #fff; }
        .security-danger:hover { background: #922018; }
        .security-danger:disabled { opacity: .55; cursor: not-allowed; }
        .security-summary { margin: 0 0 12px; color: #536175; }
        .security-table-scroll { max-height: 600px; overflow: auto; border: 1px solid #b8c2cf; border-radius: 8px; background: #fff; }
        .security-table { width: 100%; border-collapse: collapse; text-align: left; min-width: 560px; }
        .security-table th, .security-table td { padding: 12px 14px; border-bottom: 1px solid #e1e6ed; overflow-wrap: anywhere; }
        .security-table thead th { position: sticky; top: 0; z-index: 1; background: #e8edf4; color: #202938; }
        .security-table tbody tr:nth-child(even) { background: #f7f9fc; }
        .security-empty { text-align: center; padding: 26px 14px !important; }
        .security-message { border: 1px solid #318060; background: #e5f6ec; color: #14553c; border-radius: 7px; padding: 12px 14px; margin: 0 0 16px; }
        .security-message.error { border-color: #b42318; background: #fff0ee; color: #8c1e16; }
        html[data-medchart-theme="dark"] .security-table-scroll { background: #202734; border-color: #66778d; }
        html[data-medchart-theme="dark"] .security-table thead th { background: #34465f; color: #f3f6fb; }
        html[data-medchart-theme="dark"] .security-table tbody tr:nth-child(even) { background: #293444; }
        html[data-medchart-theme="dark"] .security-table td { border-color: #66778d; }
        html[data-medchart-theme="dark"] .security-summary { color: #c0ccdc; }
        html[data-medchart-theme="dark"] .security-button:not(.security-danger) { background: #34465f; color: #fff; border-color: #6d8099; }
        html[data-medchart-theme="dark"] .security-danger { background: #b42318; color: #fff; }
        html[data-medchart-theme="dark"] .security-message { background: #193e30; color: #d8fbe8; border-color: #4ba77d; }
        html[data-medchart-theme="dark"] .security-message.error { background: #512c2c; color: #ffe1dc; border-color: #dd6b60; }
        @media (max-width: 620px) { .security-header { align-items: stretch; } .security-header h1 { width: 100%; } .security-actions { width: 100%; } }
    </style>
</head>
<body>
<main class="security-page">
    <header class="security-header">
        <h1>Recent Security Events</h1>
        <div class="security-actions">
            <a class="security-button" href="index.php">Back to Administration</a>
            <form method="post" action="security_events.php" style="margin:0" onsubmit="return confirm('Permanently delete ALL security event logs? This cannot be undone.');">
                <input type="hidden" name="csrf" value="<?= security_event_escape(med_csrf()) ?>">
                <input type="hidden" name="action" value="clear_security_events">
                <button class="security-button security-danger" type="submit" <?= $total === 0 ? 'disabled' : '' ?>>Clear Log</button>
            </form>
        </div>
    </header>
    <?php if (isset($_GET['cleared'])): ?>
        <div class="security-message" role="status">Security event log cleared successfully.</div>
    <?php elseif (isset($_GET['error'])): ?>
        <div class="security-message error" role="alert">Unable to clear the security event log. Check the server error log.</div>
    <?php endif; ?>
    <p class="security-summary">Showing the latest <?= min($total, 100) ?> of <?= $total ?> recorded events.</p>
    <div class="security-table-scroll" role="region" aria-label="Security event log" tabindex="0">
        <table class="security-table">
            <thead><tr><th>Time</th><th>Event</th><th>Username</th><th>IP Address</th></tr></thead>
            <tbody>
            <?php if ($events && $events->num_rows): ?>
                <?php while ($row = $events->fetch_assoc()): ?>
                <tr><td><?= security_event_escape($row['created_at']) ?></td><td><?= security_event_escape($row['event_type']) ?></td><td><?= security_event_escape($row['username']) ?></td><td><?= security_event_escape($row['ip_address']) ?></td></tr>
                <?php endwhile; ?>
            <?php else: ?>
                <tr><td class="security-empty" colspan="4">No security events have been recorded.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</main>
</body>
</html>
