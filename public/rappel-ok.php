<?php
declare(strict_types=1);
// Boutons des notifications de rappel : « C'est fait » et « Plus tard ». Appelé par l'application
// ntfy (POST avec a=done|s15|s60|matin) ou ouvert en touchant la notification (GET : page avec les
// boutons). Pas de connexion requise : le lien contient un jeton secret propre à ce rappel.
require dirname(__DIR__) . '/src/bootstrap.php';

stop_if_banned();
start_session();

$id   = (int) ($_REQUEST['id'] ?? 0);
$key  = (string) ($_REQUEST['k'] ?? '');
$act  = (string) ($_REQUEST['a'] ?? '');
$post = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
$st   = db()->prepare(RAPPEL_SELECT . ' WHERE r.id = ?');
$st->execute([$id]);
$r    = $st->fetch() ?: null;
$done = null;
// Qui appelle le lien (application ntfy iPhone/Android, navigateur…) : noté au journal.
$ua  = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
$via = 'depuis la notification · ' . (preg_match('/ntfy[^ ]*/i', $ua, $m) ? $m[0] : (preg_match('/iPhone|Android|Windows|Mac/', $ua, $m) ? 'navigateur ' . $m[0] : 'appareil inconnu'));

if (!$r || !preg_match('/^[a-f0-9]{40}$/', $key) || !hash_equals(rappel_token($r), $key)) {
    login_failed('ack', client_ip(), null, 'Lien de rappel invalide');
    $state = 'bad';
} elseif ($post && $act === 'done') {
    if ($r['pending_since'] !== null) {
        rappel_ack($r, null, $via . (isset($_POST['page']) ? ', page de validation' : ', bouton'));
    }
    $state = 'ok';
    $done  = "« {$r['title']} » est validé : plus de relance.";
} elseif ($post && ($until = rappel_report_until($act, time())) !== null) {
    rappel_snooze($r, $until, null, $via);
    $state = 'ok';
    $done  = "« {$r['title']} » revient " . rappel_when($until) . '.';
} else {
    $state = 'ask';
}

// Réponse courte pour l'application ntfy.
if ($post && !isset($_POST['page'])) {
    http_response_code($state === 'bad' ? 403 : 200);
    header('Content-Type: text/plain; charset=utf-8');
    exit($state === 'bad' ? 'Lien invalide.' : (string) $done);
}

$hidden = '<input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="k" value="' . e($key) . '"><input type="hidden" name="page" value="1">';
auth_start('Rappel');
?>
<div class="card">
  <div class="card-head">
    <?php if ($state === 'ask'): ?>
      <h1><?= e($r['title']) ?></h1>
      <p class="muted"><?= $r['pending_since'] !== null
          ? 'Prévu ' . e(rappel_when((int) $r['pending_since'])) . ((int) $r['nag_count'] ? ' · relancé ' . (int) $r['nag_count'] . ' fois' : '') . '.'
          : e(rappel_summary($r)) . '.' ?></p>
    <?php elseif ($state === 'ok'): ?>
      <h1>✅ C'est noté</h1>
      <p class="muted"><?= e($done) ?></p>
    <?php else: ?>
      <h1>Lien invalide</h1>
      <p class="muted">Ce lien ne correspond à aucun rappel. Ouvre la page Rappels du portail.</p>
    <?php endif; ?>
  </div>
  <?php if ($state === 'ask'): ?>
    <?php if ($r['pending_since'] !== null): ?>
    <form method="post" class="form"><?= $hidden ?><input type="hidden" name="a" value="done">
      <button class="btn btn-primary btn-block" type="submit">✅ C'est fait</button>
    </form>
    <?php endif; ?>
    <p class="muted small rp-later">Plus tard :</p>
    <div class="rp-later-btns">
      <?php foreach (RAPPEL_REPORTS as $code => $label): ?>
      <form method="post"><?= $hidden ?><input type="hidden" name="a" value="<?= e($code) ?>">
        <button class="btn btn-ghost" type="submit">⏰ <?= e($label) ?></button>
      </form>
      <?php endforeach; ?>
    </div>
  <?php else: ?>
  <a class="btn btn-ghost btn-block" href="/rappels.php">Ouvrir mes rappels</a>
  <?php endif; ?>
</div>
<?php auth_end();
