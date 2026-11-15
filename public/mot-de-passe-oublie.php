<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/motdepasse.php';

start_session();
reset_migrate();
if (current_user()) {
    redirect('/');
}

$available = mail_configured() && portal_url() !== '';
$error = null;

if ($available && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    require_post_csrf();
    stop_if_banned();
    $ip = client_ip();
    $login = trim((string) ($_POST['login'] ?? ''));

    if (too_many_attempts('reset-ip', $ip, RESET_MAX_PER_IP)) {
        $error = 'Trop de demandes. Réessaie dans 15 minutes.';
    } else {
        record_attempt('reset-ip', $ip);
        if ($login !== '' && mb_strlen($login) <= 254) {
            $st = db()->prepare(str_contains($login, '@')
                ? 'SELECT * FROM users WHERE email <> \'\' AND lower(email) = lower(?) ORDER BY id LIMIT 1'
                : 'SELECT * FROM users WHERE username = ?');
            $st->execute([$login]);
            $user = $st->fetch() ?: null;

            if ($user && valid_email((string) $user['email'])) {
                $uid = (int) $user['id'];
                if (!$user['totp_enabled']) {
                    // Sans double authentification, le lien suffirait à prendre le compte : on refuse.
                    log_event($uid, 'Réinitialisation refusée : double authentification pas encore activée');
                } elseif (too_many_attempts('reset-user', 'u' . $uid, RESET_MAX_PER_USER)) {
                    log_event($uid, 'Réinitialisation : trop de demandes, lien non envoyé');
                } else {
                    record_attempt('reset-user', 'u' . $uid);
                    $link = portal_url() . '/reinitialiser.php?t=' . create_reset($uid);
                    $minutes = intdiv(RESET_TTL, 60);
                    mail_later((string) $user['email'], 'Réinitialisation de ton mot de passe · ' . APP_NAME,
                        "Bonjour {$user['username']},\n\n"
                        . "Une demande de réinitialisation du mot de passe a été faite pour ton compte sur " . APP_NAME . ".\n\n"
                        . "Pour choisir un nouveau mot de passe, ouvre ce lien (valable $minutes minutes, une seule fois) :\n$link\n\n"
                        . "Ton code de double authentification te sera demandé.\n\n"
                        . "Si ce n'est pas toi, ignore ce message : ton mot de passe actuel reste valable.\n"
                        . "Demande faite depuis l'adresse IP $ip, le " . date('d/m/Y à H:i') . '.', $uid);
                    log_event($uid, 'Lien de réinitialisation du mot de passe envoyé');
                    notify("Mot de passe oublié pour « {$user['username']} »",
                        "Un lien de réinitialisation a été envoyé par mail. Si ce n'est pas toi, ignore-le.", 4, ['key']);
                }
            }
        }
        // Même réponse que le compte existe ou non (on ne révèle pas quels comptes existent).
        usleep(LOGIN_DELAY_MS * 1000);
        flash("Si ce compte existe et qu'une adresse e-mail y est enregistrée, un lien vient d'être envoyé. Pense à regarder dans les spams.");
        redirect('/mot-de-passe-oublie.php');
    }
}

auth_start('Mot de passe oublié');
?>
<section class="card auth-card">
  <header class="card-head">
    <h1>Mot de passe oublié</h1>
    <p class="muted">Reçois un lien par e-mail pour choisir un nouveau mot de passe.</p>
  </header>
  <?php if ($error): ?><div class="alert alert-error" role="alert"><?= e($error) ?></div><?php endif; ?>
  <?php if (!$available): ?>
    <div class="alert">La réinitialisation par e-mail n'est pas activée sur ce portail. Demande à un administrateur de te donner un mot de passe provisoire.</div>
  <?php else: ?>
    <form method="post" class="form">
      <?= csrf_field() ?>
      <label class="field">
        <span>Identifiant ou adresse e-mail</span>
        <input name="login" autocomplete="username" autocapitalize="none" spellcheck="false" required autofocus maxlength="254">
      </label>
      <button class="btn btn-primary btn-block" type="submit">Envoyer le lien</button>
    </form>
    <p class="muted small">Le lien seul ne suffit pas : ton code de double authentification (ou un code de secours) sera demandé.</p>
  <?php endif; ?>
  <p class="auth-alt"><a href="/login.php">← Retour à la connexion</a></p>
</section>
<?php auth_end();
