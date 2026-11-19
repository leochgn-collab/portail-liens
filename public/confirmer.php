<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';

// Confirmation par code 2FA avant une action d'administration, quand la session a été ouverte
// par le seul cookie d'appareil de confiance (sans Face ID).
$user = require_login();
$next = safe_path((string) ($_SESSION['confirm_next'] ?? '/'));
if (empty($_SESSION['weak'])) {
    redirect($next);
}
$error = null;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    require_post_csrf();
    stop_if_banned();
    $key = 'u' . $user['id'];
    if (too_many_attempts('2fa', $key, LOGIN_MAX_PER_USER)) {
        forget_current_device();
        logout_session();
        flash('Trop de codes incorrects : reconnecte-toi.');
        redirect('/login.php');
    }
    $secret = decrypt_secret($user['totp_secret']);
    $step = $secret ? totp_verify($secret, (string) ($_POST['code'] ?? ''), (int) $user['totp_last_step']) : null;
    if ($step !== null) {
        db()->prepare('UPDATE users SET totp_last_step = ? WHERE id = ?')->execute([$step, $user['id']]);
        clear_attempts('2fa', $key);
        unset($_SESSION['weak'], $_SESSION['confirm_next']);
        log_event((int) $user['id'], 'Identité confirmée par code 2FA (action d\'administration)');
        flash('Identité confirmée. Tu peux refaire ton action.');
        redirect($next);
    }
    login_failed('2fa', $key, $user, 'Code 2FA incorrect (confirmation d\'une action d\'administration)');
    stop_if_banned();
    $error = $secret ? totp_error_message($secret, (string) ($_POST['code'] ?? ''), (int) $user['totp_last_step']) : 'Code incorrect.';
}

auth_start('Confirmer');
?>
<section class="card auth-card">
  <header class="card-head">
    <h1>Confirme ton identité</h1>
    <p class="muted">Pour une action d'administration, saisis le code à 6 chiffres de ton application d'authentification. Active Face ID sur cet appareil (Mon compte) pour ne plus avoir à le faire.</p>
  </header>
  <?php if ($error): ?><div class="alert alert-error" role="alert"><?= e($error) ?></div><?php endif; ?>
  <form method="post" class="form">
    <?= csrf_field() ?>
    <label class="field">
      <span>Code</span>
      <input name="code" class="mono code-input otp" inputmode="numeric" pattern="[0-9 ]{6,7}" placeholder="000000" autocomplete="one-time-code" required autofocus maxlength="7">
    </label>
    <button class="btn btn-primary btn-block" type="submit">Confirmer</button>
  </form>
  <p class="auth-alt"><a href="<?= e($next) ?>">← Annuler</a></p>
</section>
<?php auth_end();
