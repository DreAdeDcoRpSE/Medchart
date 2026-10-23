<?php
require_once 'includes/security.php';
require_once 'includes/db.php';
$error = '';
$ip = substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 45);
$locked = false;
$lockSeconds = 0;
// Determine lockout from the database clock, including on a normal GET request.
function med_login_lockout(mysqli $conn, string $ip, string $username = ''): int
{
    $sql = 'SELECT UNIX_TIMESTAMP(attempted_at) AS ts, UNIX_TIMESTAMP(NOW()) AS now_ts FROM login_attempts WHERE attempted_at >= DATE_SUB(NOW(), INTERVAL 10 MINUTE) AND (ip_address=?';
    if ($username !== '') $sql .= ' OR username=?';
    $sql .= ') ORDER BY attempted_at DESC LIMIT 4,1';
    $stmt = $conn->prepare($sql);
    if ($username !== '') $stmt->bind_param('ss', $ip, $username);
    else $stmt->bind_param('s', $ip);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return $row ? max(0, (int)$row['ts'] + 600 - (int)$row['now_ts']) : 0;
}
$lockSeconds = med_login_lockout($conn, $ip);
$locked = $lockSeconds > 0;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    med_require_csrf();
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $lockSeconds = med_login_lockout($conn, $ip, $username);
    $locked = $lockSeconds > 0;
    if ($locked) {
        $error = 'Too many login attempts. Please wait for the lockout to expire before trying again.';
    } else {
        $stmt = $conn->prepare('SELECT * FROM users WHERE username=? LIMIT 1');
        $stmt->bind_param('s', $username);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        if ($user && password_verify($password, $user['password']) && (int)$user['is_approved'] === 1) {
            $clear = $conn->prepare('DELETE FROM login_attempts WHERE username=? AND ip_address=?');
            $clear->bind_param('ss', $username, $ip);
            $clear->execute();
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int)$user['id'];
            $_SESSION['is_admin'] = (int)$user['is_admin'];
            $_SESSION['password_fingerprint'] = hash('sha256', $user['password']);
            $_SESSION['last_activity'] = time();
            med_log($conn, 'login_success', $username);
            header('Location: ' . (!empty($user['must_change_password']) ? 'change_password.php' : 'index.php'));
            exit;
        }
        $insert = $conn->prepare('INSERT INTO login_attempts (username,ip_address) VALUES (?,?)');
        $insert->bind_param('ss', $username, $ip);
        $insert->execute();
        med_log($conn, 'login_failure', $username);
        $lockSeconds = med_login_lockout($conn, $ip, $username);
        $locked = $lockSeconds > 0;
        if ($locked) {
            med_log($conn, 'login_lockout', $username);
            $error = 'Too many login attempts. Please wait for the lockout to expire before trying again.';
        } else $error = 'Invalid credentials or account pending approval.';
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Medical Tracker</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #f0f0f0;
            margin: 0;
            padding: 0;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            /* Full viewport height */
        }

        .login-container {
            background-color: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
            width: 90%;
            max-width: 400px;
            /* Max width for larger screens */
            box-sizing: border-box;
        }

        h2 {
            text-align: center;
            margin-top: 0;
            margin-bottom: 20px;
            color: #333;
        }

        .form-group {
            margin-bottom: 15px;
        }

        label {
            display: block;
            /* Makes labels full width */
            margin-bottom: 5px;
            font-weight: bold;
            color: #555;
        }

        input[type="text"],
        input[type="password"] {
            width: 100%;
            padding: 12px;
            /* Adequate touch target size */
            border: 1px solid #ccc;
            border-radius: 4px;
            box-sizing: border-box;
            /* Includes padding/border in width */
            font-size: 1em;
            /* Readable font size */
        }

        input[type="submit"] {
            width: 100%;
            padding: 12px;
            background-color: #007bff;
            /* Bootstrap-like primary color */
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 1em;
            font-weight: bold;
        }

        input[type="submit"]:hover {
            background-color: #0056b3;
            /* Darker shade on hover */
        }

        .error-message {
            color: #842029;
            background: #f8d7da;
            border: 1px solid #f5a9b1;
            border-radius: 6px;
            padding: 12px;
            text-align: center;
            margin-bottom: 15px;
            font-weight: bold;
        }

        input:disabled,
        button:disabled {
            cursor: not-allowed;
            opacity: .55
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
    <div class="login-container">
        <h2>Login</h2>
        <?php if ($error !== ''): ?>
            <p class="error-message"><?php echo htmlspecialchars($error); ?></p>
        <?php endif; ?>
        <?php if ($locked): ?><div class="error-message" role="alert">Login is temporarily disabled. Please try again in <span id="lockout-countdown" data-seconds="<?= (int)$lockSeconds ?>"><?= (int)ceil($lockSeconds / 60) ?> minute(s)</span>.</div><?php endif; ?>
        <form method="post"><input type="hidden" name="csrf" value="<?= htmlspecialchars(med_csrf(), ENT_QUOTES, 'UTF-8') ?>">
            <div class="form-group">
                <label for="username">Username:</label>
                <input type="text" id="username" name="username" required <?= $locked ? 'disabled' : '' ?>>
            </div>
            <div class="form-group">
                <label for="password">Password:</label>
                <input type="password" id="password" name="password" required <?= $locked ? 'disabled' : '' ?>>
            </div>
            <input type="submit" value="Login" <?= $locked ? 'disabled' : '' ?>>
        </form>
        <p style="text-align:center"><a href="forgot_password.php">Forgot password?</a></p>
        <p style="text-align:center"><a href="register.php">Request an account</a></p>
    </div>
    <script>
        (function() {
            const el = document.getElementById('lockout-countdown');
            if (el) {
                let seconds = Number(el.dataset.seconds) || 0;
                const timer = setInterval(function() {
                    seconds = Math.max(0, seconds - 1);
                    if (seconds === 0) {
                        clearInterval(timer);
                        location.reload();
                        return;
                    }
                    el.textContent = Math.floor(seconds / 60) + 'm ' + String(seconds % 60).padStart(2, '0') + 's';
                }, 1000);
                el.textContent = Math.floor(seconds / 60) + 'm ' + String(seconds % 60).padStart(2, '0') + 's';
            }
        })();
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
                btn.disabled = input.disabled;
                wrapper.appendChild(btn);
            });
        })();
    </script>
</body>

</html>