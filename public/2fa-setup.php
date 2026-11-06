<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';

// Activation obligatoire de la double authentification à la première connexion.
start_session();
$user = pending_user();
if (!$user) {
    flash('Reconnecte-toi.');
    redirect('/login.php');
}
if ($user['totp_enabled']) {
    redirect('/2fa.php');
}

$_SESSION['enrol_secret'] ??= totp_new_secret();
$secret = $_SESSION['enrol_secret'];
$error = null;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    require_post_csrf();
    $key = 'u' . $user['id'];
    if (too_many_attempts('2fa', $key, LOGIN_MAX_PER_USER)) {
        unset($_SESSION['pre_uid'], $_SESSION['pre_at'], $_SESSION['enrol_secret']);
        flash('Trop de codes incorrects. Réessaie dans 15 minutes.');
        redirect('/login.php');
    }
    $step = totp_verify($secret, (string) ($_POST['code'] ?? ''));
    if ($step === null) {
        record_attempt('2fa', $key);
        $error = 'Code incorrect. Vérifie que l\'heure de ton téléphone est automatique, puis réessaie.';
    } else {
        db()->prepare('UPDATE users SET totp_secret = ?, totp_enabled = 1, totp_last_step = ? WHERE id = ?')
            ->execute([encrypt_secret($secret), $step, $user['id']]);
        $codes = regenerate_recovery_codes((int) $user['id']);
        clear_attempts('2fa', $key);
        log_event((int) $user['id'], 'Double authentification activée');
        $user = find_user((int) $user['id']);
        complete_login($user, '/compte.php', [
            'new_codes' => $codes,
            'flash'     => 'Double authentification activée. Note tes codes de secours maintenant.',
        ]);
    }
}

$uri = totp_uri($secret, $user['username']);
auth_start('Activer la double authentification');
?>
<section class="card auth-card auth-card-wide">
  <header class="card-head">
    <h1>Activer la double authentification</h1>
    <p class="muted">Obligatoire pour accéder au portail. À faire une seule fois.</p>
  </header>
  <?php if ($error): ?><div class="alert alert-error" role="alert"><?= e($error) ?></div><?php endif; ?>

  <ol class="steps">
    <li>
      <strong>Installe une application d'authentification</strong>
      <span class="muted">Google Authenticator, Microsoft Authenticator, Authy, ou l'app Mots de passe de l'iPhone.</span>
    </li>
    <li>
      <strong>Scanne ce QR code</strong>
      <div class="qr-wrap">
        <canvas class="qr" data-qr="<?= e($uri) ?>" width="220" height="220" aria-label="QR code à scanner"></canvas>
      </div>
      <details class="manual">
        <summary>Impossible de scanner ? Saisir la clé à la main</summary>
        <code class="secret mono"><?= e(trim(chunk_split($secret, 4, ' '))) ?></code>
        <a class="btn btn-ghost btn-sm" href="<?= e($uri) ?>">Ouvrir dans l'app (sur ce téléphone)</a>
      </details>
    </li>
    <li>
      <strong>Saisis le code affiché par l'application</strong>
      <form method="post" class="form form-inline">
        <?= csrf_field() ?>
        <input name="code" class="mono code-input otp" inputmode="numeric" pattern="[0-9 ]{6,7}" placeholder="000000" autocomplete="one-time-code" required maxlength="7" aria-label="Code à 6 chiffres">
        <button class="btn btn-primary" type="submit">Activer</button>
      </form>
    </li>
  </ol>
</section>
<?php auth_end();
