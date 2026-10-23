<?php
require_once 'includes/security.php';
require_once 'includes/db.php';
require_once 'includes/notifications.php';
if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
$id = (int)$_SESSION['user_id'];
$msg = '';
$msgType = 'error';
$passwordChanged = false;
$stmt = $conn->prepare('SELECT password,is_approved,email FROM users WHERE id=?');
$stmt->bind_param('i', $id);
$stmt->execute();
$u = $stmt->get_result()->fetch_assoc();
if (!$u || !$u['is_approved']) {
    http_response_code(403);
    exit('Forbidden');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    med_require_csrf();
    $old = (string)($_POST['current'] ?? '');
    $new = (string)($_POST['new'] ?? '');
    if (!password_verify($old, $u['password'])) $msg = 'Current password is incorrect.';
    elseif (strlen($new) < 8 || strlen($new) > 1024) $msg = 'New password must contain at least 8 characters.';
    elseif ($new !== ($_POST['confirm'] ?? '')) $msg = 'New passwords do not match.';
    else {
        $hash = password_hash($new, PASSWORD_DEFAULT);
        $update = $conn->prepare('UPDATE users SET password=?,must_change_password=0 WHERE id=? AND password=?');
        $update->bind_param('sis', $hash, $id, $u['password']);
        $update->execute();
        if ($update->affected_rows !== 1) {
            $msg = 'Your password changed in another session. Please sign in again.';
        } else {
            $_SESSION['password_fingerprint'] = hash('sha256', $hash);
            session_regenerate_id(true);
            med_log($conn, 'password_changed', (string)$id);
            $sent = med_send_password_changed_notice($u['email'], 'account settings');
            $msg = $sent ? 'Your password was changed successfully. A security notification was sent to your registered email address.' : 'Your password was changed successfully, but the security notification email could not be sent. Please contact the administrator to check email delivery.';
            $msgType = $sent ? 'success' : 'warning';
            $passwordChanged = true;
        }
    }
}
function h($v)
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html>

<head>
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Change password</title>
    <style>
        body {
            font: 16px Arial, sans-serif;
            max-width: 430px;
            margin: 40px auto;
            padding: 20px
        }

        label {
            display: block;
            margin: 16px 0
        }

        input {
            display: block;
            width: 100%;
            padding: 12px;
            box-sizing: border-box
        }

        button {
            padding: 12px
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
    <link rel="stylesheet" href="css/theme.css">
    <script src="js/theme.js"></script>
</head>

<body>
    <h1>Change password</h1><?php if ($msg): ?><div class="med-notice med-notice-<?= h($msgType) ?>" role="<?= $msgType === 'error' ? 'alert' : 'status' ?>"><strong><?= $msgType === 'success' ? '✓ Password Changed' : ($msgType === 'warning' ? '⚠ Email Warning' : '⚠ Password Not Changed') ?></strong>
            <div><?= h($msg) ?></div><?php if ($passwordChanged): ?><p id="med-redirect-countdown" role="status" aria-live="polite">Redirecting to your dashboard in <strong id="med-redirect-seconds">5</strong> seconds...</p>
                <p><a href="index.php">Return to Dashboard now</a></p><?php endif; ?>
        </div><?php endif; ?><?php if (!$passwordChanged): ?><form method="post"><input type="hidden" name="csrf" value="<?= h(med_csrf()) ?>"><label>Current or temporary password<input type="password" name="current" required autocomplete="current-password"></label><label>New password (8+ characters)<input type="password" name="new" minlength="8" required autocomplete="new-password"></label><label>Confirm new password<input type="password" name="confirm" minlength="8" required autocomplete="new-password"></label><button>Update password</button></form><?php endif; ?><p><a href="index.php">Dashboard</a> · <a href="logout.php">Logout</a></p><?php if ($passwordChanged): ?><script>
            (function() {
                var seconds = 5;
                var el = document.getElementById('med-redirect-seconds');
                var countdown = window.setInterval(function() {
                    seconds--;
                    if (seconds <= 0) {
                        window.clearInterval(countdown);
                        window.location.replace('index.php');
                        return;
                    }
                    if (el) el.textContent = String(seconds);
                }, 1000);
            })();
        </script><?php endif; ?><script>
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