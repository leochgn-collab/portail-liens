<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/sauvegardes.php';

$me = require_admin();

$errors = [];
$jobs = $tasks = [];
$running = null;      // tâche en cours
$run     = null;      // son analyse
$estimate = null;     // secondes restantes estimées
$copies = [];
$stores = [];

if (pve_configured()) {
    $j = bk_jobs();
    $jobs = $j['jobs'];
    if ($j['error']) {
        $errors[] = $j['error'];
    }
    $t = bk_tasks(15);
    $tasks = $t['tasks'];
    if ($t['error'] && !in_array($t['error'], $errors, true)) {
        $errors[] = $t['error'];
    }

    foreach ($tasks as $task) {
        if (empty($task['endtime'])) {
            $running = $task;
            break;
        }
    }
    if ($running) {
        $run = bk_parse(bk_log((string) $running['upid']));
        // Estimation : durée de chaque CT lors de la dernière sauvegarde réussie qui l'incluait ;
        // pour un CT jamais sauvegardé, moyenne des durées connues.
        $durees = [];
        $lus = 0;
        foreach ($tasks as $task) {
            if (!empty($task['endtime']) && ($task['status'] ?? '') === 'OK' && $lus++ < 5) {
                foreach (bk_parse(bk_log((string) $task['upid']))['items'] as $id => $it) {
                    if ($it['dur'] !== null && !isset($durees[$id])) {
                        $durees[$id] = $it['dur'];
                    }
                }
                if (!array_diff($run['vmids'], array_keys($durees))) {
                    break; // toutes les durées trouvées
                }
            }
        }
        foreach ($run['items'] as $id => $it) { // CT déjà finis aujourd'hui : durée du jour
            if ($it['dur'] !== null) {
                $durees[$id] = $it['dur'];
            }
        }
        if ($durees) {
            $moy = (int) round(array_sum($durees) / count($durees));
            $left = 0;
            foreach ($run['vmids'] as $id) {
                $st = $run['items'][$id]['state'] ?? 'wait';
                if ($st === 'ok' || $st === 'err') {
                    continue;
                }
                $pd = $durees[$id] ?? $moy;
                if ($st === 'run') {
                    $started = $run['items'][$id]['start'] ?? null;
                    $left += max(0, $pd - ($started ? time() - $started : 0));
                } else {
                    $left += $pd;
                }
            }
            $estimate = $left;
        }
    }

    // Stockages utilisés par les tâches planifiées.
    foreach ($jobs as $job) {
        $s = (string) ($job['storage'] ?? '');
        if ($s !== '' && !isset($stores[$s])) {
            $stores[$s] = bk_storage($s);
            $c = bk_copies($s);
            $copies[$s] = $c['byVm'];
        }
    }
}

// Noms des CT/VM pour l'affichage.
$names = [];
if (pve_configured()) {
    $r = pve_request('GET', '/cluster/resources?type=vm');
    foreach ($r['ok'] && is_array($r['data']) ? $r['data'] : [] as $v) {
        $names[(int) $v['vmid']] = (string) ($v['name'] ?? '');
    }
}
$nom = fn (int $id): string => ($names[$id] ?? '') !== '' ? $names[$id] : "CT $id";

// Prochaine exécution, toutes tâches confondues.
$next = null;
foreach ($jobs as $job) {
    if (!empty($job['enabled']) && !empty($job['next-run'])) {
        $next = $next === null ? (int) $job['next-run'] : min($next, (int) $job['next-run']);
    }
}
$last = null;
foreach ($tasks as $task) {
    if (!empty($task['endtime'])) {
        $last = $task;
        break;
    }
}

page_start('Sauvegardes', $me, 'sauvegardes');
?>
<header class="topbar">
  <div>
    <p class="eyebrow">Proxmox · nœud <?= e(PVE_NODE) ?></p>
    <h1>Sauvegardes</h1>
  </div>
  <a class="btn btn-ghost btn-sm" href="/sauvegardes.php">Actualiser</a>
</header>

<?php if (!pve_configured()): ?>
  <div class="alert alert-error" role="alert">Proxmox n'est pas configuré. Ajoute PVE_URL et PVE_TOKEN dans config.local.php (voir README).</div>
<?php endif; ?>
<?php foreach ($errors as $err): ?>
  <div class="alert alert-error" role="alert"><?= e($err) ?><?= str_contains($err, 'droits') ? ' Donne au jeton le rôle de lecture « PortailAudit » (voir README, page « Sauvegardes »).' : '' ?></div>
<?php endforeach; ?>

<?php if (pve_configured() && !$errors): ?>
<section class="stats">
  <div class="stat">
    <span class="stat-ic"><?= icon('clock') ?></span>
    <div><small>Prochaine sauvegarde</small>
      <strong class="stat-sm"><?= $next ? e(date('d/m à H:i', $next)) . '<br><span class="muted">' . e(fmt_relatif($next)) . '</span>' : '—' ?></strong></div>
  </div>
  <div class="stat">
    <span class="stat-ic"><?= icon('pulse') ?></span>
    <div><small>Dernière sauvegarde</small>
      <?php if ($last): [$lbl, $cls] = bk_status_label($last); ?>
        <strong class="stat-sm"><span class="dot <?= $cls === 'ok' ? 'up' : 'down' ?>"></span> <?= e($lbl) ?><br><span class="muted"><?= e(date('d/m H:i', (int) $last['starttime'])) ?> · <?= e(fmt_duree((int) $last['endtime'] - (int) $last['starttime'])) ?></span></strong>
      <?php else: ?><strong class="stat-sm">Aucune</strong><?php endif; ?>
    </div>
  </div>
  <?php foreach ($stores as $sid => $st):
      $actif = !empty($st['active']);
      $total = (int) ($st['total'] ?? 0);
      $used  = (int) ($st['used'] ?? 0);
      ?>
  <div class="stat">
    <span class="stat-ic"><?= icon('server') ?></span>
    <div><small>Stockage <?= e($sid) ?></small>
      <strong class="stat-sm"><span class="dot <?= $actif ? 'up' : 'down' ?>"></span> <?= $actif ? 'Joignable' : 'Éteint / injoignable' ?>
        <?php if ($actif && $total > 0): ?><br><span class="muted"><?= e(fmt_taille($total - $used)) ?> libres sur <?= e(fmt_taille($total)) ?></span><?php endif; ?></strong></div>
  </div>
  <?php endforeach; ?>
  <div class="stat">
    <span class="stat-ic"><?= icon('folder') ?></span>
    <div><small>Copies stockées</small>
      <?php $nb = 0; $sz = 0; foreach ($copies as $by) { foreach ($by as $l) { $nb += count($l); foreach ($l as $f) { $sz += $f['size']; } } } ?>
      <strong class="stat-sm"><?= $nb || !array_filter($stores, fn ($s) => empty($s['active'])) ? $nb . ' copie' . ($nb > 1 ? 's' : '') : '—' ?><br><span class="muted"><?= $nb ? e(fmt_taille($sz)) : (array_filter($stores, fn ($s) => empty($s['active'])) ? 'NAS éteint : liste indisponible' : '—') ?></span></strong></div>
  </div>
</section>

<?php if ($running && $run):
    $total = max(1, count($run['vmids']));
    $fini  = $run['done'] + $run['failed'];
    $cour  = $run['current'];
    $ratio = $fini / $total;
    if ($cour !== null) {
        $ratio += 0.5 / $total; // CT en cours compté à moitié
    }
    $ecoule = time() - (int) $running['starttime'];
    ?>
<section class="panel panel-accent" data-autorefresh="15">
  <h2><?= icon('pulse') ?> Sauvegarde en cours <span class="count"><?= $fini ?> / <?= $total ?> terminés</span></h2>
  <?= bk_bar($ratio) ?>
  <p class="bk-times">
    <span>Démarrée à <strong><?= e(date('H:i', (int) $running['starttime'])) ?></strong></span>
    <span>Écoulé : <strong><?= e(fmt_duree($ecoule)) ?></strong></span>
    <span>Reste environ : <strong><?= $estimate !== null ? e(fmt_duree($estimate)) : 'inconnu (1re sauvegarde)' ?></strong></span>
    <?php if ($estimate !== null): ?><span>Fin prévue vers <strong><?= e(date('H:i', time() + $estimate)) ?></strong></span><?php endif; ?>
  </p>
  <ul class="bk-steps">
  <?php foreach ($run['vmids'] as $id):
      $it = $run['items'][$id] ?? null;
      $state = $it['state'] ?? 'wait';
      $label = ['ok' => 'Terminé', 'err' => 'Échec', 'run' => 'En cours…', 'wait' => 'En attente'][$state];
      $detail = '';
      if ($state === 'ok') {
          $detail = fmt_duree($it['dur']) . ($it['size'] !== '' ? ' · ' . $it['size'] : '');
      } elseif ($state === 'run' && !empty($it['start'])) {
          $detail = 'depuis ' . fmt_duree(time() - (int) $it['start']);
      } elseif ($state === 'err') {
          $detail = $it['error'];
      }
      ?>
    <li class="bk-step bk-<?= $state ?>">
      <span class="bk-ic" aria-hidden="true"><?= ['ok' => '✓', 'err' => '✕', 'run' => '●', 'wait' => '○'][$state] ?></span>
      <span class="bk-name"><strong><?= e($it['name'] ?? '' ?: $nom($id)) ?></strong> <span class="muted mono">CT <?= $id ?></span></span>
      <span class="bk-state"><?= e($label) ?><?php if ($detail !== ''): ?> <span class="muted">· <?= e($detail) ?></span><?php endif; ?></span>
    </li>
  <?php endforeach; ?>
  </ul>
  <p class="muted small">La page se met à jour toute seule toutes les 15 secondes.</p>
</section>
<?php endif; ?>

<?php foreach ($jobs as $job):
    $ids = array_filter(array_map('intval', explode(',', (string) ($job['vmid'] ?? ''))));
    ?>
<section class="panel">
  <h2><?= icon('clock') ?> Tâche planifiée <span class="count"><?= !empty($job['enabled']) ? 'active' : 'désactivée' ?></span></h2>
  <p class="ct-sum">
    Tous les jours à <strong><?= e((string) ($job['schedule'] ?? '?')) ?></strong> vers <strong><?= e((string) ($job['storage'] ?? '?')) ?></strong>
    · mode <?= e((string) ($job['mode'] ?? 'snapshot')) ?>
    <?php $keep = $job['prune-backups']['keep-last'] ?? null; if ($keep): ?>· garde les <?= (int) $keep ?> dernières copies<?php endif; ?>
    <?php if (!empty($job['comment'])): ?><br><span class="muted"><?= e((string) $job['comment']) ?></span><?php endif; ?>
  </p>
  <p class="muted small"><?= !empty($job['all']) ? 'Toutes les machines' : e(implode(' · ', array_map(fn ($i) => $nom($i) . " ($i)", $ids))) ?></p>
</section>
<?php endforeach; ?>
<?php if (!$jobs): ?>
  <div class="alert alert-warn" role="alert">Aucune tâche de sauvegarde planifiée dans Proxmox.</div>
<?php endif; ?>

<?php foreach ($copies as $sid => $by): if (!$by) { continue; } ?>
<section class="panel">
  <h2><?= icon('folder') ?> Copies sur <?= e($sid) ?></h2>
  <table class="table">
    <thead><tr><th>Machine</th><th class="num">Copies</th><th>Dernière copie</th><th class="num">Taille</th><th class="num">Total</th></tr></thead>
    <tbody>
    <?php foreach ($by as $id => $list): $tot = array_sum(array_column($list, 'size')); ?>
      <tr>
        <td><strong><?= e($nom($id)) ?></strong> <span class="muted mono">CT <?= $id ?></span></td>
        <td class="num"><?= count($list) ?></td>
        <td class="nowrap"><?= e(date('d/m/Y H:i', $list[0]['ctime'])) ?> <span class="muted">(<?= e(fmt_relatif($list[0]['ctime'])) ?>)</span></td>
        <td class="num"><?= e(fmt_taille($list[0]['size'])) ?></td>
        <td class="num"><?= e(fmt_taille($tot)) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</section>
<?php endforeach; ?>

<section class="panel">
  <h2><?= icon('note') ?> Historique <span class="count"><?= count($tasks) ?> dernières</span></h2>
  <?php if (!$tasks): ?>
    <p class="muted">Aucune sauvegarde n'a encore été lancée.</p>
  <?php else: ?>
  <table class="table">
    <thead><tr><th>Date</th><th>Durée</th><th>Statut</th><th>Lancée par</th></tr></thead>
    <tbody>
    <?php foreach ($tasks as $task): [$lbl, $cls] = bk_status_label($task); ?>
      <tr>
        <td class="nowrap"><?= e(date('d/m/Y H:i', (int) $task['starttime'])) ?></td>
        <td class="nowrap"><?= e(empty($task['endtime']) ? fmt_duree(time() - (int) $task['starttime']) . ' (en cours)' : fmt_duree((int) $task['endtime'] - (int) $task['starttime'])) ?></td>
        <td><span class="dot <?= $cls === 'ok' ? 'up' : ($cls === 'run' ? '' : 'down') ?>"></span> <?= e($lbl) ?></td>
        <td class="muted"><?= e(str_contains((string) ($task['user'] ?? ''), '!') ? 'Portail' : ((string) ($task['user'] ?? '') === 'root@pam' ? 'Planifiée / root' : (string) ($task['user'] ?? ''))) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</section>
<?php endif; ?>
<?php page_end();
