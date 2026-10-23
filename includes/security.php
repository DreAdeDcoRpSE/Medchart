<?php
// Central security bootstrap. Keep this file free of medical data.
if (session_status() !== PHP_SESSION_ACTIVE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ini_set('session.cookie_secure', '1');
    session_start();
}
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store, private');
header("Content-Security-Policy: frame-ancestors 'none'");
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
function med_csrf() { return $_SESSION['csrf']; }
function med_require_csrf()
{
    $token = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!is_string($token) || !hash_equals($_SESSION['csrf'], $token)) {
        http_response_code(403); exit('Invalid security token. Reload and try again.');
    }
}
function med_require_user($conn) {
    if (!empty($_SESSION['user_id']) && time() - (int)($_SESSION['last_activity'] ?? 0) > 1800) {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
        http_response_code(401);
        exit('Session expired. Please log in again.');
    }
    if (empty($_SESSION['user_id'])) { http_response_code(401); exit('Authentication required'); }
    $id=(int)$_SESSION['user_id'];
    $stmt=$conn->prepare('SELECT is_admin,is_approved,password,must_change_password FROM users WHERE id=? LIMIT 1');
    $stmt->bind_param('i',$id); $stmt->execute(); $u=$stmt->get_result()->fetch_assoc();
    if (!$u || !(int)$u['is_approved']) { http_response_code(403); exit('Account unavailable'); }
    $_SESSION['is_admin']=(int)$u['is_admin'];
    if (empty($_SESSION['password_fingerprint']) || !hash_equals((string)$_SESSION['password_fingerprint'], hash('sha256',$u['password']))) {
        $_SESSION = []; session_destroy();
        http_response_code(401); exit('Please log in again');
    }
    $_SESSION['last_activity']=time();
    if (!empty($u['must_change_password']) && basename($_SERVER['SCRIPT_NAME']) !== 'change_password.php') {
        http_response_code(403); exit('You must change your temporary password. Open change_password.php');
    }
    return $id;
}
function med_log($conn,$type,$username='') {
    $ip=substr($_SERVER['REMOTE_ADDR'] ?? '',0,45);
    $stmt=$conn->prepare('INSERT INTO security_events (event_type,username,ip_address) VALUES (?,?,?)');
    $stmt->bind_param('sss',$type,$username,$ip); $stmt->execute();
}
