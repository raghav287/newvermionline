<?php
/** Store details, shipping, the store-wide offer, loose compost rates, PayU keys and import options. */
require_once __DIR__ . '/../includes/admin.php';
admin_require();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $section = (string) ($_POST['section'] ?? '');
    $in = function ($k, $max = 255) { return trim(str_cut((string) ($_POST[$k] ?? ''), $max)); };
    $errors = [];
    $save = [];
    if ($section === 'store') {
        foreach (['store_name' => 120, 'legal_name' => 160, 'tagline' => 255, 'site_domain' => 120, 'store_phone' => 40, 'address' => 255, 'hours' => 120, 'order_prefix' => 8] as $k => $max) {
            $save[$k] = $in($k, $max);
        }
        $save['whatsapp'] = (string) preg_replace('/\D+/', '', $in('whatsapp', 20));
        $save['order_prefix'] = (string) preg_replace('/[^A-Z0-9]/', '', strtoupper($save['order_prefix'])) ?: 'ORD';
        $save['order_emails'] = !empty($_POST['order_emails']) ? '1' : '0';
        foreach (['store_email', 'mail_from'] as $k) {
            $save[$k] = strtolower($in($k, 190));
            if ($save[$k] !== '' && !valid_email($save[$k])) {
                $errors[] = 'Enter a valid email address for ' . ($k === 'store_email' ? 'the store email' : 'the sender address') . '.';
            }
        }
        if ($save['store_name'] === '') {
            $errors[] = 'Enter the store name.';
        }
    } elseif ($section === 'shipping') {
        $save = ['free_shipping_above' => num2(max(0, (float) $in('free_shipping_above'))), 'shipping_fee' => num2(max(0, (float) $in('shipping_fee'))), 'dispatch_time' => $in('dispatch_time', 60)];
    } elseif ($section === 'offer') {
        $pct = (float) $in('offer_percent');
        if ($pct < 0 || $pct > 90) {
            $errors[] = 'The offer must be between 0% and 90%.';
        }
        $save = ['offer_enabled' => !empty($_POST['offer_enabled']) ? '1' : '0', 'offer_name' => $in('offer_name', 40), 'offer_title' => $in('offer_title', 120),
            'offer_text' => $in('offer_text', 255), 'offer_percent' => (string) round($pct, 2), 'offer_includes_loose' => !empty($_POST['offer_includes_loose']) ? '1' : '0'];
    } elseif ($section === 'loose') {
        $slabs = [];
        foreach ((array) ($_POST['slab_from'] ?? []) as $i => $from) {
            $rate = trim((string) ($_POST['slab_rate'][$i] ?? ''));
            if (trim((string) $from) === '' || $rate === '') {
                continue;
            }
            if ((float) $rate <= 0) {
                $errors[] = 'Every price per kg must be more than zero.';
            }
            $slabs[] = [max(0, (int) $from), round((float) $rate, 2)];
        }
        usort($slabs, function ($a, $b) { return $a[0] - $b[0]; });
        $cfg = ['enabled' => !empty($_POST['loose_enabled']), 'min' => max(1, (int) $in('min')), 'max' => (int) $in('max'), 'step' => max(1, (int) $in('step')), 'slabs' => $slabs];
        if (!$slabs) {
            $errors[] = 'Add at least one price slab.';
        }
        if ($cfg['max'] < $cfg['min']) {
            $errors[] = 'The largest online order must be at least the minimum order.';
        }
        $save = ['loose' => $cfg];
    } elseif ($section === 'payu') {
        $save = ['payu_mode' => ($_POST['payu_mode'] ?? '') === 'live' ? 'live' : 'test', 'payu_key' => $in('payu_key', 60)];
        if ($in('payu_salt', 120) !== '') {
            $save['payu_salt'] = $in('payu_salt', 120);
        }
    } elseif ($section === 'import') {
        $save = ['import_window_minutes' => (string) max(1, min(1440, (int) $in('import_window_minutes'))), 'import_max_rows' => (string) max(100, min(100000, (int) $in('import_max_rows'))),
            'reference_url' => rtrim($in('reference_url'), '/')];
        if ($save['reference_url'] !== '' && !preg_match('#^https?://[^\s/]+#i', $save['reference_url'])) {
            $errors[] = 'The reference site must be a web address starting with https://';
        }
    }
    if ($errors) {
        foreach ($errors as $er) {
            flash($er, 'err');
        }
    } elseif ($save) {
        settings_save($save);
        flash('Settings saved.');
    }
    redirect(admin_url('settings.php#' . $section));
}

$s = function ($k, $d = '') { return e(setting($k, $d)); };
$L = loose_config();
$slabs = array_pad($L['slabs'], 6, ['', '']);
admin_header('Settings', 'settings');
?>
<nav class="tabs tabs-sm"><a href="#store">Store details</a><a href="#shipping">Shipping</a><a href="#offer">Offer</a><a href="#loose">Loose compost</a><a href="#payu">PayU</a><a href="#import">Import</a></nav>

<section class="panel" id="store">
  <h2>Store details</h2>
  <p class="muted">Shown in the header, footer, policies and emails.</p>
  <form method="post" class="grid-form">
    <?= csrf_field() ?><input type="hidden" name="section" value="store">
    <label class="fld"><span>Store name</span><input name="store_name" required value="<?= $s('store_name') ?>"></label>
    <label class="fld"><span>Company name</span><input name="legal_name" value="<?= $s('legal_name') ?>"></label>
    <label class="fld fld-wide"><span>Footer line</span><input name="tagline" value="<?= $s('tagline') ?>"></label>
    <label class="fld"><span>Website domain (used in policies)</span><input name="site_domain" value="<?= $s('site_domain') ?>"></label>
    <label class="fld"><span>Store email</span><input name="store_email" type="email" value="<?= $s('store_email') ?>"></label>
    <label class="fld"><span>Phone shown on the site</span><input name="store_phone" value="<?= $s('store_phone') ?>"></label>
    <label class="fld"><span>WhatsApp number with country code (digits)</span><input name="whatsapp" value="<?= $s('whatsapp') ?>" placeholder="916284637649"></label>
    <label class="fld fld-wide"><span>Address</span><input name="address" value="<?= $s('address') ?>"></label>
    <label class="fld"><span>Support hours</span><input name="hours" value="<?= $s('hours') ?>"></label>
    <label class="fld"><span>Order number prefix</span><input name="order_prefix" maxlength="8" value="<?= $s('order_prefix', 'ORD') ?>"></label>
    <label class="fld"><span>Send emails from (an address on your domain)</span><input name="mail_from" type="email" value="<?= $s('mail_from') ?>"></label>
    <label class="chk fld-wide"><input type="checkbox" name="order_emails" value="1"<?= setting('order_emails', '1') === '1' ? ' checked' : '' ?>> Email customers when their payment is received</label>
    <div><button class="btn" type="submit">Save store details</button></div>
  </form>
</section>

<section class="panel" id="shipping">
  <h2>Shipping and dispatch</h2>
  <form method="post" class="grid-form">
    <?= csrf_field() ?><input type="hidden" name="section" value="shipping">
    <label class="fld"><span>Free shipping from (₹ order value, 0 = never free)</span><input name="free_shipping_above" type="number" min="0" step="1" value="<?= $s('free_shipping_above', '0') ?>"></label>
    <label class="fld"><span>Shipping fee below that (₹)</span><input name="shipping_fee" type="number" min="0" step="1" value="<?= $s('shipping_fee', '0') ?>"></label>
    <label class="fld"><span>Dispatch time (product pages, policies, emails)</span><input name="dispatch_time" value="<?= $s('dispatch_time', '7-8 days') ?>"></label>
    <div><button class="btn" type="submit">Save shipping</button></div>
  </form>
</section>

<section class="panel" id="offer">
  <h2>Store-wide offer</h2>
  <p class="muted">Takes a percentage off every product price. The offer bar, badges and cart savings appear only while it is on.</p>
  <form method="post" class="grid-form">
    <?= csrf_field() ?><input type="hidden" name="section" value="offer">
    <label class="chk fld-wide"><input type="checkbox" name="offer_enabled" value="1"<?= setting('offer_enabled') === '1' ? ' checked' : '' ?>> Offer is on</label>
    <label class="fld"><span>Offer name (badge: "Diwali 30% off")</span><input name="offer_name" value="<?= $s('offer_name') ?>"></label>
    <label class="fld"><span>Percent off</span><input name="offer_percent" type="number" min="0" max="90" step="0.5" value="<?= $s('offer_percent', '0') ?>"></label>
    <label class="fld"><span>Headline</span><input name="offer_title" value="<?= $s('offer_title') ?>"></label>
    <label class="fld fld-wide"><span>Line under the headline</span><input name="offer_text" value="<?= $s('offer_text') ?>"></label>
    <label class="chk fld-wide"><input type="checkbox" name="offer_includes_loose" value="1"<?= setting('offer_includes_loose', '1') === '1' ? ' checked' : '' ?>> Also applies to loose compost</label>
    <div><button class="btn" type="submit">Save offer</button></div>
  </form>
</section>

<section class="panel" id="loose">
  <h2>Loose compost by the kilo</h2>
  <p class="muted">Drives the calculator on the home page and the price charged in the cart. The first slab always starts at the minimum order.</p>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="section" value="loose">
    <label class="chk"><input type="checkbox" name="loose_enabled" value="1"<?= !empty(setting_json('loose')['enabled']) ? ' checked' : '' ?>> Show the calculator and accept loose orders</label>
    <div class="grid-form">
      <label class="fld"><span>Minimum order (kg)</span><input name="min" type="number" min="1" value="<?= (int) $L['min'] ?>"></label>
      <label class="fld"><span>Largest online order (kg)</span><input name="max" type="number" min="1" value="<?= (int) $L['max'] ?>"></label>
      <label class="fld"><span>Steps of (kg)</span><input name="step" type="number" min="1" value="<?= (int) $L['step'] ?>"></label>
    </div>
    <div class="tw"><table class="tbl tbl-tight slab-table"><thead><tr><th>From (kg)</th><th>Price per kg before the offer (₹)</th></tr></thead><tbody>
      <?php foreach ($slabs as $sl): ?><tr><td><input name="slab_from[]" type="number" min="0" value="<?= e($sl[0]) ?>" aria-label="From kg"></td><td><input name="slab_rate[]" type="number" min="0" step="0.01" value="<?= e($sl[1]) ?>" aria-label="Price per kg"></td></tr><?php endforeach; ?>
    </tbody></table></div>
    <p class="muted">Leave a row empty to remove it.</p>
    <button class="btn" type="submit">Save loose compost</button>
  </form>
</section>

<section class="panel" id="payu">
  <h2>PayU</h2>
  <p class="muted">Copy the merchant key and salt from the PayU dashboard. Use test mode with PayU's test keys first, then switch to live.</p>
  <form method="post" class="grid-form">
    <?= csrf_field() ?><input type="hidden" name="section" value="payu">
    <label class="fld"><span>Mode</span><select name="payu_mode"><option value="test">Test</option><option value="live"<?= setting('payu_mode') === 'live' ? ' selected' : '' ?>>Live</option></select></label>
    <label class="fld"><span>Merchant key</span><input name="payu_key" autocomplete="off" value="<?= $s('payu_key') ?>"></label>
    <label class="fld"><span>Merchant salt <?= setting('payu_salt') !== '' ? '(saved; leave empty to keep it)' : '' ?></span><input name="payu_salt" type="password" autocomplete="new-password"></label>
    <p class="fld-wide muted">PayU sends customers back to <span class="mono"><?= e(abs_url('payu.php')) ?></span>. Each attempt's product info is "Order &lt;order number&gt;" and udf1 is the order number, so PayU reports can be matched to orders later.</p>
    <div><button class="btn" type="submit">Save PayU settings</button></div>
  </form>
</section>

<section class="panel" id="import">
  <h2>PayU import</h2>
  <form method="post" class="grid-form">
    <?= csrf_field() ?><input type="hidden" name="section" value="import">
    <label class="fld"><span>Group retries made within (minutes)</span><input name="import_window_minutes" type="number" min="1" max="1440" value="<?= $s('import_window_minutes', '60') ?>"></label>
    <label class="fld"><span>Most rows per file</span><input name="import_max_rows" type="number" min="100" max="100000" value="<?= $s('import_max_rows', '20000') ?>"></label>
    <label class="fld fld-wide"><span>Reference site for copying images (Tools)</span><input name="reference_url" value="<?= $s('reference_url') ?>"></label>
    <div><button class="btn" type="submit">Save import options</button></div>
  </form>
</section>
<?php admin_footer();
