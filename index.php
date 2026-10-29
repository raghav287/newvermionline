<?php
require_once __DIR__ . '/includes/app.php';
require_once __DIR__ . '/includes/store.php';
require_once __DIR__ . '/includes/layout.php';
store_boot();

$L = loose_config();
$offer = offer();
$products = rows("SELECT * FROM products WHERE status = 'active' AND kind = 'standard' ORDER BY created_at DESC, id DESC LIMIT 8");
$hero = site_img('hero.jpg');
$castings = site_img('castings.jpg');
$wa = whatsapp_link();
$looseOffer = offer_applies(true);
$kg = $L['enabled'] ? loose_clamp(100) : 0;
$calc = ['min' => $L['min'], 'max' => $L['max'], 'step' => $L['step'], 'slabs' => $L['slabs'], 'pct' => $looseOffer ? $offer['percent'] : 0];

page_start('', ['active' => 'home']);
?>
<section class="hero<?= $hero ? '' : ' hero-plain' ?>">
  <img class="hero-bg" src="<?= e($hero ?: asset('img/soil.svg')) ?>" alt="" width="1600" height="900" fetchpriority="high">
  <div class="wrap">
    <h1>Vermicompost for every pot, bed and field</h1>
    <p>Earthworm castings in 5, 10 and 15 kg bags for home gardens, or loose by the kilo for farms, nurseries and landscapers.</p>
    <div class="hero-actions">
      <a class="btn btn-light" href="<?= e(url('shop')) ?>">Shop bags</a>
      <?php if ($L['enabled']): ?><a class="btn btn-outline-light" href="#loose">Price loose compost</a><?php endif; ?>
    </div>
    <ul class="hero-facts">
      <li><?= icon('truck') ?>Delivery across India</li>
      <li><?= icon('lock') ?>Secure online payment by PayU</li>
    </ul>
  </div>
</section>

<?php if ($offer['on']): ?>
<section class="section offer-band" aria-labelledby="offer-title">
  <div class="wrap offer-grid">
    <div>
      <p class="kicker"><?= e($offer['name']) ?> offer</p>
      <h2 id="offer-title"><?= e($offer['title']) ?></h2>
      <p class="lead"><?= e($offer['text']) ?> No code needed: the discount is taken off in your cart, before shipping.</p>
      <div class="hero-actions">
        <a class="btn" href="<?= e(url('shop')) ?>">Shop the offer</a>
        <?php if ($L['enabled']): ?><a class="btn btn-ghost" href="#loose">Price loose compost</a><?php endif; ?>
      </div>
    </div>
    <img src="<?= e(site_img('diwali.svg') ?: asset('img/diya.svg')) ?>" alt="Clay diyas lit for Diwali under a marigold garland" width="360" height="240">
  </div>
</section>
<?php endif; ?>

<?php if ($L['enabled']): ?>
<section class="section loose" id="loose" aria-labelledby="loose-title">
  <div class="wrap loose-grid">
    <div>
      <h2 id="loose-title">Buy loose vermicompost</h2>
      <p class="lead">For farms, nurseries, landscapers and large terrace gardens. Enter the quantity you need and see the cost per kg straight away. The rate drops as the quantity goes up.</p>
      <table class="slabs">
        <caption class="sr-only">Price per kg by order quantity</caption>
        <thead><tr><th scope="col">Order quantity</th><th scope="col">Price per kg</th></tr></thead>
        <tbody>
        <?php foreach ($L['slabs'] as $i => $s): $next = $L['slabs'][$i + 1][0] ?? null; ?>
          <tr data-slab="<?= $i ?>">
            <td><?= number_format($s[0]) ?><?= $next !== null ? ' to ' . number_format($next - 1) . ' kg' : ' kg and above' ?></td>
            <td><?= $looseOffer ? '<del>' . money($s[1]) . '</del> ' : '' ?><?= money(sale_price($s[1], true)) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <ul class="loose-notes">
        <li>Minimum order <?= $L['min'] ?> kg, in steps of <?= $L['step'] ?> kg.</li>
        <li>Pay online, just like a bagged order.</li>
        <li>Need more than <?= number_format($L['max']) ?> kg? <?php if ($wa): ?><a href="<?= e($wa) ?>" target="_blank" rel="noopener">WhatsApp us</a><?php else: ?>Contact us<?php endif; ?> for a transport quote.</li>
      </ul>
    </div>
    <form class="slip" id="loose-form" method="post" action="<?= e(url('cart')) ?>" data-config="<?= e(json_encode($calc)) ?>">
      <?= csrf_field() ?><input type="hidden" name="do" value="loose">
      <div class="slip-head"><span>Weighing slip</span><span>Loose vermicompost</span></div>
      <label class="label" for="kg">How many kilos do you need?</label>
      <div class="slip-readout">
        <input id="kg" name="kg" type="number" inputmode="numeric" min="<?= $L['min'] ?>" max="<?= $L['max'] ?>" step="<?= $L['step'] ?>" value="<?= $kg ?>">
        <span>kg</span>
      </div>
      <input class="slip-range" id="kg-range" type="range" min="0" max="1000" step="1" value="0" aria-label="Adjust quantity">
      <p class="slip-warn" id="kg-warn" aria-live="polite"></p>
      <div class="presets" role="group" aria-label="Common quantities">
        <?php foreach ([25, 50, 100, 250, 500, 1000, 2000] as $pre): if ($pre < $L['min'] || $pre > $L['max']) continue; ?>
          <button type="button" data-kg="<?= $pre ?>" aria-pressed="<?= $pre === $kg ? 'true' : 'false' ?>"><?= number_format($pre) ?> kg</button>
        <?php endforeach; ?>
      </div>
      <?php $base = loose_base_rate($kg); $rate = loose_rate($kg); ?>
      <dl class="slip-lines" aria-live="polite">
        <div><dt>Rate</dt><dd id="l-rate"><?= $rate < $base ? '<del>' . money($base) . '</del> ' : '' ?><?= money($rate) ?> per kg</dd></div>
        <div><dt>Compost</dt><dd id="l-sub"><?= number_format($kg) ?> kg &times; <?= money($rate) ?> = <?= money($kg * $rate) ?></dd></div>
        <div><dt>Bulk saving</dt><dd id="l-bulk"><?= ($bulk = $kg * ($L['slabs'][0][1] - $base)) > 0 ? money($bulk) : 'None at this quantity' ?></dd></div>
        <?php if ($looseOffer): ?><div><dt><?= e($offer['name']) ?> offer (<?= pct_text($offer['percent']) ?> off)</dt><dd id="l-offer">-<?= money($kg * ($base - $rate)) ?></dd></div><?php endif; ?>
        <div><dt>Delivery</dt><dd>Shown at checkout</dd></div>
      </dl>
      <div class="slip-big">
        <div><span>Cost per kg</span><strong id="l-perkg"><?= money($rate) ?></strong></div>
        <div><span>Estimated total</span><strong id="l-total"><?= money($kg * $rate) ?></strong></div>
      </div>
      <button class="btn" id="loose-add" type="submit">Add <?= number_format($kg) ?> kg to cart</button>
      <p class="slip-foot">Shipping is added at checkout as per our <a href="<?= e(url('shipping-policy')) ?>">shipping policy</a>.</p>
    </form>
  </div>
</section>
<?php endif; ?>

<?php if ($products): ?>
<section class="section">
  <div class="wrap">
    <div class="sec-head">
      <div><h2>Bags and garden inputs</h2><p>Packed vermicompost and other organic products, ready to ship.</p></div>
      <a class="btn btn-ghost" href="<?= e(url('shop')) ?>">View all products</a>
    </div>
    <div class="grid-products"><?php foreach ($products as $p) { echo product_card($p); } ?></div>
  </div>
</section>
<?php endif; ?>

<section class="section tint">
  <div class="wrap split">
    <img src="<?= e($castings ?: asset('img/soil.svg')) ?>" alt="A handful of dark, crumbly vermicompost" width="640" height="512" loading="lazy">
    <div>
      <h2>Why gardeners use vermicompost</h2>
      <p class="lead">Worm castings feed the soil, not just the plant, so the benefits last beyond a single season.</p>
      <ul class="points">
        <li><h3>Better soil texture</h3><p>Loosens heavy soil and helps sandy soil hold together, so roots spread easily.</p></li>
        <li><h3>Slow, steady nutrition</h3><p>Supplies nitrogen, phosphorus, potassium and micronutrients that release gradually.</p></li>
        <li><h3>Living soil</h3><p>Adds beneficial microbes that keep the soil active.</p></li>
        <li><h3>Gentle on roots</h3><p>Safe for seedlings, pots and indoor plants. It will not burn roots.</p></li>
        <li><h3>Holds moisture</h3><p>Improves water retention, so plants need watering less often.</p></li>
        <li><h3>Made from waste</h3><p>Turns kitchen and farm waste into something useful instead of landfill.</p></li>
      </ul>
    </div>
  </div>
</section>

<section class="section">
  <div class="wrap">
    <div class="sec-head"><div><h2>How our compost is made</h2><p>About 60 to 90 days from organic waste to finished castings.</p></div></div>
    <ol class="steps">
      <li><h3>Collection</h3><p>Kitchen scraps, crop residues and green waste are gathered and sorted.</p></li>
      <li><h3>Composting</h3><p>The material goes into beds stocked with red wiggler earthworms.</p></li>
      <li><h3>Maturing</h3><p>The worms break it down into fine castings full of beneficial microbes.</p></li>
      <li><h3>Sieving and packing</h3><p>Each batch is sieved and checked before it is packed or sold loose.</p></li>
    </ol>
  </div>
</section>

<section class="section tint">
  <div class="wrap">
    <div class="sec-head">
      <div><h2>How much to use</h2><p>Typical amounts. Adjust for your soil, plants and season.</p></div>
      <?php if ($L['enabled']): ?><a class="btn btn-ghost" href="#loose">Price a loose order</a><?php endif; ?>
    </div>
    <div class="uses">
      <article><h3>Pots and grow bags</h3><p class="amt">1 part to 3 parts soil</p><p>Mix in when potting, then add a handful (100 to 200 g) per pot each month.</p></article>
      <article><h3>Kitchen garden beds</h3><p class="amt">1 to 2 kg per m²</p><p>Work it into the top few inches of soil before sowing or planting.</p></article>
      <article><h3>Lawns</h3><p class="amt">0.5 to 1 kg per m²</p><p>Spread a thin layer, rake it in and water well.</p></article>
      <article><h3>Field crops</h3><p class="amt">1 to 2 tonnes per acre</p><p>Apply at land preparation. Your agronomist can fine-tune this for your crop.</p></article>
    </div>
  </div>
</section>

<?php if ($wa): ?>
<section class="cta-band">
  <div class="wrap">
    <div><h2>Ordering for a farm or nursery?</h2><p>Talk to us about large quantities, delivery and regular supply.</p></div>
    <a class="btn btn-light" href="<?= e($wa) ?>" target="_blank" rel="noopener"><?= icon('message') ?>WhatsApp <?= e(setting('store_phone')) ?></a>
  </div>
</section>
<?php endif; ?>
<?php page_end();
