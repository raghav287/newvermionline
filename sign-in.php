<?php
/** Customer sign in, create account, forgot password, set new password and sign out. /sign-up maps here. */
require_once __DIR__ . '/includes/app.php';
require_once __DIR__ . '/includes/store.php';
require_once __DIR__ . '/includes/layout.php';
store_boot();

$mode = in_array($_GET['mode'] ?? '', ['login', 'register', 'forgot', 'reset'], true) ? $_GET['mode'] : 'login';
$next = in_array($_GET['next'] ?? '', ['checkout', 'account'], true) ? $_GET['next'] : 'account';
$err = '';
$msg = '';
$email = strtolower(trim((string) ($_POST['email'] ?? '')));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'logout') {
    if (csrf_ok()) {
        unset($_SESSION['customer_id']);
        session_regenerate_id(true);
    }
    redirect(url());
}
if (customer_id() && in_array($mode, ['login', 'register'], true)) {
    redirect(url($next));
}

/** Emails a link to set a password. The link stops working once the password changes, or after 24 hours. */
function send_password_link(array $c): bool
{
    $exp = time() + 86400;
    $sig = sign('reset|' . $c['id'] . '|' . $exp . '|' . (string) $c['password_hash']);
    $link = abs_url('sign-in?mode=reset&id=' . (int) $c['id'] . '&exp=' . $exp . '&t=' . $sig);
    return send_mail($c['email'], 'Set your password', '<p>Hi ' . e($c['name']) . ', use the link below to set a password for your account. It works for 24 hours.</p><p><a href="'
        . e($link) . '">Set my password</a></p><p style="color:#685e54">If you did not ask for this, you can ignore this email.</p>');
}

$resetUser = null;
if ($mode === 'reset') {
    $rid = (int) ($_GET['id'] ?? 0);
    $exp = (int) ($_GET['exp'] ?? 0);
    $u = $rid ? row('SELECT * FROM customers WHERE id = ?', [$rid]) : null;
    if ($u && $exp >= time() && sign_ok('reset|' . $u['id'] . '|' . $exp . '|' . (string) $u['password_hash'], (string) ($_GET['t'] ?? ''))) {
        $resetUser = $u;
    } else {
        $err = 'This link is invalid or has expired. Request a new one below.';
        $mode = 'forgot';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$err) {
    if (!csrf_ok()) {
        $err = 'Your session expired. Please try again.';
    } elseif ($mode === 'login') {
        if (throttle_blocked('customer', $email)) {
            $err = 'Too many attempts. Wait 15 minutes, or use "Forgot password".';
        } else {
            $u = row('SELECT * FROM customers WHERE email = ?', [$email]);
            if ($u && $u['password_hash'] && password_verify((string) ($_POST['password'] ?? ''), $u['password_hash'])) {
                throttle_clear('customer', $email);
                customer_login($u);
                redirect(url($next));
            }
            throttle_hit('customer', $email);
            $err = 'Email or password is incorrect.';
        }
    } elseif ($mode === 'register') {
        $name = trim(str_cut((string) ($_POST['name'] ?? ''), 120));
        $phone = clean_phone((string) ($_POST['phone'] ?? ''));
        $pass = (string) ($_POST['password'] ?? '');
        $u = valid_email($email) ? row('SELECT * FROM customers WHERE email = ?', [$email]) : null;
        if (strlen($name) < 2) {
            $err = 'Enter your name.';
        } elseif (!valid_email($email)) {
            $err = 'Enter a valid email address.';
        } elseif ($phone === '') {
            $err = 'Enter a valid 10-digit mobile number.';
        } elseif (strlen($pass) < 8) {
            $err = 'Use at least 8 characters for your password.';
        } elseif ($u && $u['password_hash']) {
            $err = 'An account with this email already exists. Sign in, or use "Forgot password".';
        } elseif ($u) {
            // An earlier order created this customer without a password: prove the email is theirs first.
            send_password_link($u);
            $msg = 'We already have orders under ' . $email . '. We have emailed a link to set your password, so only the owner of this email can open the account.';
        } else {
            $id = insert_row('customers', ['name' => $name, 'email' => $email, 'phone' => $phone, 'password_hash' => password_hash($pass, PASSWORD_DEFAULT),
                'source' => 'website', 'created_at' => now()]);
            customer_login(['id' => $id]);
            redirect(url($next));
        }
    } elseif ($mode === 'forgot') {
        if (!valid_email($email)) {
            $err = 'Enter a valid email address.';
        } elseif (throttle_blocked('reset', $email, 5, 3)) {
            $err = 'Too many requests. Wait 15 minutes and try again.';
        } else {
            throttle_hit('reset', $email);
            $u = row('SELECT * FROM customers WHERE email = ?', [$email]);
            if ($u) {
                send_password_link($u);
            }
            $msg = 'If we have an account or order for ' . $email . ', we have emailed it a link to set a new password.';
        }
    } elseif ($mode === 'reset' && $resetUser) {
        $pass = (string) ($_POST['password'] ?? '');
        if (strlen($pass) < 8) {
            $err = 'Use at least 8 characters for your password.';
        } elseif ($pass !== (string) ($_POST['password2'] ?? '')) {
            $err = 'The two passwords do not match.';
        } else {
            update_row('customers', ['password_hash' => password_hash($pass, PASSWORD_DEFAULT)], 'id = ?', [(int) $resetUser['id']]);
            customer_login($resetUser);
            flash('Your password is saved.');
            redirect(url('account'));
        }
    }
}

$titles = ['login' => 'Sign in', 'register' => 'Create an account', 'forgot' => 'Forgot password', 'reset' => 'Set a new password'];
$q = $next !== 'account' ? '&next=' . $next : '';
page_start($titles[$mode], ['noindex' => true]);
?>
<section class="section">
  <div class="wrap auth">
    <?php if (in_array($mode, ['login', 'register'], true)): ?>
      <nav class="tabs" aria-label="Account">
        <a href="<?= e(url('sign-in' . ($q ? '?' . ltrim($q, '&') : ''))) ?>"<?= $mode === 'login' ? ' aria-current="page"' : '' ?>>Sign in</a>
        <a href="<?= e(url('sign-in?mode=register' . $q)) ?>"<?= $mode === 'register' ? ' aria-current="page"' : '' ?>>Create account</a>
      </nav>
    <?php endif; ?>
    <h1><?= e($titles[$mode]) ?></h1>
    <?php if ($err): ?><p class="notice notice-err" role="alert"><?= e($err) ?></p><?php endif; ?>
    <?php if ($msg): ?><p class="notice notice-ok" role="status"><?= e($msg) ?></p><?php endif; ?>
    <?php if ($mode === 'login'): ?>
      <form method="post">
        <?= csrf_field() ?>
        <div class="field"><label for="email">Email</label><input class="input" id="email" name="email" type="email" required autocomplete="email" value="<?= e($email) ?>"></div>
        <div class="field"><label for="password">Password</label><input class="input" id="password" name="password" type="password" required autocomplete="current-password"></div>
        <button class="btn btn-block" type="submit">Sign in</button>
      </form>
      <p style="margin-top:16px"><a href="<?= e(url('sign-in?mode=forgot')) ?>">Forgot password?</a></p>
    <?php elseif ($mode === 'register'): ?>
      <form method="post">
        <?= csrf_field() ?>
        <div class="field"><label for="name">Full name</label><input class="input" id="name" name="name" required maxlength="120" autocomplete="name" value="<?= e($_POST['name'] ?? '') ?>"></div>
        <div class="field"><label for="email">Email</label><input class="input" id="email" name="email" type="email" required autocomplete="email" value="<?= e($email) ?>"></div>
        <div class="field"><label for="phone">Mobile number</label><input class="input" id="phone" name="phone" type="tel" required autocomplete="tel" value="<?= e($_POST['phone'] ?? '') ?>"></div>
        <div class="field"><label for="password">Password</label><input class="input" id="password" name="password" type="password" required minlength="8" autocomplete="new-password"><span class="hint">At least 8 characters.</span></div>
        <button class="btn btn-block" type="submit">Create account</button>
        <p class="terms-note">By creating an account you agree to our <a href="<?= e(url('terms-and-conditions')) ?>">terms</a> and <a href="<?= e(url('privacy-policy')) ?>">privacy policy</a>.</p>
      </form>
    <?php elseif ($mode === 'forgot'): ?>
      <form method="post" action="<?= e(url('sign-in?mode=forgot')) ?>">
        <?= csrf_field() ?>
        <p class="muted">Enter the email you ordered with. We will send a link to set a new password. This also works if you have only ordered as a guest.</p>
        <div class="field"><label for="email">Email</label><input class="input" id="email" name="email" type="email" required autocomplete="email" value="<?= e($email) ?>"></div>
        <button class="btn btn-block" type="submit">Email me a link</button>
      </form>
      <p style="margin-top:16px"><a href="<?= e(url('sign-in')) ?>">Back to sign in</a></p>
    <?php else: ?>
      <form method="post">
        <?= csrf_field() ?>
        <div class="field"><label for="password">New password</label><input class="input" id="password" name="password" type="password" required minlength="8" autocomplete="new-password"></div>
        <div class="field"><label for="password2">Repeat new password</label><input class="input" id="password2" name="password2" type="password" required minlength="8" autocomplete="new-password"></div>
        <button class="btn btn-block" type="submit">Save password</button>
      </form>
    <?php endif; ?>
  </div>
</section>
<?php page_end();
