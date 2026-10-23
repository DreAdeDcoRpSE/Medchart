<?php
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/notifications.php';
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    med_require_csrf();
    $email = trim((string)($_POST['email'] ?? ''));
    $message = 'If an approved account matches that email address, a password reset link will be sent shortly.';
    if (filter_var($email, FILTER_VALIDATE_EMAIL) && strlen($email) <= 254) {
        $ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
        // Per-IP and per-address cooldowns, without disclosing whether the address exists.
        $limit = $conn->prepare("SELECT COUNT(*) AS n FROM security_events WHERE event_type='reset_requested' AND ip_address=? AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)");
        $limit->bind_param('s', $ip);
        $limit->execute();
        $ipCount = (int)$limit->get_result()->fetch_assoc()['n'];
        if ($ipCount < 5) {
            med_log($conn, 'reset_requested');
            $stmt = $conn->prepare('SELECT id, email FROM users WHERE email=? AND is_approved=1 LIMIT 1');
            $stmt->bind_param('s', $email);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();
            if ($user) {
                $uid = (int)$user['id'];
                $cool = $conn->prepare('SELECT COUNT(*) AS n FROM password_reset_tokens WHERE user_id=? AND created_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)');
                $cool->bind_param('i', $uid);
                $cool->execute();
                $recent = (int)$cool->get_result()->fetch_assoc()['n'];
                if ($recent === 0) {
                    $token = bin2hex(random_bytes(32));
                    $hash = hash('sha256', $token);
                    $conn->begin_transaction();
                    try {
                        $del = $conn->prepare('DELETE FROM password_reset_tokens WHERE user_id=?');
                        $del->bind_param('i', $uid);
                        $del->execute();
                        $ins = $conn->prepare('INSERT INTO password_reset_tokens (user_id,token_hash,expires_at) VALUES (?,?,DATE_ADD(NOW(), INTERVAL 30 MINUTE))');
                        $ins->bind_param('is', $uid, $hash);
                        $ins->execute();
                        $conn->commit();
                        // Use a configured public URL, never an arbitrary incoming Host header.
                        // Local WAMP installations may use localhost or 127.0.0.1 without configuration.
                        $baseUrl = defined('MEDCHART_BASE_URL') ? rtrim((string)MEDCHART_BASE_URL, '/') : '';
                        if ($baseUrl === '') {
                            $host = (string)($_SERVER['HTTP_HOST'] ?? '');
                            if (preg_match('/^(?:localhost|127\.0\.0\.1)(?::[0-9]{1,5})?$/iD', $host)) {
                                $base = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/forgot_password.php'))), '/');
                                $baseUrl = 'http://' . $host . $base;
                            }
                        }
                        if (preg_match('~^https?://[^/?#]+(?:/[^?#]*)?$~iD', $baseUrl)) {
                            $url = $baseUrl . '/reset_password.php?token=' . $token;
                            med_send_notice($user['email'], 'MedChart password reset', "Someone requested a password reset for your MedChart account.\n\nUse this link within 30 minutes:\n" . $url . "\n\nIf you did not request this, ignore this message.\n");
                        } else {
                            error_log('MedChart: Set MEDCHART_BASE_URL to enable password reset links on a public host.');
                        }
                    } catch (Throwable $e) {
                        $conn->rollback();
                        error_log('MedChart reset request failed');
                    }
                }
            }
        }
    }
}
function esc($v)
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Reset password request - MedChart</title>
    <link rel="stylesheet" href="css/theme.css">
    <script src="js/theme.js"></script>
    <style>
        body {
            font: 16px Arial, sans-serif;
            max-width: 520px;
            margin: 40px auto;
            padding: 20px
        }

        input {
            display: block;
            width: 100%;
            padding: 12px;
            box-sizing: border-box;
            margin: 10px 0
        }

        button {
            padding: 12px
        }

        .notice {
            padding: 16px;
            border: 2px solid #bd8b17;
            background: #fff3c4;
            color: #493500;
            border-radius: 8px
        }
    </style>
</head>

<body>
    <main>
        <h1>Forgot password?</h1><?php if ($message): ?><p class="notice" role="status"><?= esc($message) ?></p><?php endif; ?><form method="post"><input type="hidden" name="csrf" value="<?= esc(med_csrf()) ?>"><label for="email">Account email address</label><input id="email" name="email" type="email" maxlength="254" autocomplete="email" required><button type="submit">Send reset link</button></form>
        <p><a href="login.php">Return to login</a></p>
    </main>
</body>

</html>