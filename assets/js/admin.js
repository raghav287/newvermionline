/* Admin panel: confirmation prompts and the mobile menu. */
(function () {
  'use strict';
  document.addEventListener('submit', function (e) { var m = e.target.getAttribute('data-confirm'); if (m && !window.confirm(m)) e.preventDefault(); });
  document.addEventListener('click', function (e) {
    var t = e.target.closest('[data-toggle]');
    if (t) { var el = document.getElementById(t.getAttribute('data-toggle')); if (el) el.classList.toggle('is-open'); }
  });
})();
