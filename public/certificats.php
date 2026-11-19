<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/certificats.php';

$me = require_admin();
cert_migrate();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    require_post_csrf();
    $action = (string) ($_POST['action'] ?? '');
    $msg = null;

    if ($action === 'add' || $action === 'import') {
        $hosts = $action === 'import'
            ? cert_suggestions()
            : [strtolower(trim((string) ($_POST['host'] ?? '')))];
        $port = $action === 'add' && trim((string) ($_POST['port'] ?? '')) !== '' ? (int) $_POST['port'] : 443;
        $added = [];
        foreach ($hosts as $h) {
            $h = preg_replace('#^https?://#', '', rtrim($h, '/')) ?? '';
            if (!cert_valid_host($h)) {
                $msg = 'Nom de domaine invalide (exemple : nc.leo44.cloud).';
                continue;
            }
            if ($port < 1 || $port > 65535) {
                $msg = 'Port invalide.';
                continue;
            }
            if ((int) db()->query('SELECT COUNT(*) FROM cert_hosts')->fetchColumn() >= 100) {
                $msg = 'Maximum 100 domaines.';
                break;
            }
            $ins = db()->prepare('INSERT OR IGNORE INTO cert_hosts (host, port, created_at) VALUES (?, ?, ?)');
            $ins->execute([$h, $port, time()]);
            if ($ins->rowCount()) {
                $added[] = $h;
                $st = db()->prepare('SELECT * FROM cert_hosts WHERE host = ?');
                $st->execute([$h]);
                cert_update($st->fetch()); // premier contrôle tout de suite
            }
        }
        if ($added) {
            log_event((int) $me['id'], 'Certificats surveillés : ' . implode(', ', $added));
            $msg = count($added) === 1 ? "« {$added[0]} » est maintenant surveillé." : count($added) . ' domaines ajoutés.';
        }
        $msg ??= 'Rien à ajouter : ce domaine est déjà surveillé.';
    } elseif ($action === 'mail') {
        $m = trim((string) ($_POST['cert_mail'] ?? ''));
        if ($m !== '' && !valid_email($m)) {
            $msg = 'Adresse e-mail invalide.';
        } else {
            set_setting('cert_mail', $m);
            log_event((int) $me['id'], $m === '' ? 'Alertes de certificats : e-mail retiré' : 'Alertes de certificats envoyées par e-mail');
            $msg = $m === '' ? 'Plus d\'e-mail : les alertes partiront sur ntfy.' : "Les alertes partiront à $m.";
            if ($m !== '' && isset($_POST['test'])) {
                $err = mail_configured()
                    ? send_mail($m, '[' . APP_NAME . '] Test des alertes de certificats', "Bonjour,\n\nCet e-mail confirme que les alertes de certificats HTTPS du portail arriveront bien ici.\n")
                    : 'envoi de mail non configuré (réglages SMTP_… dans config.local.php)';
                $msg .= $err === null ? ' E-mail de test envoyé : regarde ta boîte (et les indésirables).' : " Mais l'e-mail de test n'est pas parti : $err";
            }
        }
    } elseif ($action === 'check') {
        // Limite : un contrôle complet toutes les 30 secondes au plus.
        if (time() - (int) setting('cert_manual_at', '0') < 30) {
            $msg = 'Contrôle déjà lancé il y a quelques secondes, patiente un peu.';
        } else {
            set_setting('cert_manual_at', (string) time());
            cert_run_all();
            $msg = 'Tous les certificats ont été vérifiés.';
        }
    } elseif ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        $st = db()->prepare('SELECT host FROM cert_hosts WHERE id = ?');
        $st->execute([$id]);
        if ($h = $st->fetchColumn()) {
            db()->prepare('DELETE FROM cert_hosts WHERE id = ?')->execute([$id]);
            log_event((int) $me['id'], "Certificat de $h n'est plus surveillé");
            $msg = "« $h » n'est plus surveillé.";
        }
    }
    flash($msg ?? 'Action inconnue.');
    redirect('/certificats.php');
}

$rows = db()->query('SELECT * FROM cert_hosts ORDER BY CASE status WHEN \'expired\' THEN 0 WHEN \'invalid\' THEN 1 WHEN \'error\' THEN 2 WHEN \'warn\' THEN 3 ELSE 4 END, expires_at, host')->fetchAll();
$sugg = cert_suggestions();
$nOk   = count(array_filter($rows, fn ($r) => $r['status'] === 'ok'));
$nWarn = count(array_filter($rows, fn ($r) => $r['status'] === 'warn'));
$nBad  = count(array_filter($rows, fn ($r) => in_array($r['status'], ['expired', 'invalid', 'error'], true)));
$soon  = array_filter(array_column($rows, 'expires_at'));
$next  = $soon ? min($soon) : null;
$last  = array_filter(array_column($rows, 'checked_at'));

const CERT_ETATS = [
    'ok'      => ['Valide', 'up'],
    'warn'    => ['Expire bientôt', 'warn'],
    'expired' => ['Expiré', 'down'],
    'invalid' => ['Refusé', 'down'],
    'error'   => ['Injoignable', 'down'],
    'new'     => ['Pas encore vérifié', ''],
];

/** Jauge des jours restants (sur 90 jours, durée d'un certificat Let's Encrypt). */
function cert_jauge(?int $days): string
{
    if ($days === null) {
        return '';
    }
    $w = max(0, min(100, $days / 90 * 100));
    $cls = $days <= 3 ? 'cj-bad' : ($days <= CERT_WARN_DAYS ? 'cj-warn' : 'cj-ok');
    return '<svg class="cj ' . $cls . '" viewBox="0 0 100 6" preserveAspectRatio="none" aria-hidden="true">'
        . '<rect class="cj-bg" x="0" y="0" width="100" height="6" rx="3"/>'
        . '<rect class="cj-val" x="0" y="0" width="' . round($w, 1) . '" height="6" rx="3"/></svg>';
}

page_start('Certificats', $me, 'certificats');
?>
<header class="topbar">
  <div>
    <p class="eyebrow">HTTPS · vérifiés toutes les heures</p>
    <h1>Certificats</h1>
  </div>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="action" value="check">
    <button class="btn btn-ghost" type="submit"><?= icon('clock') ?> Vérifier maintenant</button>
  </form>
</header>

<section class="stats">
  <div class="stat"><span class="stat-ic"><?= icon('lock') ?></span>
    <div><small>Domaines</small><strong><?= count($rows) ?></strong></div></div>
  <div class="stat"><span class="stat-ic"><?= icon('shield') ?></span>
    <div><small>Valides</small><strong><?= $nOk ?></strong><span class="stat-sub"><?= $nWarn ?> bientôt · <?= $nBad ?> en erreur</span></div></div>
  <div class="stat"><span class="stat-ic"><?= icon('clock') ?></span>
    <div><small>Prochaine expiration</small><strong class="stat-sm"><?php $nd = $next ? (int) cert_days($next) : null; ?><?= $next ? e(date('d/m/Y', $next)) . '<br><span class="muted">' . ($nd < 0 ? 'expiré depuis ' . abs($nd) . ' j' : ($nd === 0 ? "aujourd'hui" : "dans $nd jours")) . '</span>' : '—' ?></strong></div></div>
  <div class="stat"><span class="stat-ic"><?= icon('pulse') ?></span>
    <div><small>Dernier contrôle</small><strong class="stat-sm"><?= $last ? e(date('d/m à H:i', max($last))) : '—' ?></strong></div></div>
</section>

<?php if ($sugg): ?>
<div class="alert">
  <?= count($sugg) ?> de tes sous-domaines (trouvés dans tes liens) ne sont pas encore surveillés : <strong><?= e(implode(', ', $sugg)) ?></strong>.
  <form method="post" class="cert-inline">
    <?= csrf_field() ?><input type="hidden" name="action" value="import">
    <button class="btn btn-primary btn-sm" type="submit">Tout ajouter</button>
  </form>
</div>
<?php endif; ?>

<section class="panel">
  <h2><?= icon('lock') ?> Domaines surveillés</h2>
  <?php if (!$rows): ?>
    <p class="muted">Aucun domaine pour l'instant. Ajoute-en un ci-dessous ou importe ceux de tes liens.</p>
  <?php else: ?>
  <div class="cert-list">
  <?php foreach ($rows as $r):
      [$lbl, $dot] = CERT_ETATS[$r['status']] ?? CERT_ETATS['new'];
      $days = cert_days($r['expires_at'] !== null ? (int) $r['expires_at'] : null);
      ?>
    <article class="cert cert-<?= e($r['status']) ?>">
      <div class="cert-head">
        <div class="cert-title">
          <strong><?= e($r['host']) ?><?= (int) $r['port'] !== 443 ? '<span class="muted">:' . (int) $r['port'] . '</span>' : '' ?></strong>
          <span class="host"><?= $r['issuer'] !== '' ? e($r['issuer']) : '—' ?></span>
        </div>
        <span class="ct-state cert-state" title="<?= $r['status'] === 'invalid' ? 'Les navigateurs refusent ce certificat' : '' ?>"><span class="dot <?= e($dot) ?>"></span><?= e($lbl) ?></span>
      </div>
      <?php if ($days !== null): ?>
        <div class="cert-days">
          <span><strong><?= $days < 0 ? 'expiré depuis ' . abs($days) . ' j' : $days . ' jour' . ($days > 1 ? 's' : '') ?></strong>
            <span class="muted"> · jusqu'au <?= e(date('d/m/Y à H:i', (int) $r['expires_at'])) ?></span></span>
          <?= cert_jauge($days) ?>
        </div>
      <?php endif; ?>
      <?php if ($r['error'] !== '' && $r['status'] !== 'ok' && $r['status'] !== 'warn'): ?>
        <p class="cert-err"><?= e($r['error']) ?></p>
      <?php endif; ?>
      <div class="cert-foot">
        <span class="muted small"><?= $r['checked_at'] ? 'Vérifié le ' . e(date('d/m à H:i', (int) $r['checked_at'])) : 'Pas encore vérifié' ?><?= $r['names'] !== '' ? ' · couvre : ' . e($r['names']) : '' ?></span>
        <form method="post" data-confirm="Ne plus surveiller <?= e($r['host']) ?> ?">
          <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
          <button class="btn btn-ghost btn-sm" type="submit">Retirer</button>
        </form>
      </div>
    </article>
  <?php endforeach; ?>
  </div>
  <?php endif; ?>
</section>

<section class="panel">
  <h2><?= icon('bell') ?> Où recevoir les alertes</h2>
  <form method="post" class="form cert-add">
    <?= csrf_field() ?><input type="hidden" name="action" value="mail">
    <label class="field"><span>Adresse e-mail</span>
      <input name="cert_mail" type="email" value="<?= e(setting('cert_mail')) ?>" placeholder="toi@exemple.fr" maxlength="254" autocapitalize="none" spellcheck="false"></label>
    <button class="btn btn-ghost" type="submit">Enregistrer</button>
    <button class="btn btn-primary" type="submit" name="test" value="1">Enregistrer et tester</button>
  </form>
  <p class="muted small"><?php if (!mail_configured()): ?>⚠️ L'envoi de mails n'est pas configuré sur le portail (réglages SMTP) : les alertes partent sur ntfy.<?php elseif (cert_mail_to() === ''): ?>Sans adresse, les alertes partent sur ntfy.<?php else: ?>Les alertes partent par e-mail ; si un envoi échoue, elles partent sur ntfy à la place.<?php endif; ?></p>
</section>

<section class="panel">
  <h2><?= icon('plus') ?> Ajouter un domaine</h2>
  <form method="post" class="form cert-add">
    <?= csrf_field() ?><input type="hidden" name="action" value="add">
    <label class="field"><span>Nom de domaine</span>
      <input name="host" placeholder="nc.leo44.cloud" required maxlength="253" autocapitalize="none" spellcheck="false" inputmode="url"></label>
    <label class="field field-xs"><span>Port</span>
      <input name="port" placeholder="443" inputmode="numeric" maxlength="5"></label>
    <button class="btn btn-primary" type="submit">Ajouter</button>
  </form>
  <p class="muted small">Le portail lit le certificat en passant par Zoraxy (<?= e(cert_target('exemple')) ?>), exactement comme un visiteur. Alertes à <?= (int) CERT_WARN_DAYS ?>, 7, 3 et 1 jour(s) de l'expiration, si un certificat est refusé ou injoignable deux fois de suite, et quand il est renouvelé après une alerte.</p>
</section>
<?php page_end();
