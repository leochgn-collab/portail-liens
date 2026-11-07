<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';

start_session();
if (current_user()) {
    redirect('/');
}

$error = null;
$username = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    require_post_csrf();
    stop_if_banned();
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $ip = client_ip();
    $userKey = strtolower($username);

    if (too_many_attempts('user', $userKey, LOGIN_MAX_PER_USER)) {
        $error = 'Trop de tentatives sur ce compte. Réessaie dans 15 minutes.';
        log_event(null, 'Connexion bloquée (trop d\'essais) pour « ' . mb_substr($username, 0, 40) . ' »');
        // Insister sur un compte bloqué compte aussi pour le blocage de l'IP.
        record_attempt('ip', $ip);
        if (too_many_attempts('ip', $ip, LOGIN_MAX_PER_IP)) {
            ban_ip($ip);
        }
        usleep(LOGIN_DELAY_MS * 1000);
    } else {
        $st = db()->prepare('SELECT * FROM users WHERE username = ?');
        $st->execute([$username]);
        $user = $st->fetch() ?: null;

        // Toujours vérifier un hash, même si le compte n'existe pas (même durée de réponse).
        $hash = $user['password_hash'] ?? password_hash(bin2hex(random_bytes(8)), password_algo());
        $ok = password_verify($password, $hash) && $user !== null;

        if (!$ok) {
            login_failed('user', $userKey, $user, 'Mot de passe incorrect pour « ' . mb_substr($username, 0, 40) . ' »');
            $left = ip_ban_remaining($ip);
            $error = $left > 0
                ? 'Trop de tentatives depuis ton adresse. Réessaie dans ' . human_duration($left) . '.'
                : 'Identifiant ou mot de passe incorrect.';
        } else {
            if (password_needs_rehash($user['password_hash'], password_algo())) {
                db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                    ->execute([password_hash($password, password_algo()), $user['id']]);
            }
            session_regenerate_id(true);
            $_SESSION['pre_uid'] = (int) $user['id'];
            $_SESSION['pre_at']  = time();
            redirect($user['totp_enabled'] ? '/2fa.php' : '/2fa-setup.php');
        }
    }
}

auth_start('Connexion');
?>
<section class="card auth-card">
  <header class="card-head">
    <h1>Connexion</h1>
    <p class="muted">Accès réservé aux membres. Étape 1 sur 2.</p>
  </header>
  <?php if ($error): ?><div class="alert alert-error" role="alert"><?= e($error) ?></div><?php endif; ?>
  <form method="post" class="form" autocomplete="on">
    <?= csrf_field() ?>
    <label class="field">
      <span>Identifiant</span>
      <input name="username" value="<?= e($username) ?>" autocomplete="username" autocapitalize="none" spellcheck="false" required autofocus maxlength="64">
    </label>
    <label class="field">
      <span>Mot de passe</span>
      <input name="password" type="password" autocomplete="current-password" required maxlength="200">
    </label>
    <button class="btn btn-primary btn-block" type="submit">Continuer</button>
  </form>
</section>
<?php auth_end();
