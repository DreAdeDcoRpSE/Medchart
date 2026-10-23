<?php
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/db.php';
if (empty($_SESSION['user_id'])) { header('Location: login.php'); exit; }
med_require_user($conn);
