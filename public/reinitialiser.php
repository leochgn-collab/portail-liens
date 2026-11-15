<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/motdepasse.php';

start_session();
reset_migrate();
stop_if_banned();

$token = (string) ($_POST['t'] ?? $_GET['t'] ?? '');
$user = find_reset($token);
$error = null;

if (!$user) {
    if ($token !== '') {
        // Un lien inventé ou périmé compte comme un essai raté (blocage de l'IP si ça insiste).
        login_failed('reset', client_ip(), null, 'Lien de réinitialisation invalide ou expiré');
    }
    auth_start('Lien invalide');
    ?>
<section class="card auth-card">
  <header class="card-head"><h1>Lien invalide</h1></header>
  <div class="alert alert-error" role="alert">Ce lien est invalide, déjà utilisé ou expiré.</div>
  <p class="auth-alt"><a href="/mot-de-passe-oublie.php">Demander un nouveau lien</a></p>
  <p class="auth-alt"><a href="/login.php">← Retour à la connexion</a></p>
</section>
<?php
    auth_end();
    exit;
}

$uid = (int) $user['id'];
$key = 'u' . $uid;

if (!$user['totp_enabled']) {
    $error = "Ce compte n'a pas encore de double authentification : demande à un administrateur de réinitialiser ton mot de passe.";
} elseif (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    require_post_csrf();

    if (too_many_attempts('reset-code', $key, LOGIN_MAX_PER_USER)) {
        db()->prepare('DELETE FROM password_resets WHERE user_id = ?')->execute([$uid]);
        log_event($uid, 'Réinitialisation annulée : trop de codes incorrects');
        notify("Réinitialisation bloquée pour « {$user['username']} »", 'Trop de codes 2FA incorrects : le lien a été annulé.', 4, ['lock']);
        flash('Trop de codes incorrects : ce lien a été annulé. Demande-en un nouveau dans 15 minutes.');
        redirect('/login.php');
    }

    $new = (string) ($_POST['new'] ?? '');
    $code = trim((string) ($_POST['code'] ?? ''));
    $problem = password_problem($new);

    if ($problem) {
        $error = $problem;
    } elseif ($new !== (string) ($_POST['confirm'] ?? '')) {
        $error = 'Les deux mots de passe ne sont pas identiques.';
    } else {
        // Code de l'application (6 chiffres) ou code de secours (XXXXX-XXXXX).
        $digits = preg_replace('/\s+/', '', $code);
        $step = null;
        $viaRecovery = false;
        if (preg_match('/^\d{6}$/', $digits)) {
            $secret = decrypt_secret($user['totp_secret']);
            $step = $secret ? totp_verify($secret, $digits, (int) $user['totp_last_step']) : null;
        } elseif ($code !== '') {
            $viaRecovery = use_recovery_code($uid, $code);
        }

        if ($step === null && !$viaRecovery) {
            login_failed('reset-code', $key, $user, 'Réinitialisation : code 2FA incorrect');
            $error = 'Code incorrect ou expiré.';
        } else {
            $pdo = db();
            $pdo->beginTransaction();
            $pdo->prepare('UPDATE users SET password_hash = ?, must_change_pwd = 0, pwd_version = pwd_version + 1'
                . ($step !== null ? ', totp_last_step = ?' : '') . ' WHERE id = ?')
                ->execute($step !== null
                    ? [password_hash($new, password_algo()), $step, $uid]
                    : [password_hash($new, password_algo()), $uid]);
            $pdo->prepare('DELETE FROM password_resets WHERE user_id = ?')->execute([$uid]);
            $pdo->commit();

            clear_attempts('reset-code', $key);
            clear_attempts('user', strtolower($user['username']));
            log_event($uid, 'Mot de passe réinitialisé par e-mail' . ($viaRecovery ? ' (code de secours)' : ''));
            notify("Mot de passe réinitialisé pour « {$user['username']} »",
                "Le mot de passe a été changé avec le lien reçu par mail. Si ce n'est pas toi, préviens un administrateur.", 4, ['key']);
            mail_later((string) $user['email'], 'Ton mot de passe a été changé · ' . APP_NAME,
                "Bonjour {$user['username']},\n\nLe mot de passe de ton compte sur " . APP_NAME . ' vient d\'être changé'
                . ' (depuis l\'adresse IP ' . client_ip() . ', le ' . date('d/m/Y à H:i') . ").\n"
                . "Toutes tes sessions ouvertes ont été déconnectées.\n\n"
                . "Si ce n'est pas toi, préviens tout de suite un administrateur.", $uid);

            unset($_SESSION['pre_uid'], $_SESSION['pre_at']);
            session_regenerate_id(true);
            flash('Mot de passe changé. Connecte-toi avec le nouveau.' . ($viaRecovery
                ? ' Un code de secours a été utilisé : pense à en regénérer dans « Mon compte ».' : ''));
            redirect('/login.php');
        }
    }
}

auth_start('Nouveau mot de passe');
?>
<section class="card auth-card">
  <header class="card-head">
    <h1>Nouveau mot de passe</h1>
    <p class="muted">Compte <strong><?= e($user['username']) ?></strong></p>
  </header>
  <?php if ($error): ?><div class="alert alert-error" role="alert"><?= e($error) ?></div><?php endif; ?>
  <?php if ($user['totp_enabled']): ?>
  <form method="post" class="form">
    <?= csrf_field() ?>
    <input type="hidden" name="t" value="<?= e($token) ?>">
    <input type="text" name="username" value="<?= e($user['username']) ?>" autocomplete="username" hidden>
    <label class="field"><span>Nouveau mot de passe <em>12 caractères minimum</em></span>
      <input type="password" name="new" autocomplete="new-password" minlength="12" maxlength="200" required autofocus></label>
    <label class="field"><span>Confirmer</span>
      <input type="password" name="confirm" autocomplete="new-password" minlength="12" maxlength="200" required></label>
    <label class="field"><span>Code de double authentification <em>ou code de secours</em></span>
      <input name="code" class="mono code-input" placeholder="000000" autocomplete="one-time-code" autocapitalize="characters" spellcheck="false" required maxlength="20"></label>
    <button class="btn btn-primary btn-block" type="submit">Changer le mot de passe</button>
  </form>
  <?php endif; ?>
  <p class="auth-alt"><a href="/login.php">← Retour à la connexion</a></p>
</section>
<?php auth_end();
