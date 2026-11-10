(function () {
  // toggle hidden "new ..." panels
  document.querySelectorAll('[data-toggle]').forEach(function (b) {
    b.addEventListener('click', function () {
      var p = document.getElementById(b.getAttribute('data-toggle'));
      if (p) { p.classList.toggle('open'); var f = p.querySelector('input:not([type=hidden]),select'); if (f && p.classList.contains('open')) f.focus(); }
    });
  });
  // confirm dialogs
  document.querySelectorAll('[data-confirm]').forEach(function (el) {
    el.addEventListener('click', function (e) { if (!window.confirm(el.getAttribute('data-confirm'))) e.preventDefault(); });
  });
  // clickable rows
  document.querySelectorAll('tr[data-href]').forEach(function (tr) {
    tr.addEventListener('click', function (e) { if (e.target.closest('a,button,input,select')) return; window.location = tr.getAttribute('data-href'); });
  });
  // auto-hide success flashes
  setTimeout(function () {
    document.querySelectorAll('.flash-success').forEach(function (f) { f.style.transition = 'opacity .4s'; f.style.opacity = '0'; setTimeout(function () { f.remove(); }, 450); });
  }, 6000);
})();
