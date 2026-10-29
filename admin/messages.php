<?php
require_once __DIR__ . '/../includes/admin.php';
admin_require();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int) ($_POST['id'] ?? 0);
    $do = (string) ($_POST['do'] ?? '');
    if ($do === 'delete') {
        q('DELETE FROM contact_messages WHERE id = ?', [$id]);
        flash('Message deleted.');
    } elseif (in_array($do, ['read', 'unread'], true)) {
        q('UPDATE contact_messages SET is_read = ? WHERE id = ?', [$do === 'read' ? 1 : 0, $id]);
    }
    redirect(admin_url('messages.php'));
}
$list = rows('SELECT * FROM contact_messages ORDER BY created_at DESC, id DESC LIMIT 200');
admin_header('Messages', 'messages');
?>
<p class="muted">Messages sent from the Contact page, newest first.</p>
<?php if (!$list): ?><p class="panel muted">No messages yet.</p><?php endif; ?>
<?php foreach ($list as $m): ?>
  <article class="panel msg<?= (int) $m['is_read'] ? '' : ' msg-new' ?>">
    <div class="panel-head">
      <h2><?= e($m['subject'] ?: 'Message') ?> <span class="muted">from <?= e($m['name']) ?></span></h2>
      <span class="muted"><?= e(fmt_date($m['created_at'])) ?></span>
    </div>
    <p><a href="mailto:<?= e($m['email']) ?>"><?= e($m['email']) ?></a><?= $m['phone'] !== '' ? ', ' . e($m['phone']) : '' ?></p>
    <p><?= nl2br(e($m['message'])) ?></p>
    <div class="actions">
      <form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $m['id'] ?>"><input type="hidden" name="do" value="<?= (int) $m['is_read'] ? 'unread' : 'read' ?>"><button class="btn btn-ghost btn-sm" type="submit"><?= (int) $m['is_read'] ? 'Mark as unread' : 'Mark as read' ?></button></form>
      <form method="post" data-confirm="Delete this message?"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $m['id'] ?>"><input type="hidden" name="do" value="delete"><button class="linkish" type="submit">Delete</button></form>
    </div>
  </article>
<?php endforeach; ?>
<?php admin_footer();
