<?php
/** Copy pictures from the reference site, and data checks for imported orders. */
require_once __DIR__ . '/../includes/admin.php';
admin_require();

/** Files the shop expects: [local path => remote path on the reference site]. */
function wanted_images(): array
{
    $want = [];
    foreach (['logo.png', 'hero.jpg', 'castings.jpg', 'diwali.svg'] as $f) {
        $want['assets/img/' . $f] = '/assets/img/' . $f;
    }
    foreach (rows('SELECT image, gallery FROM products') as $p) {
        foreach (array_merge([(string) $p['image']], (array) json_decode((string) $p['gallery'], true)) as $f) {
            if (is_string($f) && preg_match('/^[A-Za-z0-9._-]+\.(jpe?g|png|webp|gif)$/i', $f)) {
                $want['assets/uploads/products/' . $f] = '/assets/uploads/products/' . $f;
            }
        }
    }
    return array_filter($want, function ($local) { return !is_file(APP_ROOT . '/' . $local); }, ARRAY_FILTER_USE_KEY);
}

function fetch_remote(string $url): ?string
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3, CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_USERAGENT => 'Mozilla/5.0 (store image copier)']);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ($body !== false && $code === 200) ? (string) $body : null;
    }
    $body = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 20, 'user_agent' => 'Mozilla/5.0 (store image copier)']]));
    return $body === false ? null : $body;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $do = (string) ($_POST['do'] ?? '');
    if ($do === 'images') {
        $ref = rtrim((string) setting('reference_url', ''), '/');
        if (!preg_match('#^https?://#i', $ref)) {
            flash('Set the reference site in Settings > PayU import first.', 'err');
            redirect(admin_url('tools.php'));
        }
        $start = time();
        $ok = 0;
        $failed = [];
        foreach (wanted_images() as $local => $remote) {
            if (time() - $start > 25) {
                break;
            }
            $body = fetch_remote($ref . $remote);
            $isSvg = substr($local, -4) === '.svg';
            $valid = $body !== null && ($isSvg ? (stripos($body, '<svg') !== false && stripos($body, '<script') === false && stripos($body, 'onload=') === false) : (bool) @getimagesizefromstring($body));
            if ($valid && @file_put_contents(APP_ROOT . '/' . $local, $body) !== false) {
                $ok++;
            } else {
                $failed[] = basename($local);
            }
        }
        $left = count(wanted_images());
        flash('Copied ' . $ok . ' picture' . ($ok === 1 ? '' : 's') . '.' . ($failed ? ' Could not copy: ' . implode(', ', array_slice($failed, 0, 10)) . '.' : '') . ($left ? ' ' . $left . ' still missing; press the button again.' : ''), $failed ? 'err' : 'ok');
    } elseif ($do === 'recalc') {
        $n = 0;
        foreach (col('SELECT id FROM orders') as $oid) {
            order_recalc((int) $oid);
            $n++;
        }
        flash('Payment status worked out again for ' . $n . ' orders.');
    }
    redirect(admin_url('tools.php'));
}

$missing = wanted_images();
$unmatched = rows("SELECT i.id, i.product_name, i.source_text, o.id AS order_id, o.order_number FROM order_items i JOIN orders o ON o.id = i.order_id WHERE i.match_status = 'unmatched' ORDER BY o.placed_at DESC LIMIT 100");
$noItems = (int) val("SELECT COUNT(*) FROM orders o WHERE o.source = 'payu_import' AND NOT EXISTS (SELECT 1 FROM order_items i WHERE i.order_id = o.id)");
$attempts = (int) val('SELECT COUNT(*) FROM payu_transactions WHERE order_id IS NULL');
$dupFlags = (int) val("SELECT COUNT(*) FROM orders WHERE import_note LIKE '%Possible duplicate payment%'");
admin_header('Tools', 'tools');
?>
<section class="panel">
  <h2>Copy pictures from <?= e(setting('reference_url', 'the reference site')) ?></h2>
  <p>The logo, home-page photos, offer picture and product images are copied from the reference site into this store, so nothing loads from the old site. <?= count($missing) ?> picture<?= count($missing) === 1 ? ' is' : 's are' ?> missing.</p>
  <?php if ($missing): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="images"><button class="btn" type="submit">Copy missing pictures</button></form><?php else: ?><p class="muted">All pictures are in place.</p><?php endif; ?>
</section>
<section class="panel" id="checks">
  <h2>Data checks</h2>
  <ul class="todo">
    <li><a href="<?= e(admin_url('orders.php?source=payu_import')) ?>"><?= $noItems ?> imported order<?= $noItems === 1 ? '' : 's' ?></a> have no product details in the PayU file. They stay without items unless you add what you know.</li>
    <li><a href="<?= e(admin_url('payments.php?linked=no')) ?>"><?= $attempts ?> PayU attempt<?= $attempts === 1 ? '' : 's' ?></a> are not part of an order (no payment in their group succeeded).</li>
    <li><a href="<?= e(admin_url('orders.php?q=')) ?>"><?= $dupFlags ?> imported order<?= $dupFlags === 1 ? '' : 's' ?></a> are flagged as a possible duplicate payment.</li>
  </ul>
  <?php if ($unmatched): ?>
    <h3>Imported items not matched to a product</h3>
    <div class="tw"><table class="tbl"><thead><tr><th>Order</th><th>Text in the PayU file</th></tr></thead><tbody>
      <?php foreach ($unmatched as $u): ?><tr><td><a href="<?= e(admin_url('order.php?id=' . (int) $u['order_id'])) ?>"><?= e($u['order_number']) ?></a></td><td><?= e($u['source_text'] ?: $u['product_name']) ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
    <p class="muted">Link each one on its order page, or add the text as an alternative name on the product so future imports match it.</p>
  <?php endif; ?>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="recalc"><button class="btn btn-ghost" type="submit">Recalculate the payment status of every order</button></form>
</section>
<?php admin_footer();
