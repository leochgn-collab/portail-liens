<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/motdepasse.php';

reset_migrate();
$user = require_login();
$errors = [];
$newCodes = $_SESSION['new_codes'] ?? null;
unset($_SESSION['new_codes']);

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    require_post_csrf();
    $action  = (string) ($_POST['action'] ?? '');

    if ($action === 'appearance') {
        $theme = (string) ($_POST['theme'] ?? '');
        $fond  = (string) ($_POST['fond'] ?? '');
        if (isset(THEMES[$theme], FONDS[$fond])) {
            db()->prepare('UPDATE users SET theme = ?, fond = ? WHERE id = ?')->execute([$theme, $fond, $user['id']]);
            remember_appearance($theme, $fond);
            flash('Apparence enregistrée.');
        }
        redirect('/compte.php');
    }
    if ($action === 'faceid_off') {
        $here = (int) ($_SESSION['device_id'] ?? 0);
        db()->prepare('DELETE FROM faceid_creds WHERE user_id = ? AND device_id = ?')->execute([$user['id'], $here]);
        $_SESSION['faceid'] = false;
        log_event((int) $user['id'], 'Face ID désactivé sur un appareil de confiance');
        flash('Face ID désactivé sur cet appareil. Pense à supprimer aussi la clé d\'accès dans l\'app Mots de passe de l\'iPhone.');
        redirect('/compte.php');
    }
    if ($action === 'device_forget' || $action === 'devices_forget_all') {
        $one = $action === 'device_forget' ? (int) ($_POST['device'] ?? 0) : null;
        $n = forget_devices((int) $user['id'], $one);
        log_event((int) $user['id'], $one ? 'Appareil de confiance retiré' : "Tous les appareils de confiance retirés ($n)");
        if ($one === null || $one === (int) ($_SESSION['device_id'] ?? 0)) {
            clear_trust_cookie();
        }
        flash($one ? 'Appareil retiré : il devra refaire la double authentification.' : "$n appareil(s) retiré(s).");
        redirect('/compte.php');
    }
    $current = (string) ($_POST['current'] ?? '');
    $key = 'acct' . $user['id'];

    if (too_many_attempts('acct', $key, LOGIN_MAX_PER_USER)) {
        $errors[] = 'Trop d\'essais. Réessaie dans 15 minutes.';
    } elseif (!password_verify($current, $user['password_hash'])) {
        record_attempt('acct', $key);
        $errors[] = 'Mot de passe actuel incorrect.';
    } elseif ($action === 'password') {
        $new = (string) ($_POST['new'] ?? '');
        $problem = password_problem($new);
        if ($problem) {
            $errors[] = $problem;
        } elseif ($new !== (string) ($_POST['confirm'] ?? '')) {
            $errors[] = 'Les deux nouveaux mots de passe ne sont pas identiques.';
        } elseif (password_verify($new, $user['password_hash'])) {
            $errors[] = 'Choisis un mot de passe différent de l\'actuel.';
        } else {
            db()->prepare('UPDATE users SET password_hash = ?, must_change_pwd = 0, pwd_version = pwd_version + 1 WHERE id = ?')
                ->execute([password_hash($new, password_algo()), $user['id']]);
            log_event((int) $user['id'], 'Mot de passe modifié');
            notify("Mot de passe modifié pour « {$user['username']} »", 'Les autres sessions et tous les appareils de confiance ont été déconnectés. Si ce n\'est pas toi, préviens un administrateur.', 4, ['key']);
            // Les autres sessions sont déconnectées ; on garde celle-ci.
            $_SESSION['pwd_version'] = (int) $user['pwd_version'] + 1;
            session_regenerate_id(true);
            flash('Mot de passe modifié. Tes autres sessions ont été déconnectées.');
            redirect('/compte.php');
        }
    } elseif ($action === 'codes') {
        $_SESSION['new_codes'] = regenerate_recovery_codes((int) $user['id']);
        log_event((int) $user['id'], 'Nouveaux codes de secours générés');
        flash('Nouveaux codes générés. Les anciens ne fonctionnent plus.');
        redirect('/compte.php');
    } elseif ($action === 'email') {
        $email = trim((string) ($_POST['email'] ?? ''));
        $taken = db()->prepare('SELECT COUNT(*) FROM users WHERE id <> ? AND email <> \'\' AND lower(email) = lower(?)');
        $taken->execute([$user['id'], $email]);
        if ($email !== '' && !valid_email($email)) {
            $errors[] = 'Adresse e-mail invalide.';
        } elseif ($email !== '' && (int) $taken->fetchColumn() > 0) {
            $errors[] = 'Cette adresse est déjà utilisée par un autre compte.';
        } else {
            db()->prepare('UPDATE users SET email = ? WHERE id = ?')->execute([$email, $user['id']]);
            log_event((int) $user['id'], $email === '' ? 'Adresse e-mail retirée' : 'Adresse e-mail modifiée');
            // L'adresse sert à réinitialiser le mot de passe : tout changement est signalé.
            notify("Adresse e-mail modifiée pour « {$user['username']} »", $email === '' ? 'L\'adresse a été retirée.' : 'Nouvelle adresse enregistrée pour « mot de passe oublié ».', 4, ['envelope']);
            if ($email !== '' && mail_configured()) {
                $err = send_mail($email, 'Adresse enregistrée · ' . APP_NAME,
                    "Bonjour {$user['username']},\n\nCette adresse est maintenant celle de ton compte sur " . APP_NAME . ".\n"
                    . "Elle servira à recevoir un lien si tu oublies ton mot de passe.\n\n"
                    . "Si ce n'est pas toi, préviens un administrateur.");
                flash($err === null
                    ? 'Adresse enregistrée. Un e-mail de test vient de t\'être envoyé.'
                    : 'Adresse enregistrée, mais l\'e-mail de test n\'est pas parti : ' . $err);
            } else {
                flash($email === '' ? 'Adresse e-mail retirée.' : 'Adresse enregistrée.');
            }
            redirect('/compte.php');
        }
    }
}

$left = remaining_recovery_codes((int) $user['id']);
$journal = db()->prepare('SELECT * FROM journal WHERE user_id = ? ORDER BY id DESC LIMIT 8');
$journal->execute([$user['id']]);

page_start('Mon compte', $user, 'compte');
?>
<header class="topbar">
  <div>
    <p class="eyebrow">Sécurité</p>
    <h1>Mon compte</h1>
  </div>
</header>

<?php if ($user['must_change_pwd']): ?>
  <div class="alert alert-warn">Ton mot de passe est provisoire. Choisis-en un nouveau pour accéder au portail.</div>
<?php endif; ?>
<?php foreach ($errors as $err): ?>
  <div class="alert alert-error" role="alert"><?= e($err) ?></div>
<?php endforeach; ?>

<?php if ($newCodes): ?>
  <section class="panel panel-accent">
    <h2><?= icon('key') ?> Tes codes de secours</h2>
    <p class="muted">Chacun permet <strong>une seule</strong> connexion si tu perds ton téléphone. Note-les ou imprime-les maintenant : ils ne seront plus jamais affichés.</p>
    <ul class="codes mono">
      <?php foreach ($newCodes as $c): ?><li><?= e($c) ?></li><?php endforeach; ?>
    </ul>
    <div class="row">
      <button class="btn btn-ghost btn-sm" type="button" data-copy="<?= e(implode("\n", $newCodes)) ?>">Copier</button>
      <button class="btn btn-ghost btn-sm" type="button" data-print>Imprimer</button>
    </div>
  </section>
<?php endif; ?>

<div class="cols">
  <section class="panel">
    <h2>Changer le mot de passe</h2>
    <form method="post" class="form">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="password">
      <input type="text" name="username" value="<?= e($user['username']) ?>" autocomplete="username" hidden>
      <label class="field"><span>Mot de passe actuel</span>
        <input type="password" name="current" autocomplete="current-password" required></label>
      <label class="field"><span>Nouveau mot de passe <em>12 caractères minimum</em></span>
        <input type="password" name="new" autocomplete="new-password" minlength="12" required></label>
      <label class="field"><span>Confirmer</span>
        <input type="password" name="confirm" autocomplete="new-password" minlength="12" required></label>
      <button class="btn btn-primary" type="submit">Enregistrer</button>
    </form>
  </section>

  <section class="panel">
    <h2>Double authentification</h2>
    <p class="status-line"><span class="dot up"></span> Activée avec une application d'authentification</p>
    <p class="muted">Codes de secours restants : <strong class="<?= $left <= 2 ? 'warn' : '' ?>"><?= $left ?> / 8</strong></p>
    <form method="post" class="form">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="codes">
      <label class="field"><span>Mot de passe actuel</span>
        <input type="password" name="current" autocomplete="current-password" required></label>
      <button class="btn btn-ghost" type="submit">Générer de nouveaux codes de secours</button>
    </form>
    <p class="muted small">Nouveau téléphone ? Demande à un administrateur de réinitialiser ta double authentification : tu la reconfigureras à la prochaine connexion.</p>
  </section>
</div>

<section class="panel">
  <h2>Adresse e-mail</h2>
  <p class="muted">Sert uniquement à recevoir un lien si tu oublies ton mot de passe<?= mail_configured() ? '' : ' (l\'envoi de mails n\'est pas encore configuré sur ce portail)' ?>.</p>
  <form method="post" class="form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="email">
    <input type="text" name="username" value="<?= e($user['username']) ?>" autocomplete="username" hidden>
    <label class="field"><span>Adresse e-mail <em>laisser vide pour la retirer</em></span>
      <input type="email" name="email" value="<?= e((string) ($user['email'] ?? '')) ?>" autocomplete="email" maxlength="254"></label>
    <label class="field"><span>Mot de passe actuel</span>
      <input type="password" name="current" autocomplete="current-password" required></label>
    <button class="btn btn-primary" type="submit">Enregistrer l'adresse</button>
  </form>
</section>

<section class="panel">
  <h2>Apparence</h2>
  <form method="post" class="form" data-autosubmit>
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="appearance">
    <div class="field"><span>Couleur</span>
      <div class="swatches">
        <?php foreach (THEMES as $k => $label): ?>
          <label class="swatch sw-<?= e($k) ?>"><input type="radio" name="theme" value="<?= e($k) ?>" <?= $user['theme'] === $k ? 'checked' : '' ?>><span><?= e($label) ?></span></label>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="field"><span>Fond</span>
      <div class="seg">
        <?php foreach (FONDS as $k => $label): ?>
          <label><input type="radio" name="fond" value="<?= e($k) ?>" <?= $user['fond'] === $k ? 'checked' : '' ?>><span><?= e($label) ?></span></label>
        <?php endforeach; ?>
      </div>
    </div>
    <noscript><button class="btn btn-primary" type="submit">Enregistrer</button></noscript>
  </form>
</section>

<?php $devices = user_devices((int) $user['id']); $here = (int) ($_SESSION['device_id'] ?? 0); ?>
<section class="panel">
  <h2><?= icon('key') ?> Appareils de confiance <span class="count"><?= count($devices) ?></span></h2>
  <p class="muted small">Ces appareils se connectent sans mot de passe ni code pendant <?= (int) TRUST_DAYS ?> jours après leur dernière utilisation. Retire tout appareil perdu ou que tu ne reconnais pas. Changer ton mot de passe les retire tous.</p>
  <?php $hereCred = $here ? faceid_cred_for_device($here) : null; ?>
  <?php if ($here): ?>
  <div class="faceid-box" data-faceid-box data-csrf="<?= e(csrf_token()) ?>">
    <?php if ($hereCred): ?>
      <p><strong>🔒 Face ID est activé sur cet appareil.</strong> <span class="muted">L'application le demande quand tu l'ouvres après <?= (int) round(FACEID_LOCK / 60) ?> min sans l'utiliser.</span></p>
      <form method="post" data-confirm="Désactiver Face ID sur cet appareil ?">
        <?= csrf_field() ?><input type="hidden" name="action" value="faceid_off">
        <button class="btn btn-ghost btn-sm" type="submit">Désactiver Face ID</button>
      </form>
    <?php else: ?>
      <p><strong>Protéger l'application avec Face ID</strong> <span class="muted">Face ID (ou Touch ID, ou le code de l'appareil) sera demandé à l'ouverture de l'application.</span></p>
      <button class="btn btn-primary btn-sm" type="button" data-faceid-register>🔒 Activer Face ID sur cet appareil</button>
      <div class="alert alert-error" role="alert" data-faceid-error hidden></div>
    <?php endif; ?>
  </div>
  <?php endif; ?>
  <?php if (!$devices): ?>
    <p class="muted">Aucun appareil de confiance. Coche « Faire confiance à cet appareil » lors de la double authentification.</p>
  <?php else: ?>
  <table class="table">
    <thead><tr><th>Appareil</th><th>Ajouté</th><th>Dernière utilisation</th><th>Adresse IP</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($devices as $d): ?>
      <tr>
        <td><strong><?= e($d['label']) ?></strong><?= (int) $d['id'] === $here ? ' <span class="badge">Cet appareil</span>' : '' ?><?= faceid_cred_for_device((int) $d['id']) ? ' <span class="badge badge-muted">Face ID</span>' : '' ?></td>
        <td class="nowrap"><?= e(french_datetime((int) $d['created_at'])) ?></td>
        <td class="nowrap"><?= e(french_datetime((int) $d['last_used'])) ?></td>
        <td class="mono"><?= e((string) $d['last_ip']) ?></td>
        <td class="actions">
          <form method="post" data-confirm="Retirer « <?= e($d['label']) ?> » ? Il devra refaire la double authentification.">
            <?= csrf_field() ?><input type="hidden" name="action" value="device_forget"><input type="hidden" name="device" value="<?= (int) $d['id'] ?>">
            <button class="btn btn-danger btn-sm" type="submit">Retirer</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php if (count($devices) > 1): ?>
  <form method="post" data-confirm="Retirer tous les appareils de confiance ?" class="row">
    <?= csrf_field() ?><input type="hidden" name="action" value="devices_forget_all">
    <button class="btn btn-ghost btn-sm" type="submit">Tout retirer</button>
  </form>
  <?php endif; ?>
  <?php endif; ?>
</section>

<section class="panel">
  <h2>Activité récente</h2>
  <table class="table">
    <thead><tr><th>Date</th><th>Événement</th><th>Adresse IP</th></tr></thead>
    <tbody>
    <?php foreach ($journal as $j): ?>
      <tr><td class="nowrap"><?= e(french_datetime((int) $j['at'])) ?></td><td><?= e($j['event']) ?></td><td class="mono"><?= e($j['ip']) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</section>
<?php page_end();
