<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';

$me = require_admin();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    require_post_csrf();
    $action = (string) ($_POST['action'] ?? '');
    $vmid   = (int) ($_POST['vmid'] ?? 0);
    $err    = null;
    $ok     = null;
    if ($vmid < 100) {
        $err = 'Identifiant de CT invalide.';
    } elseif (in_array($action, ['start', 'shutdown'], true)) {
        $err = pve_action($vmid, $action);
        $ok  = "CT $vmid : " . ($action === 'start' ? 'démarrage' : 'arrêt') . ' demandé.';
        if ($err === null) {
            log_event((int) $me['id'], "CT $vmid : $action");
        }
    } elseif ($action === 'schedule_save') {
        $off = (string) ($_POST['off_time'] ?? '');
        $on  = (string) ($_POST['on_time'] ?? '');
        if (in_array($vmid, PVE_PROTECTED, true)) {
            $err = "Le CT $vmid est protégé : pas de planification possible.";
        } elseif (!ct_valid_time($off) || !ct_valid_time($on) || $off === $on) {
            $err = 'Heures invalides (format HH:MM, extinction différente de l\'allumage).';
        } else {
            db()->prepare('INSERT INTO ct_schedule (vmid, off_time, on_time, enabled, last_state) VALUES (?, ?, ?, ?, NULL)
                ON CONFLICT(vmid) DO UPDATE SET off_time = excluded.off_time, on_time = excluded.on_time,
                enabled = excluded.enabled, last_state = NULL')
                ->execute([$vmid, $off, $on, isset($_POST['enabled']) ? 1 : 0]);
            log_event((int) $me['id'], "CT $vmid : plage $off → $on");
            $ok = "Plage enregistrée pour le CT $vmid.";
        }
    } elseif ($action === 'schedule_delete') {
        db()->prepare('DELETE FROM ct_schedule WHERE vmid = ?')->execute([$vmid]);
        log_event((int) $me['id'], "CT $vmid : plage supprimée");
        $ok = "Plage supprimée pour le CT $vmid.";
    }
    flash($err ?? $ok);
    redirect('/conteneurs.php');
}

$list  = pve_list_cts();
if ($list['error'] === null) {
    ct_detect_changes($list['cts']); // la page voit aussi tout de suite un CT créé
}
$recent = ct_recent();
$sched = [];
foreach (db()->query('SELECT * FROM ct_schedule')->fetchAll() as $s) {
    $sched[(int) $s['vmid']] = $s;
}
$nRun  = count(array_filter($list['cts'], fn ($c) => ($c['status'] ?? '') === 'running'));
$nPlan = count(array_filter($sched, fn ($s) => (int) $s['enabled'] === 1));

page_start('Conteneurs', $me, 'conteneurs');
?>
<header class="topbar">
  <div>
    <p class="eyebrow">Proxmox · nœud <?= e(PVE_NODE) ?></p>
    <h1>Conteneurs</h1>
  </div>
</header>

<?php if (!pve_configured()): ?>
  <div class="alert alert-error" role="alert">Proxmox n'est pas configuré. Ajoute PVE_URL et PVE_TOKEN dans config.local.php (voir README).</div>
<?php elseif ($list['error']): ?>
  <div class="alert alert-error" role="alert"><?= e($list['error']) ?></div>
<?php endif; ?>

<?php if ($list['cts']): ?>
<section class="stats stats-3">
  <div class="stat">
    <span class="stat-ic"><?= icon('server') ?></span>
    <div><small>Conteneurs</small><strong><?= count($list['cts']) ?></strong></div>
  </div>
  <div class="stat">
    <span class="stat-ic"><?= icon('power') ?></span>
    <div><small>En marche</small><strong><?= $nRun ?> / <?= count($list['cts']) ?></strong></div>
  </div>
  <div class="stat">
    <span class="stat-ic"><?= icon('clock') ?></span>
    <div><small>Plages actives</small><strong><?= $nPlan ?></strong></div>
  </div>
</section>

<p class="muted legend">Sur chaque frise : <span class="lg lg-on"></span> allumé · <span class="lg lg-off"></span> éteint · <span class="lg lg-now"></span> maintenant</p>

<div class="ct-list">
<?php foreach ($list['cts'] as $ct):
    $id   = (int) $ct['vmid'];
    $name = (string) ($ct['name'] ?? "CT $id");
    $run  = ($ct['status'] ?? '') === 'running';
    $prot = in_array($id, PVE_PROTECTED, true);
    $s    = $sched[$id] ?? null;
    $warn = in_array($id, PVE_CONFIRM, true)
        ? "Arrêter « $name » peut couper l'accès à tous tes sites. Continuer ?"
        : "Arrêter le CT $id « $name » ?";
    ?>
  <article class="ct<?= $run ? ' ct-run' : ' ct-stop' ?><?= isset($recent[$id]) ? ' ct-new' : '' ?>">
    <div class="ct-head">
      <span class="ct-ic"><?= icon('server') ?></span>
      <div class="ct-title">
        <strong><?= e($name) ?><?= $prot ? ' <span class="badge badge-priv">Protégé</span>' : '' ?><?= isset($recent[$id]) ? ' <span class="badge badge-new">Nouveau</span>' : '' ?></strong>
        <span class="host mono">CT <?= $id ?></span>
      </div>
      <span class="ct-state"><span class="dot <?= $run ? 'up' : 'down' ?>"></span><?= $run ? 'En marche' : 'Arrêté' ?></span>
    </div>

    <div class="ct-actions">
      <?php if (!$run): ?>
      <form method="post">
        <?= csrf_field() ?><input type="hidden" name="action" value="start"><input type="hidden" name="vmid" value="<?= $id ?>">
        <button class="btn btn-primary btn-sm" type="submit"><?= icon('power') ?> Démarrer</button>
      </form>
      <?php elseif (!$prot): ?>
      <form method="post" data-confirm="<?= e($warn) ?>">
        <?= csrf_field() ?><input type="hidden" name="action" value="shutdown"><input type="hidden" name="vmid" value="<?= $id ?>">
        <button class="btn btn-danger btn-sm" type="submit"><?= icon('power') ?> Arrêter</button>
      </form>
      <?php else: ?>
      <span class="muted small">Le portail tourne dans ce CT : il ne peut pas l'arrêter.</span>
      <?php endif; ?>
    </div>

    <?php if (!$prot): ?>
    <div class="ct-plan">
      <?php if ($s): ?>
        <p class="ct-sum<?= $s['enabled'] ? '' : ' muted' ?>">
          <?= $s['enabled'] ? '' : '⏸ Désactivée · ' ?>Éteint de <strong><?= e($s['off_time']) ?></strong> à <strong><?= e($s['on_time']) ?></strong>, allumé le reste du temps.
        </p>
        <?= ct_timeline($s['off_time'], $s['on_time'], (bool) $s['enabled']) ?>
      <?php else: ?>
        <p class="ct-sum muted">Aucune plage : ce CT reste allumé tant que tu ne l'arrêtes pas.</p>
      <?php endif; ?>
      <details class="ct-edit">
        <summary><?= $s ? 'Modifier la plage' : 'Ajouter une plage horaire' ?></summary>
        <form method="post" class="ct-form">
          <?= csrf_field() ?><input type="hidden" name="action" value="schedule_save"><input type="hidden" name="vmid" value="<?= $id ?>">
          <label class="field"><span>🌙 Éteindre à</span><input type="time" name="off_time" value="<?= e($s['off_time'] ?? '') ?>" required></label>
          <label class="field"><span>☀️ Rallumer à</span><input type="time" name="on_time" value="<?= e($s['on_time'] ?? '') ?>" required></label>
          <label class="check"><input type="checkbox" name="enabled" value="1" <?= !$s || $s['enabled'] ? 'checked' : '' ?>> Active</label>
          <button class="btn btn-ghost btn-sm" type="submit">Enregistrer</button>
        </form>
        <?php if ($s): ?>
        <form method="post" data-confirm="Supprimer la plage du CT <?= $id ?> ?">
          <?= csrf_field() ?><input type="hidden" name="action" value="schedule_delete"><input type="hidden" name="vmid" value="<?= $id ?>">
          <button class="btn btn-danger btn-sm" type="submit">Retirer la plage</button>
        </form>
        <?php endif; ?>
      </details>
    </div>
    <?php endif; ?>
  </article>
<?php endforeach; ?>
</div>
<p class="muted small">Une plage peut passer minuit (ex. éteint de 23:00 à 07:00). Le planificateur n'agit qu'aux heures de changement : un démarrage manuel dans la plage reste en place jusqu'à la prochaine extinction. L'arrêt est propre ; Proxmox ne force l'arrêt que si le CT ne s'est pas éteint au bout de <?= (int) PVE_SHUTDOWN_TIMEOUT ?> secondes.</p>
<?php endif; ?>
<?php page_end();
