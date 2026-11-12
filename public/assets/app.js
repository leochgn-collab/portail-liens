(function () {
  'use strict';

  // ── QR code (activation 2FA) ──
  document.querySelectorAll('canvas[data-qr]').forEach(function (cv) {
    if (!window.QRCodeLib) return;
    var L = window.QRCodeLib;
    var qr = new L.QRCode(-1, L.Level.M);
    qr.addData(cv.getAttribute('data-qr'));
    qr.make();
    var n = qr.getModuleCount(), quiet = 3, total = n + quiet * 2;
    var px = Math.floor(cv.width / total), size = px * total;
    cv.width = cv.height = size;
    var ctx = cv.getContext('2d');
    ctx.fillStyle = '#ffffff';
    ctx.fillRect(0, 0, size, size);
    ctx.fillStyle = '#0a0d12';
    for (var r = 0; r < n; r++) {
      for (var c = 0; c < n; c++) {
        if (qr.isDark(r, c)) ctx.fillRect((c + quiet) * px, (r + quiet) * px, px, px);
      }
    }
  });

  // ── Champs de code : chiffres uniquement ──
  document.querySelectorAll('input.otp').forEach(function (inp) {
    inp.addEventListener('input', function () {
      var v = inp.value.replace(/\D/g, '').slice(0, 6);
      if (inp.value !== v) inp.value = v;
      if (v.length === 6 && inp.form && inp.form.dataset.sent !== '1') {
        inp.form.dataset.sent = '1';
        inp.form.requestSubmit ? inp.form.requestSubmit() : inp.form.submit();
      }
    });
  });

  // ── Confirmation avant suppression ──
  document.querySelectorAll('form[data-confirm]').forEach(function (f) {
    f.addEventListener('submit', function (ev) {
      if (!window.confirm(f.getAttribute('data-confirm'))) ev.preventDefault();
    });
  });

  // ── Copier / imprimer ──
  document.querySelectorAll('[data-copy]').forEach(function (b) {
    b.addEventListener('click', function () {
      var t = b.getAttribute('data-copy'), icon = b.classList.contains('icon-btn'), old = b.textContent;
      (navigator.clipboard ? navigator.clipboard.writeText(t) : Promise.reject()).then(function () {
        if (icon) { b.classList.add('copied'); setTimeout(function () { b.classList.remove('copied'); }, 1600); return; }
        b.textContent = 'Copié ✓';
        setTimeout(function () { b.textContent = old; }, 1600);
      }, function () { if (!icon) b.textContent = 'Copie impossible'; });
    });
  });
  document.querySelectorAll('[data-print]').forEach(function (b) {
    b.addEventListener('click', function () { window.print(); });
  });

  // ── Notes longues : « Afficher plus » ──
  document.querySelectorAll('.note').forEach(function (n) {
    var body = n.querySelector('.note-body'), more = n.querySelector('.note-more');
    if (!body || !more) return;
    if (body.scrollHeight > body.clientHeight + 4) more.hidden = false;
    more.addEventListener('click', function () {
      var open = n.classList.toggle('open');
      more.textContent = open ? 'Réduire' : 'Afficher plus';
    });
  });

  // ── Rappels : n'afficher que les champs utiles à la fréquence choisie ──
  document.querySelectorAll('form[data-rappel]').forEach(function (f) {
    var sel = f.querySelector('[data-freq]');
    if (!sel) return;
    var sync = function () {
      var k = sel.value;
      f.querySelectorAll('[data-show]').forEach(function (el) {
        var on = el.getAttribute('data-show').split(' ').indexOf(k) !== -1;
        el.hidden = !on;
        el.querySelectorAll('input').forEach(function (i) { i.disabled = !on; });
      });
      f.querySelectorAll('.rp-row').forEach(function (row) { row.hidden = !row.querySelector('.field:not([hidden])'); });
      f.querySelectorAll('[data-lbl-' + k + ']').forEach(function (el) { el.textContent = el.getAttribute('data-lbl-' + k); });
    };
    sel.addEventListener('change', sync);
    sync();
  });

  // ── Notification éphémère ──
  var toast = document.querySelector('.toast');
  if (toast) setTimeout(function () { toast.classList.add('hide'); }, 5000);

  // ── Recherche ──
  var search = document.getElementById('search');
  if (search) {
    var tiles = Array.prototype.slice.call(document.querySelectorAll('.tile, .note'));
    var groups = Array.prototype.slice.call(document.querySelectorAll('.group'));
    var none = document.getElementById('no-result');
    var norm = function (s) { return s.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, ''); };
    tiles.forEach(function (t) { t._s = norm(t.getAttribute('data-search') || ''); });

    var kind = 'all';
    document.querySelectorAll('.chip[data-filter]').forEach(function (c) {
      c.addEventListener('click', function () {
        kind = c.getAttribute('data-filter');
        document.querySelectorAll('.chip[data-filter]').forEach(function (o) { o.classList.toggle('active', o === c); });
        apply();
      });
    });
    var apply = function () {
      var q = norm(search.value.trim()), words = q.split(/\s+/).filter(Boolean), shown = 0;
      tiles.forEach(function (t) {
        var ok = (kind === 'all' || t.getAttribute('data-kind') === kind)
          && words.every(function (w) { return t._s.indexOf(w) !== -1; });
        t.hidden = !ok;
        if (ok) shown++;
      });
      groups.forEach(function (g) {
        g.hidden = !g.querySelector('.tile:not([hidden]), .note:not([hidden])');
        g.querySelectorAll('.grid').forEach(function (gr) { gr.hidden = !gr.querySelector('.tile:not([hidden]), .note:not([hidden])'); });
      });
      if (none) none.hidden = shown > 0 || !tiles.length;
    };
    search.addEventListener('input', apply);
    search.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') {
        var first = document.querySelector('.tile:not([hidden])');
        if (first) window.open(first.href, '_blank', 'noopener');
      } else if (e.key === 'Escape') {
        search.value = ''; apply(); search.blur();
      }
    });
    document.addEventListener('keydown', function (e) {
      var tag = (document.activeElement && document.activeElement.tagName) || '';
      if (e.key === '/' && tag !== 'INPUT' && tag !== 'TEXTAREA') { e.preventDefault(); search.focus(); }
    });
  }

  // ── Apparence : aperçu immédiat puis enregistrement ──
  document.querySelectorAll('form[data-autosubmit]').forEach(function (f) {
    f.addEventListener('change', function (ev) {
      var t = ev.target;
      if (t.name === 'theme') document.documentElement.setAttribute('data-theme', t.value);
      if (t.name === 'fond') document.documentElement.setAttribute('data-fond', t.value);
      f.submit();
    });
  });

  // ── État des services ──
  var box = document.getElementById('groups');
  var url = box && box.getAttribute('data-status');
  if (url) {
    var load = function () {
      fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
        .then(function (r) { return r.ok ? r.json() : {}; })
        .then(function (data) {
          var up = 0, total = 0;
          document.querySelectorAll('.tile[data-id]').forEach(function (t) {
            var s = data[t.getAttribute('data-id')], dot = t.querySelector('.dot');
            if (!s || !dot) return;
            total++;
            if (s.up) up++;
            dot.className = 'dot ' + (s.up ? 'up' : 'down');
            var st = t.querySelector('.st');
            if (st) st.textContent = s.up ? 'En ligne · ' + s.ms + ' ms' : 'Hors ligne';
            dot.title = (s.up ? 'En ligne · ' + s.ms + ' ms' : 'Hors ligne' + (s.code ? ' (erreur ' + s.code + ')' : ''))
              + (t.getAttribute('data-kind') === 'private' ? ' — vu depuis le serveur' : '');
          });
          var st = document.getElementById('stat-online');
          if (st) st.textContent = total ? up + ' / ' + total : '—';
        })
        .catch(function () {});
    };
    load();
    setInterval(load, 120000);
  }
})();
