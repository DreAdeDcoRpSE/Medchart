<?php
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/notifications.php';
$token = (string)($_POST['token'] ?? $_GET['token'] ?? '');
$valid = (bool)preg_match('/^[a-f0-9]{64}$/D', $token);
$error = '';
$success = false;
$emailSent = false;
$record = null;
if ($valid) {
    $hash = hash('sha256', $token);
    $stmt = $conn->prepare('SELECT id,user_id FROM password_reset_tokens WHERE token_hash=? AND expires_at>NOW() LIMIT 1');
    $stmt->bind_param('s', $hash);
    $stmt->execute();
    $record = $stmt->get_result()->fetch_assoc();
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    med_require_csrf();
    $pass = (string)($_POST['password'] ?? '');
    $confirm = (string)($_POST['confirm'] ?? '');
    if (!$record) $error = 'This reset link is invalid or has expired.';
    elseif (strlen($pass) < 8 || strlen($pass) > 1024) $error = 'Password must be at least 8 characters.';
    elseif (!hash_equals($pass, $confirm)) $error = 'Passwords do not match.';
    else {
        $conn->begin_transaction();
        try {
            $lock = $conn->prepare('SELECT user_id FROM password_reset_tokens WHERE id=? AND token_hash=? AND expires_at>NOW() FOR UPDATE');
            $rid = (int)$record['id'];
            $lock->bind_param('is', $rid, $hash);
            $lock->execute();
            $locked = $lock->get_result()->fetch_assoc();
            if (!$locked) throw new RuntimeException('Expired reset token');
            $uid = (int)$locked['user_id'];
            $pwHash = password_hash($pass, PASSWORD_DEFAULT);
            $up = $conn->prepare('UPDATE users SET password=?,must_change_password=0 WHERE id=? AND is_approved=1');
            $up->bind_param('si', $pwHash, $uid);
            $up->execute();
            if ($up->affected_rows !== 1) throw new RuntimeException('Account unavailable');
            $del = $conn->prepare('DELETE FROM password_reset_tokens WHERE user_id=?');
            $del->bind_param('i', $uid);
            $del->execute();
            $mailStmt = $conn->prepare('SELECT email FROM users WHERE id=? LIMIT 1');
            $mailStmt->bind_param('i', $uid);
            $mailStmt->execute();
            $mailUser = $mailStmt->get_result()->fetch_assoc();
            $conn->commit();
            $success = true;
            try {
                med_log($conn, 'password_reset_completed', (string)$uid);
            } catch (Throwable $logError) {
                error_log('MedChart password reset event logging failed');
            }
            $emailSent = !empty($mailUser['email']) && med_send_password_changed_notice($mailUser['email'], 'password recovery link');
            session_regenerate_id(true);
            $_SESSION = [];
        } catch (Throwable $e) {
            $conn->rollback();
            $error = 'Unable to reset password. Request a new link.';
            error_log('MedChart password reset failed');
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
    <title>Choose new password - MedChart</title>
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
    <style>
        .med-pw-wrap {
            display: flex;
            align-items: stretch;
            position: relative;
            gap: 0
        }

        .med-pw-wrap>input {
            flex: 1;
            min-width: 0
        }

        .med-pw-toggle {
            flex: 0 0 44px;
            min-width: 44px;
            min-height: 44px;
            padding: 8px !important;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            border: 1px solid #999;
            background: #f4f4f4;
            border-radius: 5px;
            margin-left: 5px
        }

        .med-pw-toggle svg {
            width: 21px;
            height: 21px
        }
    </style>
</head>

<body>
    <main>
        <h1>Set a new password</h1><?php if ($success): ?><div class="med-notice med-notice-<?= $emailSent ? 'success' : 'warning' ?>" role="status"><strong><?= $emailSent ? '✓ Password Changed' : '⚠ Email Warning' ?></strong>
                <div><?= $emailSent ? 'Your password has been changed. A security notification was sent to your registered email address. Please log in.' : 'Your password has been changed, but the security notification email could not be sent. Please contact the administrator to check email delivery.' ?></div>
            </div>
            <p id="redirect-countdown" role="status">Redirecting to the login page in <span id="redirect-seconds">5</span> seconds...</p>
            <script>
                (function() {
                    var remaining = 5;
                    var label = document.getElementById("redirect-seconds");
                    var countdown = setInterval(function() {
                        remaining--;
                        if (remaining <= 0) {
                            clearInterval(countdown);
                            window.location.replace("login.php");
                            return;
                        }
                        if (label) label.textContent = String(remaining);
                    }, 1000);
                })();
            </script><?php elseif (!$record): ?><div class="med-notice med-notice-error" role="alert">This reset link is invalid or expired. <a href="forgot_password.php">Request a new one</a>.</div><?php else: ?><?php if ($error): ?><div class="med-notice med-notice-error" role="alert"><?= esc($error) ?></div><?php endif; ?><form method="post"><input type="hidden" name="csrf" value="<?= esc(med_csrf()) ?>"><input type="hidden" name="token" value="<?= esc($token) ?>"><label for="password">New password (8 characters minimum)</label><input id="password" name="password" type="password" minlength="8" autocomplete="new-password" required><label for="confirm">Confirm new password</label><input id="confirm" name="confirm" type="password" minlength="8" autocomplete="new-password" required><button type="submit">Change password</button></form><?php endif; ?>
    </main>
    <script>
        (function() {
            const eye = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg>';
            const hidden = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 3l18 18M10.6 5.1A12 12 0 0 1 12 5c6.4 0 10 7 10 7a15 15 0 0 1-3.1 3.8M6.2 6.2C3.4 8.1 2 12 2 12s3.6 7 10 7a10.5 10.5 0 0 0 4.4-1"/><path d="M10 10a3 3 0 0 0 4 4"/></svg>';
            document.querySelectorAll('input[type="password"]').forEach(function(input) {
                const wrapper = document.createElement('span');
                wrapper.className = 'med-pw-wrap';
                input.parentNode.insertBefore(wrapper, input);
                wrapper.appendChild(input);
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'med-pw-toggle';
                btn.setAttribute('aria-label', 'Show password');
                btn.setAttribute('aria-pressed', 'false');
                btn.title = 'Show password';
                btn.innerHTML = eye;
                btn.addEventListener('click', function() {
                    const show = input.type === 'password';
                    input.type = show ? 'text' : 'password';
                    btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
                    btn.setAttribute('aria-pressed', String(show));
                    btn.title = show ? 'Hide password' : 'Show password';
                    btn.innerHTML = show ? hidden : eye;
                });
                wrapper.appendChild(btn);
            });
        })();
    </script>
</body>

</html>