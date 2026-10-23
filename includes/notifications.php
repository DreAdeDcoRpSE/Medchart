<?php
// Email notifications use PHP mail(); configure SMTP/sendmail on the host.
function med_admin_email($conn) {
    $result=$conn->query("SELECT setting_value FROM app_settings WHERE setting_key='admin_email' LIMIT 1");
    $row=$result ? $result->fetch_assoc() : null;
    return $row ? trim($row['setting_value']) : '';
}
function med_mail_config() {
    $path = dirname(__DIR__) . '/secret/mail.php';
    $defaults = ['method' => 'mail', 'from_email' => 'no-reply@localhost.test', 'from_name' => 'MedChart',
                 'host' => '127.0.0.1', 'port' => 1025, 'security' => 'none', 'username' => '', 'password' => ''];
    if (!is_file($path)) return $defaults;
    $config = require $path;
    return is_array($config) ? array_merge($defaults, $config) : $defaults;
}

function med_smtp_read($socket) {
    $text = '';
    do {
        $line = fgets($socket, 8192);
        if ($line === false) throw new RuntimeException('SMTP server did not respond.');
        $text .= $line;
    } while (strlen($line) < 4 || $line[3] === '-');
    return [(int)substr($line, 0, 3), $text];
}
function med_smtp_command($socket, $command, $expected) {
    if ($command !== null && fwrite($socket, $command . "\r\n") === false) throw new RuntimeException('SMTP write failed.');
    [$code, $reply] = med_smtp_read($socket);
    if (!in_array($code, (array)$expected, true)) throw new RuntimeException('SMTP error code ' . $code);
    return $reply;
}
function med_smtp_send($config, $to, $subject, $body) {
    $host = trim((string)$config['host']);
    $port = (int)$config['port'];
    $security = (string)$config['security'];
    if ($host === '' || preg_match('/[\r\n]/', $host) || $port < 1 || $port > 65535 || !in_array($security, ['none','starttls','ssl'], true)) return false;
    // No unauthenticated plaintext credentials: STARTTLS/SSL is required for SMTP authentication.
    if ((string)$config['username'] !== '' && $security === 'none') return false;
    $transport = $security === 'ssl' ? 'ssl://' : 'tcp://';
    $context = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
    $socket = @stream_socket_client($transport . $host . ':' . $port, $errno, $errstr, 10, STREAM_CLIENT_CONNECT, $context);
    if (!$socket) return false;
    stream_set_timeout($socket, 15);
    try {
        med_smtp_command($socket, null, 220);
        med_smtp_command($socket, 'EHLO medchart.local', 250);
        if ($security === 'starttls') {
            med_smtp_command($socket, 'STARTTLS', 220);
            if (@stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT) !== true) throw new RuntimeException('TLS negotiation failed.');
            med_smtp_command($socket, 'EHLO medchart.local', 250);
        }
        if ((string)$config['username'] !== '') {
            med_smtp_command($socket, 'AUTH LOGIN', 334);
            med_smtp_command($socket, base64_encode((string)$config['username']), 334);
            med_smtp_command($socket, base64_encode((string)$config['password']), 235);
        }
        $from = (string)$config['from_email'];
        med_smtp_command($socket, 'MAIL FROM:<' . $from . '>', 250);
        med_smtp_command($socket, 'RCPT TO:<' . $to . '>', [250,251]);
        med_smtp_command($socket, 'DATA', 354);
        $headers = 'From: ' . $config['from_name'] . ' <' . $from . ">\r\n" .
                   'To: <' . $to . ">\r\n" .
                   'Subject: ' . '=?UTF-8?B?' . base64_encode($subject) . "?=\r\n" .
                   "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n";
        $normalized = preg_replace('/\r\n|\r|\n/', "\r\n", $body);
        $normalized = preg_replace('/(?<=\r\n)\./', '..', $normalized);
        if (str_starts_with($normalized, '.')) $normalized = '.' . $normalized;
        med_smtp_command($socket, $headers . "\r\n" . $normalized . "\r\n.", 250);
        med_smtp_command($socket, 'QUIT', 221);
        return true;
    } catch (Throwable $e) {
        error_log('MedChart SMTP delivery failed: ' . $e->getMessage());
        return false;
    } finally {
        fclose($socket);
    }
}
function med_send_notice($to, $subject, $body) {
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return false;
    $config = med_mail_config();
    $from = (string)$config['from_email'];
    $name = (string)$config['from_name'];
    if (!filter_var($from, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $name) || preg_match('/[\r\n]/', $subject)) return false;
    $subject = trim($subject);
    $body = str_replace("\r", '', $body);
    if ($config['method'] === 'smtp') return med_smtp_send($config, $to, $subject, $body);
    if ($config['method'] !== 'mail') return false;
    return @mail($to, $subject, $body, 'From: ' . $name . ' <' . $from . ">\r\nContent-Type: text/plain; charset=UTF-8\r\n");
}
function med_pending_count($conn) {
    $r=$conn->query('SELECT COUNT(*) AS total FROM users WHERE is_approved=0');
    return $r ? (int)$r->fetch_assoc()['total'] : 0;
}

// Security notification is sent to the email stored on the account, never user input.
function med_send_password_changed_notice($email, $method) {
    $when = gmdate('Y-m-d H:i:s') . ' UTC';
    $body = "The password for your MedChart account was changed.\n\n"
          . "Method: " . $method . "\n"
          . "Time: " . $when . "\n\n"
          . "If you made this change, no action is required.\n"
          . "If you did NOT make this change, contact your MedChart administrator immediately.\n\n"
          . "For your security, this email does not contain your password or medical information.\n";
    return med_send_notice($email, 'MedChart security alert: Password changed', $body);
}
