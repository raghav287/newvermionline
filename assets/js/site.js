/* Vermi Compost storefront: cart drawer, add to cart, quantity steppers, product gallery, loose compost calculator. No libraries. */
(function () {
  'use strict';
  function meta(n) { var m = document.querySelector('meta[name="' + n + '"]'); return m ? m.content : ''; }
  var BASE = meta('base'), CSRF = meta('csrf');
  function $(s, r) { return (r || document).querySelector(s); }
  function $$(s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function money(n) {
    n = Math.round(n * 100) / 100;
    var whole = Math.abs(n - Math.round(n)) < 0.005;
    return '\u20b9' + n.toLocaleString('en-IN', { minimumFractionDigits: whole ? 0 : 2, maximumFractionDigits: whole ? 0 : 2 });
  }
  function api(action, data) {
    var opts = { credentials: 'same-origin', headers: { 'X-CSRF-Token': CSRF, 'Accept': 'application/json' } };
    if (data) {
      var fd = new FormData();
      Object.keys(data).forEach(function (k) { fd.append(k, data[k]); });
      opts.method = 'POST';
      opts.body = fd;
    }
    return fetch(BASE + '/api.php?action=' + encodeURIComponent(action), opts)
      .then(function (r) { return r.json(); })
      .catch(function () { return { ok: false, message: 'Could not reach the store. Check your connection and try again.' }; });
  }
  var toastEl = $('#toast'), toastTimer;
  function toast(msg) {
    if (!toastEl) return;
    toastEl.textContent = msg;
    toastEl.classList.add('is-on');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () { toastEl.classList.remove('is-on'); }, 3200);
  }

  /* drawers */
  var overlay = $('#overlay'), lastFocus = null;
  function openDrawer(id) {
    var d = document.getElementById(id);
    if (!d) return;
    closeDrawers(true);
    lastFocus = document.activeElement;
    d.classList.add('is-open');
    d.setAttribute('aria-hidden', 'false');
    overlay.classList.add('is-open');
    document.body.style.overflow = 'hidden';
    var c = d.querySelector('[data-close]');
    if (c) c.focus();
  }
  function closeDrawers(silent) {
    $$('.drawer.is-open').forEach(function (d) { d.classList.remove('is-open'); d.setAttribute('aria-hidden', 'true'); });
    if (overlay) overlay.classList.remove('is-open');
    document.body.style.overflow = '';
    if (!silent && lastFocus && lastFocus.focus) lastFocus.focus();
  }
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeDrawers(); });

  /* mini cart */
  function renderCart(c) {
    $$('[data-cart-count]').forEach(function (el) { el.textContent = c.count; el.hidden = !c.count; });
    var list = $('#mini-list'), foot = $('#mini-foot');
    if (!list) return;
    if (!c.lines.length) {
      list.innerHTML = '<p class="muted">Your cart is empty.</p><a class="btn btn-ghost btn-sm" href="' + BASE + '/shop">Browse products</a>';
      foot.hidden = true;
      return;
    }
    list.innerHTML = c.lines.map(function (l) {
      return '<div class="mini-item"><a href="' + esc(l.url) + '"><img src="' + esc(l.image) + '" alt="" width="64" height="64"></a>' +
        '<div><h3><a href="' + esc(l.url) + '">' + esc(l.name) + '</a></h3>' + (l.size ? '<p class="hint">' + esc(l.size) + '</p>' : '') +
        '<div class="qty" data-key="' + esc(l.key) + '" data-min="' + l.min + '" data-max="' + l.max + '" data-step="' + l.step + '">' +
        '<button type="button" data-step-by="-1" aria-label="Decrease quantity">\u2212</button>' +
        '<input type="number" inputmode="numeric" value="' + l.qty + '" min="' + l.min + '" max="' + l.max + '" step="' + l.step + '" aria-label="Quantity' + (l.loose ? ' in kg' : '') + '">' +
        '<button type="button" data-step-by="1" aria-label="Increase quantity">+</button></div></div>' +
        '<div class="mini-side"><strong>' + money(l.total) + '</strong><button type="button" class="link-btn" data-remove="' + esc(l.key) + '">Remove</button></div></div>';
    }).join('');
    $('#mini-subtotal').textContent = money(c.subtotal);
    foot.hidden = false;
  }
  function loadCart() { api('cart').then(function (r) { if (r.cart) renderCart(r.cart); }); }
  function changed(r) {
    if (!r.ok && r.message) toast(r.message);
    if (document.body.hasAttribute('data-cart-page')) { location.reload(); return; }
    if (r.cart) renderCart(r.cart);
  }

  /* steppers: the cart page submits its form, the mini cart saves through the API, others only change the number */
  function setQty(box, qty) {
    var min = +box.dataset.min || 1, max = +box.dataset.max || 99, step = +box.dataset.step || 1;
    qty = Math.min(max, Math.max(min, Math.round(qty / step) * step));
    box.querySelector('input').value = qty;
    if (box.dataset.key) api('set', { key: box.dataset.key, qty: qty }).then(changed);
    else if (box.closest('#cart-form')) box.closest('#cart-form').submit();
  }
  document.addEventListener('click', function (e) {
    var opener = e.target.closest('[data-open]');
    if (opener) {
      e.preventDefault();
      openDrawer(opener.getAttribute('data-open'));
      if (opener.getAttribute('data-open') === 'cart-drawer') loadCart();
      return;
    }
    if (e.target === overlay || e.target.closest('[data-close]')) { closeDrawers(); return; }
    var b = e.target.closest('[data-step-by]');
    if (b) {
      var box = b.closest('.qty');
      setQty(box, (+box.querySelector('input').value || 0) + (+b.dataset.stepBy) * (+box.dataset.step || 1));
      return;
    }
    var rm = e.target.closest('[data-remove]');
    if (rm) { api('remove', { key: rm.dataset.remove }).then(changed); return; }
    var add = e.target.closest('[data-add]');
    if (add) {
      add.disabled = true;
      api('add', { product_id: add.dataset.add, qty: 1 }).then(function (r) { add.disabled = false; changed(r); if (r.ok) openDrawer('cart-drawer'); });
      return;
    }
    var th = e.target.closest('[data-img]');
    if (th) {
      $('#main-img').src = th.dataset.img;
      $$('[data-img]').forEach(function (x) { x.setAttribute('aria-current', String(x === th)); });
    }
  });
  document.addEventListener('change', function (e) {
    var box = e.target.closest('.qty');
    if (box && e.target.tagName === 'INPUT') setQty(box, +e.target.value || +box.dataset.min || 1);
    if (e.target.hasAttribute('data-autosubmit')) e.target.form.submit();
  });

  /* product page */
  var pdp = $('#pdp-form');
  if (pdp) {
    pdp.addEventListener('click', function (e) { var s = e.target.closest('button[type="submit"]'); if (s) pdp.dataset.buy = s.name === 'buy' ? '1' : ''; });
    pdp.addEventListener('submit', function (e) {
      e.preventDefault();
      var buy = pdp.dataset.buy === '1';
      api('add', { product_id: pdp.dataset.product, qty: +$('#pdp-qty').value || 1 }).then(function (r) {
        if (r.ok && buy) { location.href = BASE + '/checkout'; return; }
        changed(r);
        if (r.ok) openDrawer('cart-drawer');
      });
    });
  }

  /* loose compost calculator */
  var lf = $('#loose-form');
  if (lf) (function () {
    var C = JSON.parse(lf.getAttribute('data-config'));
    var kgIn = $('#kg'), range = $('#kg-range'), warn = $('#kg-warn'), btn = $('#loose-add');
    function clamp(kg) { kg = Math.round((+kg || 0) / C.step) * C.step; return Math.min(C.max, Math.max(C.min, kg)); }
    function slab(kg) { var i = 0; C.slabs.forEach(function (s, n) { if (kg >= s[0]) i = n; }); return i; }
    function sale(r) { return Math.round(r * (100 - C.pct)) / 100; }
    function toRange(kg) { return C.max === C.min ? 0 : Math.round(Math.sqrt((kg - C.min) / (C.max - C.min)) * 1000); }
    function fromRange(v) { return clamp(C.min + (C.max - C.min) * Math.pow(v / 1000, 2)); }
    function put(id, html) { var el = document.getElementById(id); if (el) el.innerHTML = html; }
    function render(kg, from) {
      var i = slab(kg), base = C.slabs[i][1], rate = sale(base), sub = Math.round(rate * kg * 100) / 100;
      if (from !== 'input') kgIn.value = kg;
      if (from !== 'range') range.value = toRange(kg);
      put('l-rate', (rate < base ? '<del>' + money(base) + '</del> ' : '') + money(rate) + ' per kg');
      put('l-sub', kg.toLocaleString('en-IN') + ' kg \u00d7 ' + money(rate) + ' = ' + money(sub));
      put('l-bulk', (C.slabs[0][1] - base) * kg > 0 ? money((C.slabs[0][1] - base) * kg) : 'None at this quantity');
      put('l-offer', '-' + money((base - rate) * kg));
      put('l-perkg', money(rate));
      put('l-total', money(sub));
      $$('[data-slab]').forEach(function (tr) { tr.classList.toggle('is-active', +tr.dataset.slab === i); });
      $$('[data-kg]', lf).forEach(function (b) { b.setAttribute('aria-pressed', String(+b.dataset.kg === kg)); });
      btn.textContent = 'Add ' + kg.toLocaleString('en-IN') + ' kg to cart';
    }
    kgIn.addEventListener('input', function () {
      var v = +kgIn.value;
      warn.textContent = !kgIn.value ? '' : v < C.min ? 'Minimum order is ' + C.min + ' kg.' : v > C.max ? 'For more than ' + C.max.toLocaleString('en-IN') + ' kg, contact us for a transport quote.' : '';
      render(clamp(v), 'input');
    });
    kgIn.addEventListener('change', function () { warn.textContent = ''; render(clamp(kgIn.value)); });
    range.addEventListener('input', function () { warn.textContent = ''; render(fromRange(+range.value), 'range'); });
    $$('[data-kg]', lf).forEach(function (b) { b.addEventListener('click', function () { warn.textContent = ''; render(clamp(b.dataset.kg)); }); });
    lf.addEventListener('submit', function (e) {
      e.preventDefault();
      var kg = clamp(kgIn.value);
      render(kg);
      btn.disabled = true;
      api('loose', { kg: kg }).then(function (r) { btn.disabled = false; changed(r); if (r.ok) openDrawer('cart-drawer'); });
    });
    render(clamp(kgIn.value));
  })();

  /* shop filters on small screens, confirmations */
  var ft = $('.filter-toggle');
  if (ft) ft.addEventListener('click', function () { var f = $('.filters'); f.classList.toggle('is-open'); ft.setAttribute('aria-expanded', String(f.classList.contains('is-open'))); });
  document.addEventListener('submit', function (e) { var m = e.target.getAttribute('data-confirm'); if (m && !window.confirm(m)) e.preventDefault(); });
})();
