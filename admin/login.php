<?php
require_once __DIR__ . '/../includes/admin.php';
admin_boot();

if (!empty($_SESSION['admin_id'])) {
    redirect(admin_url());
}
$error = '';
$email = strtolower(trim((string) ($_POST['email'] ?? '')));
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_ok()) {
        $error = 'The form expired. Please sign in again.';
    } elseif (throttle_blocked('admin', $email)) {
        $error = 'Too many attempts. Wait 15 minutes and try again.';
    } else {
        $a = row('SELECT * FROM admins WHERE email = ? AND is_active = 1', [$email]);
        if ($a && password_verify((string) ($_POST['password'] ?? ''), $a['password_hash'])) {
            throttle_clear('admin', $email);
            session_regenerate_id(true);
            $_SESSION['admin_id'] = (int) $a['id'];
            $_SESSION['admin_seen'] = time();
            update_row('admins', ['last_login_at' => now()], 'id = ?', [(int) $a['id']]);
            $next = safe_local((string) ($_GET['next'] ?? ''), admin_url());
            redirect(strpos($next, base_path() . '/admin/') === 0 ? $next : admin_url());
        }
        throttle_hit('admin', $email);
        $error = 'Email or password is incorrect.';
    }
}
header('Content-Type: text/html; charset=utf-8');
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow">
<title>Admin sign in</title><link rel="stylesheet" href="<?= e(asset('css/admin.css')) ?>"></head>
<body class="login-page">
<main class="login-card">
  <img src="<?= e(site_img('logo.png') ?: asset('img/logo.svg')) ?>" alt="" width="64" height="64">
  <h1><?= e(setting('store_name', 'Store')) ?> admin</h1>
  <?php if ($error): ?><p class="note note-err" role="alert"><?= e($error) ?></p><?php endif; ?>
  <form method="post">
    <?= csrf_field() ?>
    <label class="fld"><span>Email</span><input type="email" name="email" required autocomplete="username" value="<?= e($email) ?>"></label>
    <label class="fld"><span>Password</span><input type="password" name="password" required autocomplete="current-password"></label>
    <button class="btn btn-wide" type="submit">Sign in</button>
  </form>
</main>
</body></html>
