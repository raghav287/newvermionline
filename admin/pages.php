<?php
require_once __DIR__ . '/../includes/admin.php';
admin_require();

$slug = (string) ($_GET['slug'] ?? '');
$pg = $slug !== '' ? row('SELECT * FROM pages WHERE slug = ?', [$slug]) : null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $pg) {
    $title = trim(str_cut((string) ($_POST['title'] ?? ''), 160));
    if (strlen($title) < 2) {
        flash('Enter a page title.', 'err');
    } else {
        update_row('pages', ['title' => $title, 'intro' => trim(str_cut((string) ($_POST['intro'] ?? ''), 255)), 'body' => (string) ($_POST['body'] ?? '')], 'slug = ?', [$slug]);
        flash('Page saved.');
    }
    redirect(admin_url('pages.php?slug=' . rawurlencode($slug)));
}
$list = rows('SELECT slug, title, updated_at FROM pages ORDER BY title');
admin_header($pg ? 'Edit: ' . $pg['title'] : 'Pages', 'pages');
?>
<div class="two <?= $pg ? 'two-wide-r' : '' ?>">
  <section class="panel">
    <h2>Pages</h2>
    <div class="tw"><table class="tbl"><thead><tr><th>Page</th><th>Address</th><th>Last edited</th></tr></thead><tbody>
      <?php foreach ($list as $p): ?><tr><td><a href="<?= e(admin_url('pages.php?slug=' . rawurlencode($p['slug']))) ?>"><?= e($p['title']) ?></a></td><td><a class="mono" href="<?= e(url($p['slug'])) ?>" target="_blank" rel="noopener">/<?= e($p['slug']) ?></a></td><td><?= e(fmt_date($p['updated_at'], false)) ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
  </section>
  <?php if ($pg): ?>
  <section class="panel">
    <h2><?= e($pg['title']) ?></h2>
    <form method="post">
      <?= csrf_field() ?>
      <label class="fld"><span>Title</span><input name="title" required value="<?= e($pg['title']) ?>"></label>
      <label class="fld"><span>Short introduction (under the title)</span><input name="intro" value="<?= e($pg['intro']) ?>"></label>
      <label class="fld"><span>Content (HTML)</span><textarea name="body" rows="22" class="mono"><?= e($pg['body']) ?></textarea></label>
      <p class="muted">These words are replaced with your current settings, so the page always matches checkout: {store_name}, {legal_name}, {site_domain}, {email}, {phone}, {address}, {hours}, {dispatch_time}, {free_shipping_above}, {shipping_fee}, {loose_min}, {loose_max}, {contact_block}, {updated}. Link to another page with {link:shipping-policy}. On the FAQ, each question is a &lt;details&gt;&lt;summary&gt;Question&lt;/summary&gt;&lt;p&gt;Answer&lt;/p&gt;&lt;/details&gt; block.</p>
      <button class="btn" type="submit">Save page</button> <a class="btn btn-ghost" href="<?= e(url($pg['slug'])) ?>" target="_blank" rel="noopener">View page</a>
    </form>
  </section>
  <?php endif; ?>
</div>
<?php admin_footer();
