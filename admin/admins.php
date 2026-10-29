<?php
require_once __DIR__ . '/../includes/admin.php';
$me = admin_require();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $do = (string) ($_POST['do'] ?? '');
    if ($do === 'add') {
        $name = trim(str_cut((string) ($_POST['name'] ?? ''), 80));
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $pass = (string) ($_POST['password'] ?? '');
        if (strlen($name) < 2 || !valid_email($email)) {
            flash('Enter a name and a valid email.', 'err');
        } elseif (strlen($pass) < 10) {
            flash('Admin passwords need at least 10 characters.', 'err');
        } elseif (val('SELECT id FROM admins WHERE email = ?', [$email])) {
            flash('An admin with that email already exists.', 'err');
        } else {
            insert_row('admins', ['name' => $name, 'email' => $email, 'password_hash' => password_hash($pass, PASSWORD_DEFAULT), 'created_at' => now()]);
            flash('Admin added.');
        }
    } elseif ($do === 'toggle') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id === (int) $me['id']) {
            flash('You cannot switch off your own account.', 'err');
        } else {
            q('UPDATE admins SET is_active = 1 - is_active WHERE id = ?', [$id]);
            flash('Admin updated.');
        }
    } elseif ($do === 'password') {
        $new = (string) ($_POST['new'] ?? '');
        if (!password_verify((string) ($_POST['current'] ?? ''), $me['password_hash'])) {
            flash('Your current password is not correct.', 'err');
        } elseif (strlen($new) < 10) {
            flash('Use at least 10 characters.', 'err');
        } elseif ($new !== (string) ($_POST['new2'] ?? '')) {
            flash('The two new passwords do not match.', 'err');
        } else {
            update_row('admins', ['password_hash' => password_hash($new, PASSWORD_DEFAULT)], 'id = ?', [(int) $me['id']]);
            session_regenerate_id(true);
            flash('Your password is changed.');
        }
    }
    redirect(admin_url('admins.php'));
}
$list = rows('SELECT * FROM admins ORDER BY name');
admin_header('Admin users', 'admins');
?>
<div class="two">
  <section class="panel">
    <h2>Admins</h2>
    <div class="tw"><table class="tbl"><thead><tr><th>Name</th><th>Last sign-in</th><th>Status</th><th></th></tr></thead><tbody>
      <?php foreach ($list as $a): ?><tr><td><?= e($a['name']) ?><br><span class="muted"><?= e($a['email']) ?></span></td><td><?= e(fmt_date($a['last_login_at'])) ?></td>
        <td><?= (int) $a['is_active'] ? badge('Active', 'ok') : badge('Switched off', 'muted') ?></td>
        <td><?php if ((int) $a['id'] !== (int) $me['id']): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="toggle"><input type="hidden" name="id" value="<?= (int) $a['id'] ?>"><button class="linkish" type="submit"><?= (int) $a['is_active'] ? 'Switch off' : 'Switch on' ?></button></form><?php else: ?><span class="muted">You</span><?php endif; ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
    <h3>Add an admin</h3>
    <form method="post" class="grid-form">
      <?= csrf_field() ?><input type="hidden" name="do" value="add">
      <label class="fld"><span>Name</span><input name="name" required></label>
      <label class="fld"><span>Email</span><input name="email" type="email" required></label>
      <label class="fld"><span>Password (10+ characters)</span><input name="password" type="password" minlength="10" required autocomplete="new-password"></label>
      <div><button class="btn" type="submit">Add admin</button></div>
    </form>
  </section>
  <section class="panel">
    <h2>Change your password</h2>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="do" value="password">
      <label class="fld"><span>Current password</span><input name="current" type="password" required autocomplete="current-password"></label>
      <label class="fld"><span>New password (10+ characters)</span><input name="new" type="password" minlength="10" required autocomplete="new-password"></label>
      <label class="fld"><span>Repeat new password</span><input name="new2" type="password" minlength="10" required autocomplete="new-password"></label>
      <button class="btn" type="submit">Change password</button>
    </form>
  </section>
</div>
<?php admin_footer();
