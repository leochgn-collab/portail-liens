<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';

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
