<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';

start_session();
$user = pending_user();
if (!$user) {
    flash('Reconnecte-toi.');
    redirect('/login.php');
}
if (!$user['totp_enabled']) {
    redirect('/2fa-setup.php');
}

$error = null;
$useRecovery = isset($_GET['secours']) || isset($_POST['recovery']);

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    require_post_csrf();
    stop_if_banned();
    $key = 'u' . $user['id'];

    if (too_many_attempts('2fa', $key, LOGIN_MAX_PER_USER)) {
        unset($_SESSION['pre_uid'], $_SESSION['pre_at']);
        log_event((int) $user['id'], 'Double authentification bloquée (trop d\'essais)');
        flash('Trop de codes incorrects. Réessaie dans 15 minutes.');
        redirect('/login.php');
    }

    if ($useRecovery) {
        if (use_recovery_code((int) $user['id'], (string) ($_POST['recovery'] ?? ''))) {
            clear_attempts('2fa', $key);
            clear_attempts('user', strtolower($user['username']));
            log_event((int) $user['id'], 'Connexion avec un code de secours');
            notify("Code de secours utilisé par « {$user['username']} »",
                'Un code de secours a servi à se connecter. Si ce n\'est pas toi, change ton mot de passe.', 4, ['key']);
            $left = remaining_recovery_codes((int) $user['id']);
            complete_login($user, $left <= 2 ? '/compte.php' : '/', [
                'flash' => "Code de secours utilisé. Il t'en reste $left." . ($left <= 2 ? ' Génère-en de nouveaux.' : ''),
            ]);
        }
    } else {
        $secret = decrypt_secret($user['totp_secret']);
        $step = $secret ? totp_verify($secret, (string) ($_POST['code'] ?? ''), (int) $user['totp_last_step']) : null;
        if ($step !== null) {
            db()->prepare('UPDATE users SET totp_last_step = ? WHERE id = ?')->execute([$step, $user['id']]);
            clear_attempts('2fa', $key);
            clear_attempts('user', strtolower($user['username']));
            complete_login($user);
        }
    }

    login_failed('2fa', $key, $user, $useRecovery ? 'Code de secours incorrect' : 'Code 2FA incorrect');
    stop_if_banned();
    $error = $useRecovery ? 'Code de secours invalide ou déjà utilisé.' : 'Code incorrect ou expiré.';
}

auth_start('Vérification');
?>
<section class="card auth-card">
  <header class="card-head">
    <h1>Double authentification</h1>
    <p class="muted">Étape 2 sur 2 · <strong><?= e($user['username']) ?></strong></p>
  </header>
  <?php if ($error): ?><div class="alert alert-error" role="alert"><?= e($error) ?></div><?php endif; ?>

  <?php if ($useRecovery): ?>
    <form method="post" class="form">
      <?= csrf_field() ?>
      <label class="field">
        <span>Code de secours</span>
        <input name="recovery" class="mono code-input" placeholder="XXXXX-XXXXX" autocomplete="off" autocapitalize="characters" spellcheck="false" required autofocus maxlength="20">
      </label>
      <button class="btn btn-primary btn-block" type="submit">Valider</button>
    </form>
    <p class="auth-alt"><a href="/2fa.php">Utiliser plutôt l'application</a></p>
  <?php else: ?>
    <p class="muted">Ouvre ton application d'authentification et saisis le code à 6 chiffres.</p>
    <form method="post" class="form">
      <?= csrf_field() ?>
      <label class="field">
        <span>Code</span>
        <input name="code" class="mono code-input otp" inputmode="numeric" pattern="[0-9 ]{6,7}" placeholder="000000" autocomplete="one-time-code" required autofocus maxlength="7">
      </label>
      <button class="btn btn-primary btn-block" type="submit">Se connecter</button>
    </form>
    <p class="auth-alt"><a href="/2fa.php?secours=1">Téléphone perdu ? Utiliser un code de secours</a></p>
  <?php endif; ?>
  <p class="auth-alt"><a href="/login.php">← Changer de compte</a></p>
</section>
<?php auth_end();
