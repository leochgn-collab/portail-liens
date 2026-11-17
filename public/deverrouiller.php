<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';

// Écran verrouillé : Face ID (clé d'accès) pour rouvrir la session d'un appareil de confiance.
start_session();
$uid = (int) ($_SESSION['unlock_uid'] ?? 0);
$dev = (int) ($_SESSION['unlock_dev'] ?? 0);
$user = $uid ? find_user($uid) : null;
$cred = $dev ? faceid_cred_for_device($dev) : null;
if (!$user || !$cred || (int) $cred['user_id'] !== $uid) {
    unset($_SESSION['unlock_uid'], $_SESSION['unlock_dev']);
    redirect('/login.php');
}
$challenge = faceid_new_challenge('unlock');

auth_start('Déverrouiller');
?>
<section class="card auth-card faceid-card"
  data-faceid-unlock
  data-challenge="<?= e($challenge) ?>"
  data-cred="<?= e((string) $cred['cred_id']) ?>"
  data-rp="<?= e(faceid_rp_id()) ?>"
  data-csrf="<?= e(csrf_token()) ?>">
  <div class="faceid-ic" aria-hidden="true">
    <svg viewBox="0 0 48 48" class="ic"><path d="M6 16V10a4 4 0 014-4h6M32 6h6a4 4 0 014 4v6M42 32v6a4 4 0 01-4 4h-6M16 42h-6a4 4 0 01-4-4v-6"/><path d="M17 18v3M31 18v3M24 19v8h-2M18 32c3.5 3 8.5 3 12 0"/></svg>
  </div>
  <header class="card-head">
    <h1>Bonjour, <?= e($user['username']) ?></h1>
    <p class="muted">L'application est verrouillée. Utilise Face ID pour continuer.</p>
  </header>
  <div class="alert alert-error" role="alert" data-faceid-error hidden></div>
  <button class="btn btn-primary btn-block" type="button" data-faceid-go>Déverrouiller avec Face ID</button>
  <p class="auth-alt"><a href="/login.php?motdepasse=1">Utiliser mon mot de passe</a></p>
</section>
<?php auth_end();
