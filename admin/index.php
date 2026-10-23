<?php
require_once '../includes/security.php';
require_once '../includes/db.php';
$self = med_require_user($conn);
$check = $conn->prepare('SELECT is_admin,is_approved FROM users WHERE id=?');
$check->bind_param('i', $self);
$check->execute();
$me = $check->get_result()->fetch_assoc();
if (!$me || !$me['is_admin'] || !$me['is_approved']) {
    header('Location: ./access-denied.html', true, 302);
    exit;
}
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
require_once '../includes/notifications.php';
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    med_require_csrf();
    $action = (string)($_POST['action'] ?? '');
    $id = (int)($_POST['id'] ?? 0);
    if ($action === 'test_email') {
        $testAddress = med_admin_email($conn);
        if (!filter_var($testAddress, FILTER_VALIDATE_EMAIL)) {
            $message = 'Save a valid administrator email address before testing.';
        } else {
            $sent = med_send_notice($testAddress, 'MedChart: Test email', "This is a test email from MedChart.\nIf you received this, the configured mail transport is working.\n");
            $message = $sent ? 'Test email sent. Check your inbox or Mailpit.' : 'Test email failed. Check your mail settings and PHP error log.';
        }
    } elseif ($action === 'save_email') {
        $email = trim((string)($_POST['admin_email'] ?? ''));
        if ($email !== '' && (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254)) $message = 'Enter a valid administrator email address.';
        else {
            $stmt = $conn->prepare("INSERT INTO app_settings (setting_key,setting_value) VALUES ('admin_email',?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");
            $stmt->bind_param('s', $email);
            $message = $stmt->execute() ? 'Administrator email saved.' : 'Unable to save email.';
        }
    } elseif ($action === 'create') {
        $name = trim((string)($_POST['username'] ?? ''));
        $pass = (string)($_POST['password'] ?? '');
        $email = trim((string)($_POST['email'] ?? ''));
        $isAdmin = isset($_POST['is_admin']) ? 1 : 0;
        if (!preg_match('/^[a-zA-Z0-9_.-]{3,50}$/', $name) || strlen($pass) < 8 || ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) || strlen($email) > 254) $message = 'Invalid username, email or password (minimum 8 characters).';
        else {
            $hash = password_hash($pass, PASSWORD_DEFAULT);
            $stmt = $conn->prepare('INSERT INTO users (username,password,email,is_admin,is_approved) VALUES (?,?,?,?,1)');
            $stmt->bind_param('sssi', $name, $hash, $email, $isAdmin);
            $message = $stmt->execute() ? 'User created.' : 'Unable to create user; username or email may be taken.';
        }
    } elseif ($id > 0 && $id !== $self) {
        $stmt = $conn->prepare('SELECT username,email,is_approved FROM users WHERE id=? LIMIT 1');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $target = $stmt->get_result()->fetch_assoc();
        if (!$target) $message = 'Account not found.';
        elseif ($action === 'reject') {
            $reason = trim((string)($_POST['reason'] ?? ''));
            if (mb_strlen($reason) < 5 || mb_strlen($reason) > 2000) $message = 'A denial reason of 5 to 2000 characters is required.';
            elseif ((int)$target['is_approved'] !== 0) $message = 'Only pending accounts can be denied.';
            else {
                $stmt = $conn->prepare('DELETE FROM users WHERE id=? AND is_approved=0');
                $stmt->bind_param('i', $id);
                if ($stmt->execute() && $stmt->affected_rows === 1) {
                    $message = 'Request denied. ';
                    med_log($conn, 'registration_denied', $target['username']);
                    $sent = med_send_notice($target['email'], 'MedChart: Account request denied', "Your MedChart account request was denied.\n\nReason: " . $reason . "\n");
                    $message .= $sent ? 'Notification email sent.' : 'Email not sent; check mail configuration.';
                } else $message = 'Unable to deny request.';
            }
        } elseif ($action === 'approve') {
            if ((int)$target['is_approved'] !== 0) $message = 'Account is already approved.';
            else {
                $stmt = $conn->prepare('UPDATE users SET is_approved=1 WHERE id=? AND is_approved=0');
                $stmt->bind_param('i', $id);
                if ($stmt->execute() && $stmt->affected_rows === 1) {
                    med_log($conn, 'registration_approved', $target['username']);
                    $sent = med_send_notice($target['email'], 'MedChart: Account approved', "Your MedChart account has been approved. You can now log in using the password you selected when registering.\n");
                    $message = 'Account approved. ' . ($sent ? 'Notification email sent.' : 'Email not sent; check mail configuration.');
                } else $message = 'Approval failed.';
            }
        } elseif ($action === 'reset_password') {
            $temp = bin2hex(random_bytes(12));
            $hash = password_hash($temp, PASSWORD_DEFAULT);
            $stmt = $conn->prepare('UPDATE users SET password=?,must_change_password=1 WHERE id=?');
            $stmt->bind_param('si', $hash, $id);
            if ($stmt->execute()) {
                $message = 'Temporary password (copy now): ' . $temp;
                med_log($conn, 'admin_password_reset', $target['username']);
            } else $message = 'Reset failed.';
        } else {
            $sql = ['promote' => 'UPDATE users SET is_admin=1 WHERE id=? AND is_approved=1', 'demote' => 'UPDATE users SET is_admin=0 WHERE id=?', 'delete' => 'DELETE FROM users WHERE id=?'][$action] ?? null;
            if (!$sql) $message = 'Unknown action.';
            else {
                $stmt = $conn->prepare($sql);
                $stmt->bind_param('i', $id);
                $message = $stmt->execute() ? 'Action completed.' : 'Action failed.';
            }
        }
    } else $message = 'Cannot change your own administrator account.';
}
$users = $conn->query('SELECT id,username,email,is_admin,is_approved FROM users ORDER BY is_approved ASC, username ASC');
$adminEmail = med_admin_email($conn);
$pendingCount = med_pending_count($conn);
function h($s)
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>MedChart Admin</title>
    <style>
        body {
            font: 16px Arial, sans-serif;
            max-width: 1000px;
            margin: 30px auto;
            padding: 12px
        }

        form {
            display: inline-block;
            margin: 3px
        }

        input,
        button,
        textarea {
            padding: 10px;
            max-width: 100%;
            box-sizing: border-box
        }

        table {
            width: 100%;
            border-collapse: collapse
        }

        td,
        th {
            border-bottom: 1px solid #ccc;
            padding: 10px;
            text-align: left;
            vertical-align: top
        }

        button {
            cursor: pointer
        }

        section {
            padding: 14px;
            background: #f5f5f5;
            border-radius: 8px;
            margin: 12px 0
        }

        .notice {
            background: #fff3cd;
            padding: 12px;
            border: 1px solid #e5b700
        }

        .reject-form {
            display: block
        }

        .reject-form textarea {
            display: block;
            width: 100%;
            min-height: 65px
        }

        @media(max-width:650px) {

            table,
            tbody,
            tr,
            td {
                display: block
            }

            thead {
                display: none
            }

            tr {
                border: 1px solid #ccc;
                margin: 10px 0
            }
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
    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="../css/theme.css">
    <script src="../js/theme.js"></script>
</head>

<body>
    <h1>MedChart — User Management</h1>
    <nav class="admin-nav" aria-label="Administration navigation">
        <a href="../index.php" class="admin-nav-btn"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <rect x="3" y="3" width="7" height="7" rx="1" />
                <rect x="14" y="3" width="7" height="7" rx="1" />
                <rect x="3" y="14" width="7" height="7" rx="1" />
                <rect x="14" y="14" width="7" height="7" rx="1" />
            </svg>Back to Dashboard</a>
        <a href="../logout.php" class="admin-nav-btn admin-nav-logout"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" />
                <path d="M16 17l5-5-5-5" />
                <path d="M21 12H9" />
            </svg>Logout</a>
    </nav>
    <?php if ($message): ?><div class="notice admin-feedback" role="status"><?= h($message) ?></div><?php endif; ?>
    <?php if ($pendingCount): ?><p class="notice" role="alert"><strong><?= (int)$pendingCount ?> registration request(s) need your attention.</strong></p><?php endif; ?>
    <section class="admin-panel">
        <h2>Administrator Notifications:</h2>
        <p>New registration requests will be emailed to this address. PHP mail() is the default, and SMTP is optional. Use Send Test Email to check delivery.</p>
        <p class="admin-email-help"><a href="../docs/email-setup.html" target="_blank" rel="noopener noreferrer" style="display:inline-flex;align-items:center;gap:8px;padding:10px 14px;border:1px solid #71839b;border-radius:6px;font-weight:600;text-decoration:none;">&#128214; How to Configure Email &amp; SMTP &#8599;</a></p>
        <form method="post" class="admin-form">
            <input type="hidden" name="csrf" value="<?= h(med_csrf()) ?>">
            <input type="hidden" name="action" value="save_email">
            <div class="admin-field admin-field-full">
                <label for="admin-email">Administrator email address</label>
                <input id="admin-email" type="email" name="admin_email" maxlength="254" value="<?= h($adminEmail) ?>" placeholder="admin@example.com" autocomplete="email">
            </div>
            <div class="admin-form-actions">
                <button type="submit">Save Email</button>
            </div>
        </form>
        <form method="post" class="admin-form" style="margin-top:12px">
            <input type="hidden" name="csrf" value="<?= h(med_csrf()) ?>">
            <input type="hidden" name="action" value="test_email">
            <div class="admin-form-actions"><button type="submit">Send Test Email</button></div>
        </form>
    </section>
    <section class="admin-panel">
        <h2>Create User:</h2>
        <form method="post" class="admin-form">
            <input type="hidden" name="csrf" value="<?= h(med_csrf()) ?>">
            <input type="hidden" name="action" value="create">
            <div class="admin-field">
                <label for="new-username">Username</label>
                <input id="new-username" name="username" placeholder="Username" minlength="3" maxlength="50" autocomplete="off" required>
            </div>
            <div class="admin-field">
                <label for="new-email">Email address (optional)</label>
                <input id="new-email" type="email" name="email" placeholder="Email (optional)" autocomplete="off">
            </div>
            <div class="admin-field admin-field-full">
                <label for="new-password">Password</label>
                <input id="new-password" type="password" name="password" placeholder="Password (8+ characters)" minlength="8" autocomplete="new-password" required>
            </div>
            <div class="admin-form-actions">
                <label class="admin-check"><input type="checkbox" name="is_admin"> Administrator account</label>
                <button type="submit">Add User</button>
            </div>
        </form>
    </section>
    <h2>Accounts</h2>
    <p><a href="security_events.php">Security events</a></p>
    <table>
        <thead>
            <tr>
                <th>Username / Email</th>
                <th>Status</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php while ($u = $users->fetch_assoc()): ?><tr>
                    <td><?= h($u['username']) ?><?= ((int)$u['id'] === $self) ? ' (You)' : '' ?><div><?= h($u['email'] ?? '') ?></div>
                    </td>
                    <td><?= $u['is_approved'] ? 'Approved' : 'Pending' ?><?= $u['is_admin'] ? ' · Admin' : '' ?></td>
                    <td>
                        <?php if ((int)$u['id'] !== $self): ?>
                            <?php $actions = $u['is_approved'] ? [$u['is_admin'] ? 'demote' : 'promote', 'reset_password', 'delete'] : ['approve'];
                            foreach ($actions as $action): ?>
                                <form method="post" onsubmit="return confirmAction(this)"><input type="hidden" name="csrf" value="<?= h(med_csrf()) ?>"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>"><input type="hidden" name="action" value="<?= h($action) ?>"><button><?= h(ucfirst(str_replace('_', ' ', $action))) ?></button></form>
                            <?php endforeach; ?>
                            <?php if (!$u['is_approved']): ?><form class="reject-form" method="post" onsubmit="return confirm('Deny this registration request?')"><input type="hidden" name="csrf" value="<?= h(med_csrf()) ?>"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>"><input type="hidden" name="action" value="reject"><label>Required reason for denial<textarea name="reason" required minlength="5" maxlength="2000" placeholder="Explain why the request was denied"></textarea></label><button>Reject request</button></form><?php endif; ?>
                        <?php endif; ?>
                    </td>
                </tr><?php endwhile; ?></tbody>
    </table>
    <script>
        function confirmAction(form) {
            return !['delete'].includes(form.elements.action.value) || confirm('Delete this account?');
        }
    </script>
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