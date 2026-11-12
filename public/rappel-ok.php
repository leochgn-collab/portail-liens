<?php
declare(strict_types=1);
// Bouton « C'est fait » des rappels insistants. Appelé par l'application ntfy (POST) ou ouvert
// dans le navigateur (GET : page avec un bouton). Pas de connexion requise : le lien contient
// un jeton secret, valable seulement pour l'occurrence en attente de ce rappel.
require dirname(__DIR__) . '/src/bootstrap.php';

stop_if_banned();
start_session();

$id  = (int) ($_REQUEST['id'] ?? 0);
$key = (string) ($_REQUEST['k'] ?? '');
$st  = db()->prepare('SELECT * FROM reminders WHERE id = ?');
$st->execute([$id]);
$r   = $st->fetch() ?: null;
$post = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';

if (!$r || $r['pending_since'] === null) {
    $state = 'none';
} elseif (!preg_match('/^[a-f0-9]{40}$/', $key) || !hash_equals(rappel_ack_token($r), $key)) {
    login_failed('ack', client_ip(), null, 'Lien de validation de rappel invalide');
    $state = 'bad';
} elseif ($post) {
    rappel_ack($r, null, 'depuis la notification');
    $state = 'ok';
} else {
    $state = 'ask';
}

// Réponse courte pour l'application ntfy.
if ($post && !isset($_POST['page'])) {
    http_response_code($state === 'bad' ? 403 : 200);
    header('Content-Type: text/plain; charset=utf-8');
    exit(['ok' => 'Rappel validé.', 'none' => 'Rien à valider.', 'bad' => 'Lien invalide.'][$state] ?? '');
}

auth_start('Rappel');
?>
<div class="card">
  <div class="card-head">
    <?php if ($state === 'ask'): ?>
      <h1><?= e($r['title']) ?></h1>
      <p class="muted">Prévu <?= e(rappel_when((int) $r['pending_since'])) ?><?= (int) $r['nag_count'] ? ' · relancé ' . (int) $r['nag_count'] . ' fois' : '' ?>.</p>
    <?php elseif ($state === 'ok'): ?>
      <h1>✅ C'est noté</h1>
      <p class="muted">« <?= e($r['title']) ?> » est validé : plus de relance.</p>
    <?php elseif ($state === 'none'): ?>
      <h1>Rien à valider</h1>
      <p class="muted">Ce rappel est déjà validé.</p>
    <?php else: ?>
      <h1>Lien invalide</h1>
      <p class="muted">Ce lien ne correspond plus au rappel en attente. Utilise la dernière notification, ou valide depuis la page Rappels.</p>
    <?php endif; ?>
  </div>
  <?php if ($state === 'ask'): ?>
  <form method="post" class="form">
    <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
    <input type="hidden" name="k" value="<?= e($key) ?>">
    <input type="hidden" name="page" value="1">
    <button class="btn btn-primary btn-block" type="submit">✅ C'est fait</button>
  </form>
  <?php else: ?>
  <a class="btn btn-ghost btn-block" href="/rappels.php">Ouvrir mes rappels</a>
  <?php endif; ?>
</div>
<?php auth_end();
