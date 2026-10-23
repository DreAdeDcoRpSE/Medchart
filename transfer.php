<?php
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/db.php';
$uid = med_require_user($conn);
require_once __DIR__ . '/secret/secret.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/transfer.php';
$message = '';
$error = '';
$preview = null;
$importResponse = false;
// Per-session online retry limit. This does not prevent offline attacks against a stolen file.
function med_transfer_retry_state(): array {
    $state = $_SESSION['med_transfer_retries'] ?? ['failures' => 0, 'since' => time()];
    if (!is_array($state) || !isset($state['failures'], $state['since']) || time() - (int)$state['since'] >= 600) {
        $state = ['failures' => 0, 'since' => time()];
    }
    return $state;
}
function med_transfer_retry_check(): void {
    $state = med_transfer_retry_state();
    $_SESSION['med_transfer_retries'] = $state;
    if ($state['failures'] >= 8) {
        throw new RuntimeException('Too many unsuccessful decryption attempts. Please wait 10 minutes before trying again.');
    }
}
function med_transfer_retry_failure(): void {
    $state = med_transfer_retry_state();
    $state['failures']++;
    $_SESSION['med_transfer_retries'] = $state;
}
function med_transfer_retry_success(): void {
    unset($_SESSION['med_transfer_retries']);
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    med_require_csrf();
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'export') {
            $password = (string)($_POST['export_password'] ?? '');
            if (!med_transfer_password_valid($password)) throw new RuntimeException('Choose a stronger export password or passphrase.');
            if (!hash_equals($password, (string)($_POST['export_confirm'] ?? ''))) throw new RuntimeException('Passwords do not match.');
            $file = med_transfer_encrypt(med_transfer_export($conn, $uid), $password);
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="medchart-' . gmdate('Ymd-His') . '.medchart"');
            header('Content-Length: ' . strlen($file));
            header('X-Content-Type-Options: nosniff');
            echo $file;
            exit;
        }
        if ($action === 'preview' || $action === 'import') {
            $importResponse = true;
            $password = (string)($_POST['import_password'] ?? '');
            if ($password === '') throw new RuntimeException('Enter the exact transfer password or passphrase.');
            med_transfer_retry_check();
            $upload = $_FILES['transfer_file'] ?? null;
            if (!$upload || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || ($upload['size'] ?? 0) > 4 * 1024 * 1024 || ($upload['size'] ?? 0) < 1) throw new RuntimeException('Choose a valid .medchart file smaller than 4 MB.');
            if (strtolower(pathinfo($upload['name'] ?? '', PATHINFO_EXTENSION)) !== 'medchart') throw new RuntimeException('Choose a .medchart file.');
            // Validate the actual temporary upload, not just the client-provided size.
            $tmp = $upload['tmp_name'] ?? '';
            if (!is_string($tmp) || !is_uploaded_file($tmp)) throw new RuntimeException('Invalid uploaded transfer file.');
            $actualSize = filesize($tmp);
            if ($actualSize === false || $actualSize < 1 || $actualSize > 4 * 1024 * 1024) throw new RuntimeException('Choose a valid .medchart file smaller than 4 MB.');
            $handle = fopen($tmp, 'rb');
            if ($handle === false) throw new RuntimeException('Unable to read uploaded transfer file.');
            try { $raw = stream_get_contents($handle, 4 * 1024 * 1024 + 1); }
            finally { fclose($handle); }
            if ($raw === false || strlen($raw) !== $actualSize || strlen($raw) > 4 * 1024 * 1024) throw new RuntimeException('Unable to read uploaded transfer file.');
            try {
                $data = med_transfer_decrypt($raw, $password);
                med_transfer_retry_success();
            } catch (RuntimeException $e) {
                if ($e->getMessage() === 'Incorrect password or damaged transfer file.') med_transfer_retry_failure();
                throw $e;
            }
            $preview = ['medications' => count($data['medications']), 'allergies' => count($data['allergies']), 'team' => count($data['team']), 'patient' => ($data['patient']['name'] !== '' || $data['patient']['dob'] !== '' || $data['patient']['notes'] !== '')];
            if ($action === 'import') {
                $mode = $_POST['mode'] ?? 'merge';
                if ($mode === 'replace' && ($_POST['replace_confirm'] ?? '') !== 'REPLACE') throw new RuntimeException('To replace records, type REPLACE into the confirmation field.');
                $counts = med_transfer_import($conn, $uid, $data, $mode);
                $message = 'Import completed. Added ' . $counts['medications'] . ' medications, ' . $counts['allergies'] . ' allergies, and ' . $counts['team'] . ' medical team members.';
                $preview = null;
            }
        }
    } catch (Throwable $e) {
        error_log('MedChart transfer operation failed: ' . $e->getMessage());
        if ($action === 'export') {
            $error = ($e instanceof RuntimeException && in_array($e->getMessage(), ['Choose a stronger export password or passphrase.', 'Passwords do not match.'], true))
                ? $e->getMessage()
                : 'Export failed while preparing or encrypting your medical data. Check the PHP error log for the exact cause.';
        } else {
            if ($action === 'preview' || $action === 'import') $importResponse = true;
            $safeMessages = [
                'Incorrect password or damaged transfer file.',
                'Too many unsuccessful decryption attempts. Please wait 10 minutes before trying again.',
                'Invalid uploaded transfer file.',
                'Unable to read uploaded transfer file.',
                'This transfer file requires the PHP sodium extension. Enable sodium to import it.',
                'Unsupported transfer key derivation.',
                'Unsupported Argon2id settings.',
                'Unsupported PBKDF2 settings.',
                'Invalid transfer file.',
                'Unsupported transfer file.',
                'Invalid transfer file encoding.',
                'Transfer file exceeds 4 MB.',
                'Choose a valid .medchart file smaller than 4 MB.',
                'Choose a .medchart file.',
                'Enter the exact transfer password or passphrase.',
                'To replace records, type REPLACE into the confirmation field.',
            ];
            $error = ($e instanceof RuntimeException && in_array($e->getMessage(), $safeMessages, true))
                ? $e->getMessage()
                : 'Import failed while reading or saving the transfer file. Check the PHP error log for the exact cause.';
        }
    }
}
function esc($v)
{
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Import / Export — MedChart</title>
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/theme.css">
    <script src="js/theme.js"></script>
    <style>
        .transfer-wrap {
            max-width: 850px;
            margin: 25px auto;
            padding: 16px
        }

        .transfer-card {
            border: 1px solid #778392;
            border-radius: 9px;
            padding: 22px;
            margin: 18px 0
        }

        .transfer-card label {
            display: block;
            margin: 12px 0 5px;
            font-weight: 600
        }

        .transfer-card input[type=password],
        .transfer-card input[type=text],
        .transfer-card input[type=file] {
            box-sizing: border-box;
            max-width: 100%;
            width: 100%;
            padding: 11px;
            border: 1px solid #8995a5;
            border-radius: 5px
        }

        .transfer-card button {
            margin: 12px 8px 4px 0;
            padding: 10px 16px;
            cursor: pointer
        }

        .transfer-status {
            padding: 14px;
            border: 2px solid;
            border-radius: 6px;
            margin: 14px 0
        }

        .transfer-success {
            border-color: #21855a;
            background: #e7f6ed;
            color: #155b3d
        }

        .transfer-error {
            border-color: #b33434;
            background: #ffecec;
            color: #842525
        }

        .transfer-checks {
            list-style: none;
            padding-left: 0
        }

        .transfer-checks li {
            padding: 4px 0
        }

        .transfer-checks .good {
            color: #16814e
        }

        .transfer-checks .muted {
            opacity: .65
        }

        .transfer-checks .waived {
            text-decoration: line-through;
            opacity: .55
        }

        .transfer-note {
            border-left: 4px solid #c58d24;
            padding: 10px 14px;
            margin: 15px 0
        }

        .transfer-mode {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            margin: 10px 0
        }

        .transfer-mode label {
            font-weight: normal
        }

        .transfer-passrow {
            display: flex;
            gap: 8px
        }

        .transfer-passrow input {
            flex: 1;
            min-width: 0
        }

        .transfer-passrow button {
            white-space: nowrap
        }

        .transfer-card p {
            line-height: 1.5
        }
    </style>
</head>

<body>
    <main class="transfer-wrap">
        <p><a href="index.php">&larr; Back to Dashboard</a></p>
        <h1>Import / Export Medical Data</h1>
        <p>Move your own medical records between MedChart installations. Files are encrypted with your chosen export password. Your website's private encryption key is never included.</p>
        <?php if (!$importResponse && $message): ?><div class="transfer-status transfer-success" role="status"><?= esc($message) ?></div><?php endif; ?>
        <?php if (!$importResponse && $error): ?><div class="transfer-status transfer-error" role="alert"><?= esc($error) ?></div><?php endif; ?>
        <section class="transfer-card">
            <h2>Export My Medical Data</h2>
            <p>Choose a password or a long passphrase. You must enter it <strong>exactly the same way</strong> when importing, including capitals, punctuation, and spaces. MedChart cannot recover a forgotten export password.</p>
            <form method="post" id="export-form"><input type="hidden" name="csrf" value="<?= esc(med_csrf()) ?>"><input type="hidden" name="action" value="export"><label for="export-password">Export password or passphrase</label>
                <div class="transfer-passrow"><input type="password" id="export-password" name="export_password" autocomplete="new-password" required maxlength="1024"><button type="button" class="show-pass" data-target="export-password">Show</button></div>
                <ul class="transfer-checks" id="password-checks">
                    <li data-check="length">○ At least 10 characters</li>
                    <li data-check="upper">○ One capital letter</li>
                    <li data-check="digit">○ One number</li>
                    <li data-check="special">○ One special character</li>
                    <li data-check="phrase">○ OR a long passphrase: 24+ characters and at least 5 words</li>
                </ul>
                <p id="password-feedback" aria-live="polite">Enter a password to see the requirements.</p>
                <label for="export-confirm">Confirm export password</label>
                <div class="transfer-passrow"><input type="password" id="export-confirm" name="export_confirm" autocomplete="new-password" required><button type="button" class="show-pass" data-target="export-confirm">Show</button></div>
                <p id="match-feedback" aria-live="polite"></p><button type="submit" id="export-button" disabled>Download Encrypted .medchart File</button>
            </form>
        </section>
        <section class="transfer-card" id="import-section">
            <h2>Import Medical Data</h2><?php if ($importResponse && $message): ?><div class="transfer-status transfer-success" id="import-result" role="status" tabindex="-1"><?= esc($message) ?></div><?php endif; ?><?php if ($importResponse && $error): ?><div class="transfer-status transfer-error" id="import-result" role="alert" tabindex="-1"><?= esc($error) ?><?php if ($error === 'Incorrect password or damaged transfer file.'): ?><p><strong>Why can't MedChart be more specific?</strong> For security reasons, an incorrect password and changes to encrypted file contents both cause the same decryption failure. MedChart cannot reliably tell which one happened.</p>
                    <p>Double-check the exact password or passphrase, including capital letters, spaces, and punctuation. If you are sure it is correct, try exporting a fresh copy of the file.</p><?php endif; ?>
                </div><?php endif; ?><?php if ($preview): ?><div class="transfer-status transfer-success" id="import-preview-result" role="status" tabindex="-1"><strong>Preview successful — file decrypted and validated.</strong>
                    <p>Contains <?= esc($preview['medications']) ?> medications, <?= esc($preview['allergies']) ?> allergies, <?= esc($preview['team']) ?> medical team entries, and <?= $preview['patient'] ? 'patient information' : 'no patient information' ?>.</p>
                    <p>This preview shows the number of records in your file; it does not import or change anything. To import, choose the file again, enter the password, select Merge or Replace, and click Import.</p>
                </div><?php endif; ?><p>Choose an encrypted <code>.medchart</code> file and enter the exact password used to export it. The file is decrypted on this server, then your imported records are encrypted using this installation's key.</p>
            <form method="post" enctype="multipart/form-data" id="import-form"><input type="hidden" name="csrf" value="<?= esc(med_csrf()) ?>"><label for="transfer-file">Encrypted file (up to 4 MB)</label><input type="file" id="transfer-file" name="transfer_file" accept=".medchart" required><label for="import-password">Transfer password or passphrase</label>
                <div class="transfer-passrow"><input type="password" id="import-password" name="import_password" required><button type="button" class="show-pass" data-target="import-password">Show</button></div><button type="submit" name="action" value="preview">Preview File</button>
                <div class="transfer-mode"><label><input type="radio" name="mode" value="merge" checked> <strong>Merge</strong> (keep existing records; skip matching entries)</label><label><input type="radio" name="mode" value="replace"> <strong>Replace</strong> (delete current medical data, then import)</label></div>
                <div class="transfer-note"><strong>Important:</strong> Replace permanently deletes your current medications, allergies, medical team and patient information before importing. Export a backup first. Merge does not overwrite existing patient information.</div><label for="replace-confirm">For Replace only, type <strong>REPLACE</strong> to confirm</label><input type="text" id="replace-confirm" name="replace_confirm" autocomplete="off" placeholder="Only required when replacing"><button type="submit" name="action" value="import" id="import-button">Import Medical Data</button>
            </form>

        </section>
    </main>
    <script>
        (() => {
            if (<?= $importResponse ? 'true' : 'false' ?>) {
                const target = document.getElementById('import-result') || document.getElementById('import-preview-result') || document.getElementById('import-section');
                if (target) {
                    target.scrollIntoView({
                        block: 'start'
                    });
                    if (target.id === 'import-preview-result' || target.id === 'import-result') target.focus({
                        preventScroll: true
                    });
                }
            }
            const p = document.getElementById('export-password'),
                c = document.getElementById('export-confirm'),
                b = document.getElementById('export-button'),
                f = document.getElementById('password-feedback'),
                m = document.getElementById('match-feedback');
            const checks = document.querySelectorAll('[data-check]');

            function update() {
                const v = p.value;
                const phrase = v.length >= 24 && v.trim().split(/\s+/).filter(Boolean).length >= 5;
                const rules = {
                    length: v.length >= 10,
                    upper: /[A-Z]/.test(v),
                    digit: /\d/.test(v),
                    special: /[^A-Za-z0-9\s]/.test(v),
                    phrase
                };
                const weak = /password|qwerty|123456|letmein|correct horse battery staple/i.test(v);
                const valid = (rules.length && rules.upper && rules.digit && rules.special || phrase) && !weak && v.length <= 1024;
                checks.forEach(li => {
                    const key = li.dataset.check,
                        pass = rules[key];
                    li.className = phrase && key !== 'phrase' ? 'waived' : pass ? 'good' : 'muted';
                    li.textContent = (phrase && key !== 'phrase' ? '— ' : pass ? '✓ ' : '○ ') + ({
                        length: 'At least 10 characters',
                        upper: 'One capital letter',
                        digit: 'One number',
                        special: 'One special character',
                        phrase: 'OR a long passphrase: 24+ characters and at least 5 words'
                    } [key]);
                });
                f.textContent = weak ? 'Avoid common or predictable passwords.' : phrase ? '✓ Long passphrase accepted; character rules waived.' : valid ? '✓ Password requirements met.' : 'Use the checklist above or choose a long passphrase.';
                m.textContent = c.value ? (c.value === v ? '✓ Passwords match.' : 'Passwords do not match.') : '';
                b.disabled = !valid || c.value !== v;
            }
            p.addEventListener('input', update);
            c.addEventListener('input', update);
            document.querySelectorAll('.show-pass').forEach(btn => btn.addEventListener('click', () => {
                const el = document.getElementById(btn.dataset.target);
                el.type = el.type === 'password' ? 'text' : 'password';
                btn.textContent = el.type === 'password' ? 'Show' : 'Hide';
            }));
            update();
            document.getElementById('import-form').addEventListener('submit', e => {
                if (e.submitter && e.submitter.value === 'import' && document.querySelector('input[name=mode]:checked').value === 'replace' && !confirm('Replace ALL existing medical records for this account? This cannot be undone.')) e.preventDefault();
            });
        })();
    </script>
</body>

</html>