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

  // ── Pages de connexion : un seul envoi par formulaire ──
  // Si la page est lente, un 2e appui sur « Valider » renverrait le même code, déjà utilisé :
  // il serait alors refusé (« code incorrect ») alors que la connexion venait de réussir.
  document.querySelectorAll('.auth form').forEach(function (f) {
    f.addEventListener('submit', function (ev) {
      if (f.dataset.envoi === '1') { ev.preventDefault(); return; }
      f.dataset.envoi = '1';
      setTimeout(function () { // après l'envoi, sinon la valeur du bouton cliqué serait perdue
        f.querySelectorAll('button[type=submit], button:not([type])').forEach(function (b) {
          b.disabled = true;
          b.dataset.label = b.textContent;
          b.textContent = 'Vérification…';
        });
      }, 0);
    });
  });
  // Retour arrière : la page peut revenir du cache avec les boutons bloqués.
  window.addEventListener('pageshow', function () {
    document.querySelectorAll('.auth form[data-envoi="1"]').forEach(function (f) {
      f.dataset.envoi = '';
      f.querySelectorAll('button[disabled]').forEach(function (b) { b.disabled = false; if (b.dataset.label) b.textContent = b.dataset.label; });
    });
    document.querySelectorAll('input.otp').forEach(function (i) { if (i.form) i.form.dataset.sent = ''; });
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
    // Champs liés à un autre choix : data-if="nom:valeur1 valeur2"
    var ifs = function () {
      f.querySelectorAll('[data-if]').forEach(function (el) {
        var p = el.getAttribute('data-if').split(':'), c = f.querySelector('[name="' + p[0] + '"]');
        var on = !!c && p[1].split(' ').indexOf(c.value) !== -1;
        el.hidden = !on;
        el.querySelectorAll('input').forEach(function (i) { i.disabled = !on; });
      });
    };
    f.querySelectorAll('[data-ctl]').forEach(function (c) { c.addEventListener('change', ifs); });
    ifs();
  });

  // Heures de silence : champs affichés seulement si la case est cochée
  document.querySelectorAll('input[data-quiet]').forEach(function (cb) {
    var sync = function () {
      cb.form.querySelectorAll('.rp-quiet').forEach(function (el) { el.hidden = !cb.checked; });
    };
    cb.addEventListener('change', sync);
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

  // ── Application installée (écran d'accueil) ──
  var installee = (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches)
    || window.navigator.standalone === true;
  document.querySelectorAll('[data-app]').forEach(function (i) { i.value = installee ? '1' : '0'; });
  // Dans l'application installée, c'est forcément ton téléphone : la case est pré-cochée.
  if (installee) {
    document.querySelectorAll('[data-trust]').forEach(function (c) { c.checked = true; });
  }
  // ── Menu « Plus » de la barre d'onglets : fermeture en touchant le fond ou avec Échap ──
  var plus = document.querySelector('.tab-more');
  if (plus) {
    plus.addEventListener('click', function (e) {
      if (e.target.hasAttribute('data-sheet-close')) { plus.open = false; }
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && plus.open) { plus.open = false; }
    });
    window.addEventListener('pageshow', function () { plus.open = false; }); // retour arrière
  }

  // ── Tirer pour actualiser (dans l'application installée, Safari ne le fait pas) ──
  if (installee && 'ontouchstart' in window && document.body.classList.contains('app')) {
    var ptr = document.createElement('div');
    ptr.className = 'ptr';
    ptr.setAttribute('aria-hidden', 'true');
    ptr.appendChild(document.createElement('span'));
    document.body.appendChild(ptr);
    var y0 = null, tire = 0, SEUIL = 75;
    var place = function (d, anim) {
      ptr.style.transition = anim ? 'transform .25s, opacity .25s' : 'none';
      ptr.style.transform = 'translateY(' + (d - 60) + 'px) rotate(' + (d * 4) + 'deg)';
      ptr.style.opacity = d > 8 ? String(Math.min(1, d / SEUIL)) : '0';
    };
    window.addEventListener('touchstart', function (e) {
      y0 = (window.scrollY <= 0 && !(plus && plus.open) && e.touches.length === 1) ? e.touches[0].clientY : null;
      tire = 0;
    }, { passive: true });
    window.addEventListener('touchmove', function (e) {
      if (y0 === null) return;
      var dy = e.touches[0].clientY - y0;
      if (dy <= 0 || window.scrollY > 0) { y0 = null; place(0, true); return; }
      tire = Math.min(dy * 0.5, 110);
      place(tire, false);
    }, { passive: true });
    window.addEventListener('touchend', function () {
      if (y0 === null) return;
      y0 = null;
      if (tire >= SEUIL) {
        place(SEUIL, true);
        ptr.classList.add('go');
        location.reload();
      } else {
        place(0, true);
      }
    });
  }

  // ── Face ID (clé d'accès WebAuthn) ──
  var b64u = {
    enc: function (buf) {
      var s = '', b = new Uint8Array(buf);
      for (var i = 0; i < b.length; i++) s += String.fromCharCode(b[i]);
      return btoa(s).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
    },
    dec: function (str) {
      var s = atob(str.replace(/-/g, '+').replace(/_/g, '/') + '==='.slice((str.length + 3) % 4));
      var b = new Uint8Array(s.length);
      for (var i = 0; i < s.length; i++) b[i] = s.charCodeAt(i);
      return b.buffer;
    }
  };
  var poster = function (csrf, data) {
    var body = new URLSearchParams(data);
    body.set('csrf', csrf);
    return fetch('/faceid.php', { method: 'POST', credentials: 'same-origin', body: body, headers: { Accept: 'application/json' } })
      .then(function (r) { return r.json().catch(function () { return { error: 'Réponse inattendue du serveur.' }; }); });
  };
  var afficherErreur = function (box, msg) {
    var el = box.querySelector('[data-faceid-error]');
    if (el) { el.textContent = msg; el.hidden = !msg; }
  };
  var messageWebauthn = function (e) {
    if (e && e.name === 'NotAllowedError') return 'Face ID annulé ou refusé. Réessaie.';
    if (e && e.name === 'InvalidStateError') return 'Une clé existe déjà pour ce site sur cet appareil.';
    return 'Face ID indisponible sur cet appareil ou ce navigateur.';
  };

  // Écran verrouillé : déverrouiller.
  var verrou = document.querySelector('[data-faceid-unlock]');
  if (verrou) {
    var bouton = verrou.querySelector('[data-faceid-go]');
    var enCours = false;
    var deverrouiller = function () {
      if (enCours || !window.PublicKeyCredential) {
        if (!window.PublicKeyCredential) afficherErreur(verrou, messageWebauthn());
        return;
      }
      enCours = true;
      verrou.classList.add('wait');
      afficherErreur(verrou, '');
      navigator.credentials.get({ publicKey: {
        challenge: b64u.dec(verrou.getAttribute('data-challenge')),
        rpId: verrou.getAttribute('data-rp'),
        allowCredentials: [{ type: 'public-key', id: b64u.dec(verrou.getAttribute('data-cred')) }],
        userVerification: 'required',
        timeout: 60000
      } }).then(function (cred) {
        return poster(verrou.getAttribute('data-csrf'), {
          action: 'unlock',
          id: b64u.enc(cred.rawId),
          clientDataJSON: b64u.enc(cred.response.clientDataJSON),
          authenticatorData: b64u.enc(cred.response.authenticatorData),
          signature: b64u.enc(cred.response.signature)
        });
      }).then(function (rep) {
        if (rep.ok || rep.next) { location.replace(rep.next || '/'); return; }
        // Défi déjà utilisé : on recharge pour en obtenir un nouveau.
        afficherErreur(verrou, rep.error || 'Échec du déverrouillage.');
        setTimeout(function () { location.reload(); }, 1500);
      }).catch(function (e) {
        afficherErreur(verrou, messageWebauthn(e));
      }).then(function () {
        enCours = false;
        verrou.classList.remove('wait');
      });
    };
    bouton.addEventListener('click', deverrouiller);
    deverrouiller(); // lancement direct ; si le téléphone exige un geste, le bouton prend le relais
  }

  // Mon compte : activer Face ID sur cet appareil.
  var boite = document.querySelector('[data-faceid-box]');
  var activer = boite && boite.querySelector('[data-faceid-register]');
  if (activer) {
    activer.addEventListener('click', function () {
      if (!window.PublicKeyCredential) { afficherErreur(boite, messageWebauthn()); return; }
      activer.disabled = true;
      afficherErreur(boite, '');
      var csrf = boite.getAttribute('data-csrf');
      poster(csrf, { action: 'register_options' }).then(function (o) {
        if (o.error) throw new Error(o.error);
        return navigator.credentials.create({ publicKey: {
          challenge: b64u.dec(o.challenge),
          rp: o.rp,
          user: { id: b64u.dec(o.user.id), name: o.user.name, displayName: o.user.displayName },
          pubKeyCredParams: [{ type: 'public-key', alg: -7 }],
          authenticatorSelection: { authenticatorAttachment: 'platform', userVerification: 'required', residentKey: 'preferred' },
          attestation: 'none',
          timeout: 60000
        } });
      }).then(function (cred) {
        return poster(csrf, {
          action: 'register',
          clientDataJSON: b64u.enc(cred.response.clientDataJSON),
          attestationObject: b64u.enc(cred.response.attestationObject)
        });
      }).then(function (rep) {
        if (!rep.ok) throw new Error(rep.error || 'Échec de l\'activation.');
        location.reload();
      }).catch(function (e) {
        afficherErreur(boite, e && e.name && e.name !== 'Error' ? messageWebauthn(e) : ((e && e.message) || 'Échec de l\'activation.'));
        activer.disabled = false;
      });
    });
  }

  if ('serviceWorker' in navigator && location.protocol === 'https:') {
    navigator.serviceWorker.register('/sw.js').catch(function () {});
  }

  // ── Rechargement automatique (page Sauvegardes pendant une sauvegarde) ──
  var auto = document.querySelector('[data-autorefresh]');
  if (auto) {
    var sec = parseInt(auto.getAttribute('data-autorefresh'), 10) || 15;
    setTimeout(function () {
      // Pas de rechargement si l'utilisateur est en train de saisir quelque chose.
      var a = document.activeElement;
      if (a && /^(INPUT|TEXTAREA|SELECT)$/.test(a.tagName)) return;
      location.reload();
    }, sec * 1000);
  }
})();
