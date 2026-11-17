<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/reveil.php';

$me      = require_admin();
$devices = wol_devices();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    require_post_csrf();
    $key = (string) ($_POST['device'] ?? '');
    $msg = null;
    if (!isset($devices[$key])) {
        $msg = 'Appareil inconnu.';
    } else {
        $d    = $devices[$key];
        $last = (int) ($_SESSION['wol_last'][$key] ?? 0);
        if (time() - $last < WOL_COOLDOWN) {
            $msg = "Signal déjà envoyé à « {$d['name']} » il y a moins d'une minute : patiente un peu.";
        } elseif (wol_online($d) === true) {
            $msg = "« {$d['name']} » est déjà allumé.";
        } else {
            $err = wol_send($d['mac']);
            if ($err === null) {
                $_SESSION['wol_last'][$key] = time();
                log_event((int) $me['id'], "Réveil réseau : {$d['name']}");
                $msg = "Signal envoyé à « {$d['name']} ». Le démarrage prend en général 2 à 3 minutes.";
            } else {
                $msg = $err;
            }
        }
    }
    flash($msg);
    redirect('/reveil.php');
}

$etats = [];
foreach ($devices as $key => $d) {
    $etats[$key] = wol_online($d);
}
$nOn = count(array_filter($etats, fn ($s) => $s === true));

page_start('Réveil réseau', $me, 'reveil');
?>
<header class="topbar">
  <div>
    <p class="eyebrow">Wake-on-LAN</p>
    <h1>Réveil réseau</h1>
  </div>
</header>

<?php if (!$devices): ?>
  <div class="alert alert-warn" role="alert">Aucun appareil configuré. Ajoute WOL_DEVICES dans config.local.php (voir README).</div>
<?php elseif (!function_exists('socket_create')): ?>
  <div class="alert alert-error" role="alert">L'extension PHP « sockets » est absente : le signal ne peut pas être envoyé (voir README).</div>
<?php endif; ?>

<?php if ($devices): ?>
<section class="stats stats-3">
  <div class="stat">
    <span class="stat-ic"><?= icon('server') ?></span>
    <div><small>Appareils</small><strong><?= count($devices) ?></strong></div>
  </div>
  <div class="stat">
    <span class="stat-ic"><?= icon('power') ?></span>
    <div><small>Allumés</small><strong><?= $nOn ?> / <?= count($devices) ?></strong></div>
  </div>
  <div class="stat">
    <span class="stat-ic"><?= icon('clock') ?></span>
    <div><small>Vérifié à</small><strong><?= date('H:i') ?></strong></div>
  </div>
</section>

<div class="ct-list">
<?php foreach ($devices as $key => $d):
    $etat = $etats[$key];
    $on   = $etat === true;
    ?>
  <article class="ct<?= $on ? ' ct-run' : ' ct-stop' ?>">
    <div class="ct-head">
      <span class="ct-ic"><?= icon('server') ?></span>
      <div class="ct-title">
        <strong><?= e($d['name']) ?></strong>
        <span class="host mono"><?= e($d['ip'] !== '' ? $d['ip'] : $d['mac']) ?></span>
      </div>
      <span class="ct-state">
        <?php if ($etat === null): ?>
          <span class="dot"></span>État inconnu
        <?php else: ?>
          <span class="dot <?= $on ? 'up' : 'down' ?>"></span><?= $on ? 'Allumé' : 'Éteint' ?>
        <?php endif; ?>
      </span>
    </div>
    <div class="ct-actions">
      <?php if (!$on): ?>
      <form method="post">
        <?= csrf_field() ?><input type="hidden" name="device" value="<?= e($key) ?>">
        <button class="btn btn-primary btn-sm" type="submit"><?= icon('power') ?> Allumer</button>
      </form>
      <?php else: ?>
      <span class="muted small">Déjà allumé.</span>
      <?php endif; ?>
      <a class="btn btn-ghost btn-sm" href="/reveil.php">Actualiser</a>
    </div>
  </article>
<?php endforeach; ?>
</div>
<p class="muted small">Le signal ne part que sur le réseau local du portail : l'appareil doit avoir le Wake-on-LAN activé et être branché en câble. Un même appareil ne peut être réveillé qu'une fois par minute.</p>
<?php endif; ?>
<?php page_end();
