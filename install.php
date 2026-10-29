<?php
/**
 * One-time installer. Open /install.php in the browser and fill in the form.
 * It creates the tables, loads the vermionline.in catalogue and pages, creates the first admin and writes config.php.
 * Once config.php exists it refuses to run again. Delete this file from the server after installing.
 */
define('APP_INSTALLER', true);
require_once __DIR__ . '/includes/app.php';
require_once __DIR__ . '/includes/seed.php';

header('Content-Type: text/html; charset=utf-8');
header('X-Frame-Options: DENY');
$installed = is_array($GLOBALS['APP_CONFIG']);
$base = app_detect_base();
$checks = [
    'PHP 7.4 or newer (this server: ' . PHP_VERSION . ')' => [PHP_VERSION_ID >= 70400, true],
    'PDO MySQL extension' => [extension_loaded('pdo_mysql'), true],
    'zip, dom and xmlreader extensions (Excel import and export)' => [class_exists('ZipArchive') && class_exists('DOMDocument') && class_exists('XMLReader'), false],
    'mbstring extension' => [extension_loaded('mbstring'), false],
    'storage/imports is writable' => [is_writable(APP_ROOT . '/storage/imports'), true],
    'storage/logs is writable' => [is_writable(APP_ROOT . '/storage/logs'), false],
    'assets/uploads/products is writable' => [is_writable(APP_ROOT . '/assets/uploads/products'), false],
    'assets/img is writable (for pictures copied from the reference site)' => [is_writable(APP_ROOT . '/assets/img'), false],
];
$host = preg_replace('/[^A-Za-z0-9.\-:]/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
$f = ['db_host' => 'localhost', 'db_port' => '3306', 'db_name' => '', 'db_user' => '', 'site_url' => (is_https() ? 'https://' : 'http://') . $host . $base,
    'admin_name' => '', 'admin_email' => '', 'seed' => '1'];
$errors = [];
$done = [];
$configText = '';

if (!$installed && $_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach (array_keys($f) as $k) {
        $f[$k] = trim((string) ($_POST[$k] ?? ''));
    }
    $dbPass = (string) ($_POST['db_pass'] ?? '');
    $pass = (string) ($_POST['admin_pass'] ?? '');
    foreach ($checks as $label => $c) {
        if ($c[1] && !$c[0]) {
            $errors[] = 'Fix this first: ' . $label . '.';
        }
    }
    if ($f['db_name'] === '' || $f['db_user'] === '') {
        $errors[] = 'Enter the database name and user from Hostinger (Databases > MySQL Databases).';
    }
    if (!preg_match('#^https?://[^\s/]+#i', $f['site_url'])) {
        $errors[] = 'Enter the store address, starting with https://';
    }
    if (strlen($f['admin_name']) < 2 || !valid_email(strtolower($f['admin_email']))) {
        $errors[] = 'Enter your name and a valid email for the admin account.';
    }
    if (strlen($pass) < 10) {
        $errors[] = 'Use at least 10 characters for the admin password.';
    } elseif ($pass !== (string) ($_POST['admin_pass2'] ?? '')) {
        $errors[] = 'The two admin passwords do not match.';
    }
    $pdo = null;
    if (!$errors) {
        try {
            $pdo = new PDO('mysql:host=' . $f['db_host'] . ';port=' . (int) $f['db_port'] . ';dbname=' . $f['db_name'] . ';charset=utf8mb4', $f['db_user'], $dbPass,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $pdo->exec("SET time_zone = '+05:30'");
            if ($pdo->query("SHOW TABLES LIKE 'admins'")->fetchColumn() && (int) $pdo->query('SELECT COUNT(*) FROM admins')->fetchColumn() > 0) {
                $errors[] = 'This database already holds a store with admin users. Use a new, empty database.';
            }
        } catch (PDOException $e) {
            $errors[] = 'Could not connect to the database: ' . $e->getMessage();
        }
    }
    if (!$errors) {
        try {
            $sql = (string) preg_replace('/^\s*--.*$/m', '', (string) file_get_contents(APP_ROOT . '/database/schema.sql'));
            foreach (preg_split('/;\s*(?:\r?\n|$)/', $sql) as $stmt) {
                if (trim($stmt) !== '') {
                    $pdo->exec($stmt);
                }
            }
            $done = seed_run($pdo, $f['seed'] === '1');
            $pdo->prepare('INSERT INTO admins (name, email, password_hash, created_at) VALUES (?, ?, ?, NOW())')
                ->execute([str_cut($f['admin_name'], 80), strtolower($f['admin_email']), password_hash($pass, PASSWORD_DEFAULT)]);
            $done[] = 'admin account for ' . strtolower($f['admin_email']);
        } catch (PDOException $e) {
            $errors[] = 'Creating the tables failed: ' . $e->getMessage();
        }
    }
    if (!$errors) {
        $config = ['db_host' => $f['db_host'], 'db_port' => (int) $f['db_port'], 'db_name' => $f['db_name'], 'db_user' => $f['db_user'], 'db_pass' => $dbPass,
            'base_path' => $base, 'site_url' => rtrim($f['site_url'], '/'), 'app_secret' => bin2hex(random_bytes(32)), 'debug' => false];
        $text = "<?php\n// Written by install.php on " . date('j M Y, H:i') . ". Keep this file private: .htaccess blocks it from the web.\nreturn " . var_export($config, true) . ";\n";
        if (@file_put_contents(APP_ROOT . '/config.php', $text, LOCK_EX) === false) {
            $configText = $text;
        } else {
            @chmod(APP_ROOT . '/config.php', 0640);
        }
    }
}
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">
<title>Install the store</title><link rel="stylesheet" href="assets/css/admin.css"></head>
<body class="login-page"><main class="install">
<h1>Install the store</h1>
<?php if ($installed): ?>
  <p class="note note-ok">The store is already installed. For safety this installer does nothing now. Delete <strong>install.php</strong> from the server.</p>
  <p><a class="btn" href="<?= e($base . '/admin/') ?>">Go to the admin panel</a></p>
<?php elseif ($done && !$errors): ?>
  <p class="note note-ok">Installed: <?= e(implode(', ', $done)) ?>.</p>
  <?php if ($configText !== ''): ?>
    <p class="note note-warn">The server did not let the installer write <strong>config.php</strong>. Create a file named config.php in the store folder with exactly this content:</p>
    <textarea class="mono" rows="16" readonly style="width:100%"><?= e($configText) ?></textarea>
  <?php endif; ?>
  <ol>
    <li>Delete <strong>install.php</strong> from the server now.</li>
    <li>Sign in to the <a href="<?= e($base . '/admin/') ?>">admin panel</a>.</li>
    <li>In Settings > PayU, enter your PayU key and salt (test mode first).</li>
    <li>In Tools, press "Copy missing pictures" to bring the logo and product photos over from vermionline.in.</li>
    <li>Place one small test order, then import a PayU file under Import PayU file.</li>
  </ol>
<?php else: ?>
  <?php if ($errors): ?><div class="note note-err"><?= implode('<br>', array_map('e', $errors)) ?></div><?php endif; ?>
  <section class="panel"><h2>Server check</h2><ul class="checks">
    <?php foreach ($checks as $label => $c): ?><li class="<?= $c[0] ? 'ok' : ($c[1] ? 'bad' : 'warn') ?>"><?= $c[0] ? 'OK' : ($c[1] ? 'Required' : 'Recommended') ?>: <?= e($label) ?></li><?php endforeach; ?>
  </ul></section>
  <form method="post" class="panel">
    <h2>Database</h2>
    <p class="muted">Create an empty MySQL database and user in Hostinger hPanel (Databases > MySQL Databases), then enter them here.</p>
    <div class="grid-form">
      <label class="fld"><span>Database host</span><input name="db_host" required value="<?= e($f['db_host']) ?>"></label>
      <label class="fld"><span>Port</span><input name="db_port" required value="<?= e($f['db_port']) ?>"></label>
      <label class="fld"><span>Database name</span><input name="db_name" required value="<?= e($f['db_name']) ?>"></label>
      <label class="fld"><span>Database user</span><input name="db_user" required value="<?= e($f['db_user']) ?>"></label>
      <label class="fld"><span>Database password</span><input name="db_pass" type="password" autocomplete="off"></label>
      <label class="fld"><span>Store address</span><input name="site_url" required value="<?= e($f['site_url']) ?>"></label>
    </div>
    <h2>First admin</h2>
    <div class="grid-form">
      <label class="fld"><span>Your name</span><input name="admin_name" required value="<?= e($f['admin_name']) ?>"></label>
      <label class="fld"><span>Email</span><input name="admin_email" type="email" required value="<?= e($f['admin_email']) ?>"></label>
      <label class="fld"><span>Password (10+ characters)</span><input name="admin_pass" type="password" minlength="10" required autocomplete="new-password"></label>
      <label class="fld"><span>Repeat password</span><input name="admin_pass2" type="password" minlength="10" required autocomplete="new-password"></label>
    </div>
    <label class="chk"><input type="checkbox" name="seed" value="1"<?= $f['seed'] === '1' ? ' checked' : '' ?>> Load the 22 products and 2 categories from vermionline.in (pages, settings and the loose compost item are always loaded)</label>
    <button class="btn" type="submit">Install</button>
  </form>
<?php endif; ?>
</main></body></html>
