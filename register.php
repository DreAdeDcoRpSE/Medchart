<?php
require_once 'includes/security.php';
require_once 'includes/db.php';
require_once 'includes/notifications.php';
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
$message = '';
$registered = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        http_response_code(403);
        exit('Invalid request');
    }
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $email = trim((string)($_POST['email'] ?? ''));
    if (!preg_match('/^[a-zA-Z0-9_.-]{3,50}$/', $username) || strlen($password) < 8 || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
        $message = 'Enter a valid username, email address, and a password of at least 8 characters.';
    } else {
        $existing = $conn->prepare('SELECT id FROM users WHERE username = ? OR email = ? LIMIT 1');
        $existing->bind_param('ss', $username, $email);
        $existing->execute();
        if ($existing->get_result()->fetch_assoc()) {
            $message = 'That username or email address is already associated with an account or pending request. Please choose a different username or contact the administrator.';
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare('INSERT INTO users (username,password,email,is_admin,is_approved) VALUES (?,?,?,0,0)');
            $stmt->bind_param('sss', $username, $hash, $email);
            try {
                $inserted = $stmt->execute();
            } catch (mysqli_sql_exception $e) {
                if ((int)$e->getCode() === 1062) {
                    $inserted = false;
                } else {
                    error_log('MedChart registration database error: ' . $e->getCode());
                    $inserted = false;
                }
            }
            if ($inserted) {
                $registered = true;
                $message = 'Registration received.';
                $adminEmail = med_admin_email($conn);
                if ($adminEmail) med_send_notice($adminEmail, 'MedChart: New account awaiting approval', "A new account request needs your attention.\nUsername: " . $username . "\nPlease sign in to MedChart Admin to review it.");
            } else $message = 'Unable to complete registration. That username or email address may already be in use. Please try another or contact the administrator.';
        }
    }
}
?>
<!doctype html>
<html lang="en">

<head>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta charset="utf-8">
    <title>Request an account</title>
    <style>
        body {
            font: 16px Arial, sans-serif;
            background: #f0f0f0;
            margin: 30px auto;
            max-width: 440px;
            padding: 16px
        }

        main {
            background: white;
            padding: 24px;
            border-radius: 8px
        }

        label {
            display: block;
            margin: 16px 0
        }

        input {
            box-sizing: border-box;
            width: 100%;
            padding: 12px;
            margin-top: 6px
        }

        button {
            padding: 12px 20px
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
    <style>
        .med-warning {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            margin: 20px 0;
            padding: 15px 16px;
            border: 2px solid #c58b14;
            border-left: 5px solid #c58b14;
            border-radius: 7px;
            background: #fff3c4;
            color: #493500;
            line-height: 1.45
        }

        .med-warning-icon {
            display: flex;
            align-items: center;
            justify-content: center;
            flex: 0 0 26px;
            height: 26px;
            border-radius: 50%;
            background: #a66a00;
            color: white;
            font-weight: bold;
            font-size: 19px
        }

        .med-warning strong {
            display: block;
            font-size: 17px
        }

        .med-warning p {
            margin: 6px 0 0
        }
    </style>
    <link rel="stylesheet" href="css/theme.css">
    <script src="js/theme.js"></script>
</head>

<body>
    <main>
        <h1>Request an account</h1>
        <p>All registrations require administrator approval.</p><?php if ($message && !$registered): ?><div class="med-warning" role="alert"><span class="med-warning-icon" aria-hidden="true">!</span>
                <div><strong>Warning</strong>
                    <p><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></p>
                </div>
            </div><?php endif; ?><?php if ($registered): ?><section role="status" style="background:#e9f7ed;border:2px solid #287d44;padding:20px;border-radius:8px;text-align:center;margin:20px 0">
                <h2 style="margin-top:0">Thank you for requesting an account!</h2>
                <p>Your registration request was submitted successfully.</p>
                <p><strong>You will now need to wait for approval from the site's administrator.</strong></p>
                <p>You will receive an email once your account has been reviewed. There is no need to register again.</p>
            </section><?php else: ?><form method="post"><input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf'], ENT_QUOTES, 'UTF-8') ?>"><label>Username<input name="username" required minlength="3" maxlength="50" autocomplete="username"></label><label>Email address<input type="email" name="email" required maxlength="254" autocomplete="email"></label><label>Password (at least 8 characters)<input type="password" name="password" required minlength="8" autocomplete="new-password"></label><button type="submit">Request account</button></form><?php endif; ?><p><a href="login.php">Back to login</a></p>
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