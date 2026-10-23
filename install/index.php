<?php
// MedChart first-time installer. Never run on an existing configured installation.
require_once __DIR__ . '/../includes/security.php';
$base = dirname(__DIR__);
$dbConfig = $base . '/secret/database.php';
$keyConfig = $base . '/secret/secret.php';
$lock = $base . '/secret/installed.lock';
$installed = is_file($lock) || is_file($dbConfig) || is_file($keyConfig);
$error = '';
$success = false;
// Check prerequisites before accepting any installation credentials.
$requirements = [
    ['PHP 8.1 or newer', PHP_VERSION_ID >= 80100, 'Upgrade PHP to version 8.1 or newer.'],
    ['MySQLi extension', extension_loaded('mysqli'), 'Enable the mysqli PHP extension.'],
    ['OpenSSL extension', extension_loaded('openssl'), 'Enable the openssl PHP extension.'],
    ['Cryptographic random bytes', function_exists('random_bytes'), 'Use a PHP build with random_bytes support.'],
    ['JSON extension', extension_loaded('json'), 'Enable the json PHP extension.'],
    ['PHP sessions', function_exists('session_start'), 'Enable PHP session support.'],
    ['Secret directory exists', is_dir($base.'/secret'), 'Create the secret directory inside MedChart.'],
    ['Secret directory writable', is_dir($base.'/secret') && is_writable($base.'/secret'), 'Allow PHP to write to the secret directory during setup.'],
];
$requirementsPass = !in_array(false, array_column($requirements, 1), true);

function h($value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function write_private(string $path, string $contents): void {
    if (file_exists($path)) throw new RuntimeException('Configuration file already exists.');
    $handle = @fopen($path, 'x');
    if (!$handle) throw new RuntimeException('Cannot create configuration files in the secret directory.');
    try {
        if (fwrite($handle, $contents) !== strlen($contents)) throw new RuntimeException('Unable to write configuration file.');
        fflush($handle);
    } finally { fclose($handle); }
    @chmod($path, 0600);
}
if (!$installed && $_SERVER['REQUEST_METHOD'] === 'POST' && $requirementsPass) {
    med_require_csrf();
    $host = trim((string)($_POST['host'] ?? ''));
    $port = filter_var($_POST['port'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1,'max_range'=>65535]]);
    $database = trim((string)($_POST['database'] ?? ''));
    $dbUser = trim((string)($_POST['db_user'] ?? ''));
    $dbPass = (string)($_POST['db_pass'] ?? '');
    $adminUser = trim((string)($_POST['admin_user'] ?? ''));
    $adminEmail = trim((string)($_POST['admin_email'] ?? ''));
    $adminPass = (string)($_POST['admin_pass'] ?? '');
    $adminConfirm = (string)($_POST['admin_confirm'] ?? '');
    if (!preg_match('/^[a-zA-Z0-9_.-]{3,50}$/', $adminUser)) $error = 'Administrator username must be 3–50 letters, numbers, underscores, periods or hyphens.';
    elseif (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL) || strlen($adminEmail)>254) $error = 'Enter a valid administrator email address.';
    elseif (strlen($adminPass)<12 || strlen($adminPass)>1024 || $adminPass!==$adminConfirm) $error = 'Administrator password must be at least 12 characters and both password fields must match.';
    elseif ($host==='' || strlen($host)>255 || !$port || !preg_match('/^[a-zA-Z0-9_]+$/', $database) || $dbUser==='' || strlen($dbUser)>100) $error = 'Enter valid database connection details. Database name may contain letters, numbers and underscores.';
    elseif (!extension_loaded('mysqli') || !extension_loaded('openssl') || !function_exists('random_bytes')) $error = 'PHP mysqli, OpenSSL and cryptographic random bytes are required.';
    elseif (!is_dir($base.'/secret') || !is_writable($base.'/secret')) $error = 'The secret directory must be writable during installation.';
    else {
        $created = [];
        try {
            mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
            $db = new mysqli($host, $dbUser, $dbPass, $database, (int)$port);
            $db->set_charset('utf8mb4');
            // MedChart uses features available in MySQL 5.7+/MariaDB 10.2+.
            $versionRow = $db->query('SELECT VERSION() AS version')->fetch_assoc();
            $serverVersion = (string)($versionRow['version'] ?? '');
            $isMaria = stripos($serverVersion, 'mariadb') !== false;
            if (!preg_match('/^(\d+)\.(\d+)/', $serverVersion, $vm)) {
                throw new RuntimeException('Unable to determine the database server version.');
            }
            $major = (int)$vm[1]; $minor = (int)$vm[2];
            if (($isMaria && ($major < 10 || ($major === 10 && $minor < 2))) ||
                (!$isMaria && ($major < 5 || ($major === 5 && $minor < 7)))) {
                throw new RuntimeException('MedChart requires MySQL 5.7+ or MariaDB 10.2+.');
            }
            // Require Unicode-capable database defaults, without adding explicit
            // CHARACTER SET / COLLATE declarations to individual tables.
            $defaults = $db->query('SELECT DEFAULT_CHARACTER_SET_NAME AS charset_name FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = DATABASE()')->fetch_assoc();
            if (strtolower((string)($defaults['charset_name'] ?? '')) !== 'utf8mb4') {
                throw new RuntimeException('The empty database must use utf8mb4 as its default character set. Set this in phpMyAdmin before installing.');
            }
            // Never install over an existing database containing accounts.
            $exists = $db->query("SELECT COUNT(*) AS n FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name='users'")->fetch_assoc();
            if ((int)$exists['n'] > 0) {
                $count = $db->query('SELECT COUNT(*) AS n FROM `users`')->fetch_assoc();
                if ((int)$count['n'] > 0) throw new RuntimeException('This database already has MedChart accounts. Use a new empty database; existing installations must not be reinstalled.');
            }
            // Reject any existing table rather than risk adopting an unrelated or partial database.
            $tables = $db->query('SELECT COUNT(*) AS n FROM information_schema.tables WHERE table_schema = DATABASE()')->fetch_assoc();
            if ((int)$tables['n'] > 0) throw new RuntimeException('The selected database is not empty. Use an empty database for a fresh installation.');
            foreach (require __DIR__.'/schema.php' as $sql) $db->query($sql);
            $hash = password_hash($adminPass, PASSWORD_DEFAULT);
            if (!$hash) throw new RuntimeException('Unable to secure the administrator password.');
            $db->begin_transaction();
            $stmt = $db->prepare('INSERT INTO users (username,password,email,is_admin,is_approved,must_change_password) VALUES (?,?,?,1,1,0)');
            $stmt->bind_param('sss', $adminUser, $hash, $adminEmail);
            $stmt->execute();
            $stmt = $db->prepare("INSERT INTO app_settings (setting_key,setting_value) VALUES ('admin_email',?)");
            $stmt->bind_param('s', $adminEmail);
            $stmt->execute();
            $db->commit();
            $dbSource = "<?php\nreturn " . var_export(['host'=>$host,'port'=>(int)$port,'username'=>$dbUser,'password'=>$dbPass,'database'=>$database], true) . ";\n";
            $keySource = "<?php\ndefine('ENCRYPTION_KEY', " . var_export(bin2hex(random_bytes(16)), true) . ");\ndefine('ENCRYPTION_IV', " . var_export(bin2hex(random_bytes(8)), true) . ");\n";
            write_private($dbConfig, $dbSource); $created[]=$dbConfig;
            write_private($keyConfig, $keySource); $created[]=$keyConfig;
            write_private($lock, "MedChart installed\n"); $created[]=$lock;
            $success = true;
            $installed = true;
        } catch (Throwable $e) {
            foreach ($created as $path) @unlink($path);
            error_log('MedChart installation error: '.$e->getMessage());
            $error = $e instanceof RuntimeException ? $e->getMessage() : 'Installation failed. Check database credentials, database permissions, and your PHP error log. If tables were created, use a new empty database to retry.';
        }
    }
}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>MedChart Setup</title>
<style>.checks{list-style:none;padding:0}.checks li{padding:9px 12px;margin:5px 0;border-radius:6px;border:1px solid #455869;background:#172432}.checks .pass{border-color:#517d5a}.checks .fail{border-color:#c46c6c;background:#3b252a}.checks small{display:block;color:#c7d0d9}body{font:16px/1.55 system-ui,Arial,sans-serif;background:#111a23;color:#e8eef4;margin:0;padding:35px 16px}main{max-width:690px;margin:auto;background:#1b2835;border:1px solid #425364;border-radius:12px;padding:30px;box-sizing:border-box}h1{margin-top:0}label{display:block;margin:15px 0 5px;font-weight:600}input{display:block;width:100%;box-sizing:border-box;background:#101b26;border:1px solid #667789;border-radius:6px;color:white;padding:11px;font:inherit}button{margin-top:25px;padding:12px 25px;border:0;border-radius:6px;background:#b9d1b1;color:#102118;font-weight:700;cursor:pointer}.notice{padding:13px;border:1px solid #a6c99c;border-radius:7px;background:#243b2c}.error{border-color:#de8686;background:#45282c}a{color:#bbdfc2}.hint{color:#b9c5d1;font-size:14px}</style></head><body><main>
<h1>MedChart — First-time Setup</h1>
<?php if ($success): ?><div class="notice"><strong>Installation complete.</strong> Your database, encryption key and administrator account have been created. Sign in with the administrator credentials you chose.</div><p><a href="../login.php">Go to MedChart login →</a></p><p class="hint">Keep the files in the secret directory private. Back up your encryption key securely; losing it makes existing encrypted medical records unreadable. For extra security, remove the <code>install/</code> directory after setup. The installation lock remains in place even if you leave it there.</p>
<?php elseif ($installed): ?><div class="notice">MedChart has already been configured. For security, the installer cannot run again.</div><p><a href="../login.php">Go to MedChart login →</a></p>
<?php else: ?>
<h2>System requirements</h2>
<p class="hint">MedChart checks the server before creating any tables or configuration files.</p>
<ul class="checks">
<?php foreach ($requirements as $check): ?>
<li class="<?= $check[1] ? 'pass' : 'fail' ?>"><strong><?= $check[1] ? '✓' : '✗' ?> <?=h($check[0])?></strong><?php if (!$check[1]): ?><small><?=h($check[2])?></small><?php endif; ?></li>
<?php endforeach; ?>
</ul>
<?php if (!$requirementsPass): ?><div class="notice error" role="alert">Installation cannot continue until the failed requirements are fixed. Refresh this page after making changes.</div><?php endif; ?>
<p>Set up a <strong>new, empty MySQL database</strong> in your hosting control panel or phpMyAdmin first. This installer creates the nine MedChart tables and your first administrator account. It does not delete or overwrite existing tables.</p>
<?php if ($error): ?><div class="notice error" role="alert"><?=h($error)?></div><?php endif; ?>
<?php if ($requirementsPass): ?><form method="post" autocomplete="off"><input type="hidden" name="csrf" value="<?=h(med_csrf())?>">
<h2>Database connection</h2>
<label for="host">Database host</label><input id="host" name="host" required maxlength="255" value="<?=h($_POST['host'] ?? 'localhost')?>">
<label for="port">Database port</label><input id="port" name="port" required type="number" min="1" max="65535" value="<?=h($_POST['port'] ?? '3306')?>">
<label for="database">Database name</label><input id="database" name="database" required maxlength="64" value="<?=h($_POST['database'] ?? '')?>">
<label for="db_user">Database username</label><input id="db_user" name="db_user" required maxlength="100" value="<?=h($_POST['db_user'] ?? '')?>">
<label for="db_pass">Database password</label><input id="db_pass" name="db_pass" type="password" autocomplete="new-password">
<h2>Administrator account</h2>
<label for="admin_user">Administrator username</label><input id="admin_user" name="admin_user" required maxlength="50" value="<?=h($_POST['admin_user'] ?? '')?>">
<label for="admin_email">Administrator email</label><input id="admin_email" name="admin_email" required type="email" maxlength="254" value="<?=h($_POST['admin_email'] ?? '')?>">
<label for="admin_pass">Administrator password (12+ characters)</label><input id="admin_pass" name="admin_pass" required type="password" minlength="12" autocomplete="new-password">
<label for="admin_confirm">Confirm administrator password</label><input id="admin_confirm" name="admin_confirm" required type="password" minlength="12" autocomplete="new-password">
<button type="submit">Install MedChart</button></form><?php endif; ?>
<p class="hint">PHP 8.1+, mysqli and OpenSSL required. The installer needs temporary write access to the secret directory. Use HTTPS on public hosting. The database must be empty and use utf8mb4 as its default character set.</p>
<?php endif; ?></main></body></html>
