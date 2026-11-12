<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';

$me  = require_login();
$uid = (int) $me['id'];
remember_portal_url();

/** Rappel de l'utilisateur connecté (jamais celui d'un autre compte). */
function my_reminder(int $id, int $uid): ?array
{
    $st = db()->prepare(RAPPEL_SELECT . ' WHERE r.id = ? AND r.user_id = ?');
    $st->execute([$id, $uid]);
    return $st->fetch() ?: null;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    require_post_csrf();
    $action = (string) ($_POST['action'] ?? '');
    $id     = (int) ($_POST['id'] ?? 0);
    $r      = $id ? my_reminder($id, $uid) : null;
    $msg    = null;

    if ($action === 'topic_save') {
        $t  = trim((string) ($_POST['rappel_topic'] ?? ''));
        $qa = (string) ($_POST['quiet_start'] ?? '');
        $qb = (string) ($_POST['quiet_end'] ?? '');
        $hm = '/^([01]\d|2[0-3]):[0-5]\d$/';
        if (empty($_POST['quiet_on'])) {
            $qa = $qb = '';
        }
        if ($t !== '' && !preg_match('/^[A-Za-z0-9_-]{1,64}$/', $t)) {
            $msg = 'Sujet ntfy invalide : lettres, chiffres, - et _ uniquement (64 caractères au maximum).';
        } elseif ($qa !== '' && (!preg_match($hm, $qa) || !preg_match($hm, $qb) || $qa === $qb)) {
            $msg = 'Heures de silence invalides (début différent de la fin).';
        } else {
            db()->prepare('UPDATE users SET rappel_topic = ?, quiet_start = ?, quiet_end = ? WHERE id = ?')->execute([$t, $qa, $qb, $uid]);
            log_event($uid, 'Sujet ntfy des rappels : ' . ($t !== '' ? $t : 'celui du portail'));
            $msg = 'Réglages enregistrés · sujet « ' . ($t !== '' ? $t : setting('ntfy_topic')) . ' »'
                . ($qa !== '' ? " · silence de $qa à $qb" : ' · pas d\'heures de silence') . '.';
            if ($t !== '' && isset($_POST['test'])) {
                $msg = ($err = ntfy_send('Test des rappels', 'Tes rappels arriveront ici.', 3, ['alarm_clock'], $t)) ?? $msg . ' Test envoyé.';
            }
        }
        flash($msg);
        redirect('/rappels.php');
    }

    if (in_array($action, ['create', 'update'], true)) {
        [$data, $err] = rappel_from_post($_POST);
        $count = (int) db()->query('SELECT COUNT(*) FROM reminders WHERE user_id = ' . $uid)->fetchColumn();
        if ($err === null && $action === 'create' && $count >= RAPPEL_MAX_PAR_COMPTE) {
            $err = 'Nombre maximum de rappels atteint (' . RAPPEL_MAX_PAR_COMPTE . ').';
        }
        if ($err === null && $action === 'update' && !$r) {
            $err = 'Rappel introuvable.';
        }
        if ($err !== null) {
            $_SESSION['rappel_form'] = ['id' => $action === 'update' ? $id : 0, 'post' => $_POST];
            flash($err);
            redirect('/rappels.php' . ($action === 'update' ? '#r' . $id : '#nouveau'));
        }
        $enabled = $action === 'create' || isset($_POST['enabled']) ? 1 : 0;
        $next = rappel_next($data + ['sent_count' => $r['sent_count'] ?? 0], time());
        if ($action === 'update' && $enabled && $next === null) {
            $enabled = 0; // nombre d'envois déjà atteint
        }
        $cols = [$data['title'], $data['message'], $data['kind'], $data['at_time'], $data['on_date'], $data['weekdays'],
                 $data['month_day'], $data['every_n'], $data['priority'], $data['topic'], $enabled, $next, $data['nag_every'],
                 $data['nag_escalate'], $data['end_date'], $data['max_count']];
        if ($action === 'create') {
            db()->prepare('INSERT INTO reminders (title, message, kind, at_time, on_date, weekdays, month_day, every_n,
                    priority, topic, enabled, next_at, nag_every, nag_escalate, end_date, max_count, user_id, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([...$cols, $uid, time()]);
            $id = (int) db()->lastInsertId();
            log_event($uid, "Rappel créé : « {$data['title']} »");
            $msg = 'Rappel enregistré · prochain envoi ' . rappel_when($next) . '.';
        } else {
            db()->prepare('UPDATE reminders SET title = ?, message = ?, kind = ?, at_time = ?, on_date = ?, weekdays = ?,
                    month_day = ?, every_n = ?, priority = ?, topic = ?, enabled = ?, next_at = ?, nag_every = ?, nag_escalate = ?, end_date = ?, max_count = ?, last_error = \'\'
                    WHERE id = ? AND user_id = ?')
                ->execute([...$cols, $id, $uid]);
            if (!$enabled || $data['nag_every'] === 0) { // plus insistant ou en pause : fin des relances
                db()->prepare('UPDATE reminders SET pending_since = NULL, nag_next = NULL, nag_count = 0 WHERE id = ?')->execute([$id]);
            }
            $msg = 'Rappel modifié' . ($enabled && $next ? ' · prochain envoi ' . rappel_when($next) : '') . '.';
        }
    } elseif ($r && $action === 'toggle') {
        $on   = (int) $r['enabled'] === 1 ? 0 : 1;
        $next = $on ? rappel_next($r, time()) : $r['next_at'];
        if ($on && $next === null) {
            $msg = 'Ce rappel est terminé : modifie sa date ou sa fin pour le relancer.';
        } else {
            // En le réactivant, on repart de maintenant : pas de rafale d'envois en retard.
            db()->prepare('UPDATE reminders SET enabled = ?, next_at = ?, last_error = \'\', pending_since = NULL, nag_next = NULL, nag_count = 0
                WHERE id = ?')->execute([$on, $next, $r['id']]);
            $msg = $on ? 'Rappel réactivé · prochain envoi ' . rappel_when($next) . '.' : 'Rappel mis en pause.';
        }
    } elseif ($r && $action === 'ack' && !isset($_POST['a'])) {
        if ($r['pending_since'] !== null) {
            rappel_ack($r, $uid, 'depuis le portail');
        }
        $msg = '✅ Rappel validé : plus de relance.';
    } elseif ($r && in_array($action, ['snooze', 'ack'], true) && ($until = rappel_report_until((string) ($_POST['a'] ?? ''), time())) !== null) {
        rappel_snooze($r, $until, $uid);
        $msg = '⏰ Rappel reporté : il revient ' . rappel_when($until) . '.';
    } elseif ($r && $action === 'test') {
        $err = ntfy_send($r['title'], ($r['message'] !== '' ? $r['message'] : 'Rappel') . "\n(test)", (int) $r['priority'],
            ['alarm_clock'], rappel_topic($r));
        $msg = $err ?? 'Notification de test envoyée.';
    } elseif ($r && $action === 'delete') {
        db()->prepare('DELETE FROM reminders WHERE id = ? AND user_id = ?')->execute([$r['id'], $uid]);
        log_event($uid, "Rappel supprimé : « {$r['title']} »");
        $msg = 'Rappel supprimé.';
    }
    flash($msg ?? 'Rappel introuvable.');
    redirect('/rappels.php' . ($r && $action !== 'delete' ? '#r' . $r['id'] : ''));
}

// Formulaire refusé : on réaffiche ce qui avait été saisi.
$retry = $_SESSION['rappel_form'] ?? null;
unset($_SESSION['rappel_form']);

$st = db()->prepare('SELECT * FROM reminders WHERE user_id = ? ORDER BY pending_since IS NULL, enabled DESC, next_at IS NULL, next_at, id');
$st->execute([$uid]);
$list    = $st->fetchAll();
$active  = array_filter($list, fn ($r) => (int) $r['enabled'] === 1);
$active  = array_filter($active, fn ($r) => $r['next_at'] !== null);
$soon    = $active ? min(array_map(fn ($r) => (int) $r['next_at'], $active)) : null;
$pending = array_filter($list, fn ($r) => $r['pending_since'] !== null && (int) $r['enabled'] === 1);

/** Formulaire de création ou de modification d'un rappel. */
function rappel_form(?array $r, ?array $post): void
{
    $v = static function (string $k, string $def = '') use ($r, $post): string {
        if ($post !== null) {
            return is_array($post[$k] ?? null) ? '' : (string) ($post[$k] ?? $def);
        }
        return $r !== null && $r[$k] !== null ? (string) $r[$k] : $def;
    };
    $kind = $v('kind', 'daily');
    $days = $post !== null ? array_map('intval', (array) ($post['weekdays'] ?? []))
        : ($r && $r['weekdays'] ? array_map('intval', explode(',', $r['weekdays'])) : [1, 2, 3, 4, 5]);
    // Un rappel « une fois » déjà envoyé se reprogramme actif par défaut.
    $enabled = $post !== null ? isset($post['enabled'])
        : (!$r || (int) $r['enabled'] === 1 || ($r['kind'] === 'once' && $r['last_sent_at']));
    $prio = (int) $v('priority', '3');
    $escal = $post !== null ? !empty($post['nag_escalate']) : (!$r || (int) $r['nag_escalate'] === 1);
    $endKind = $post !== null ? (string) ($post['end_kind'] ?? 'never')
        : ($r && $r['end_date'] ? 'date' : ($r && $r['max_count'] ? 'count' : 'never'));
    ?>
    <form method="post" class="form rp-form" data-rappel>
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="<?= $r ? 'update' : 'create' ?>">
      <?php if ($r): ?><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><?php endif; ?>
      <label class="field"><span>Titre</span>
        <input name="title" value="<?= e($v('title')) ?>" maxlength="100" required placeholder="Ex. Sortir les poubelles"></label>
      <label class="field"><span>Message <em>facultatif</em></span>
        <textarea name="message" maxlength="1000" rows="2" placeholder="Texte affiché dans la notification"><?= e($v('message')) ?></textarea></label>
      <div class="rp-row">
        <label class="field"><span>Fréquence</span>
          <select name="kind" data-freq>
            <?php foreach (RAPPEL_FREQS as $k => $label): ?>
              <option value="<?= e($k) ?>" <?= $k === $kind ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
          </select></label>
        <label class="field"><span>Heure</span>
          <input type="time" name="at_time" value="<?= e($v('at_time', '08:00')) ?>" required></label>
      </div>
      <div class="rp-row">
        <label class="field" data-show="once yearly interval"><span data-lbl-once="Date" data-lbl-yearly="Date (jour et mois)" data-lbl-interval="À partir du">Date</span>
          <input type="date" name="on_date" value="<?= e($v('on_date', date('Y-m-d'))) ?>"></label>
        <label class="field" data-show="monthly"><span>Jour du mois</span>
          <input type="number" name="month_day" min="1" max="31" value="<?= e($v('month_day', date('j'))) ?>"></label>
        <label class="field" data-show="interval"><span>Tous les … jours</span>
          <input type="number" name="every_n" min="2" max="365" value="<?= e($v('every_n', '2')) ?>"></label>
      </div>
      <fieldset class="rp-days" data-show="weekly">
        <legend>Jours</legend>
        <?php foreach (RAPPEL_JOURS as $n => $j): ?>
          <label class="rp-day"><input type="checkbox" name="weekdays[]" value="<?= $n ?>" <?= in_array($n, $days, true) ? 'checked' : '' ?>><span><?= e(ucfirst($j)) ?></span></label>
        <?php endforeach; ?>
      </fieldset>
      <div class="rp-row">
        <label class="field"><span>Priorité</span>
          <select name="priority">
            <?php foreach (RAPPEL_PRIOS as $p => $label): ?>
              <option value="<?= $p ?>" <?= $p === $prio ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
          </select></label>
        <label class="field"><span>Type</span>
          <select name="nag_every" data-ctl>
            <option value="0">Normal : une notification</option>
            <?php foreach (RAPPEL_RELANCES as $n => $label): ?>
              <option value="<?= $n ?>" <?= $n === (int) $v('nag_every', '0') ? 'selected' : '' ?>>🔁 Insistant : <?= e($label) ?></option>
            <?php endforeach; ?>
          </select></label>
      </div>
      <label class="check" data-if="nag_every:5 10 15 30 60"><input type="checkbox" name="nag_escalate" value="1" <?= $escal ? 'checked' : '' ?>> 🚨 Passer en « Urgente » à la <?= RAPPEL_ESCALADE ?>e relance (sonne même en silencieux)</label>
      <div class="rp-row" data-show="daily weekly monthly yearly interval">
        <label class="field"><span>Fin</span>
          <select name="end_kind" data-ctl>
            <option value="never" <?= $endKind === 'never' ? 'selected' : '' ?>>Jamais</option>
            <option value="date" <?= $endKind === 'date' ? 'selected' : '' ?>>Jusqu'à une date</option>
            <option value="count" <?= $endKind === 'count' ? 'selected' : '' ?>>Après un nombre d'envois</option>
          </select></label>
        <label class="field" data-if="end_kind:date"><span>Dernier jour</span>
          <input type="date" name="end_date" value="<?= e($v('end_date')) ?>"></label>
        <label class="field" data-if="end_kind:count"><span>Nombre d'envois</span>
          <input type="number" name="max_count" min="1" max="1000" value="<?= e($v('max_count', '10')) ?>"></label>
      </div>
      <div class="rp-row">
        <label class="field"><span>Sujet ntfy <em>seulement pour ce rappel</em></span>
          <input name="topic" value="<?= e($v('topic')) ?>" maxlength="64" pattern="[A-Za-z0-9_\-]{1,64}" spellcheck="false" autocomplete="off"
                 placeholder="<?= e(($GLOBALS['me']['rappel_topic'] ?? '') ?: (setting('ntfy_topic') ?: 'celui du portail')) ?> (comme les autres)"></label>
      </div>
      <?php if ($r): ?>
        <label class="check"><input type="checkbox" name="enabled" value="1" <?= $enabled ? 'checked' : '' ?>> Actif</label>
      <?php endif; ?>
      <div><button class="btn btn-primary btn-sm" type="submit"><?= $r ? 'Enregistrer' : 'Créer le rappel' ?></button></div>
    </form>
    <?php
}

page_start('Rappels', $me, 'rappels');
?>
<header class="topbar">
  <div>
    <p class="eyebrow">Notifications ntfy</p>
    <h1>Rappels</h1>
  </div>
</header>

<?php if (!ntfy_configured()): ?>
  <div class="alert alert-warn" role="alert">
    Les notifications ne sont pas encore configurées : les rappels ne partiront pas.
    <?= $me['is_admin'] ? 'Renseigne le serveur ntfy dans <a href="/admin.php">Administration</a>.' : 'Demande à un administrateur de configurer ntfy.' ?>
  </div>
<?php endif; ?>

<section class="stats stats-3">
  <div class="stat">
    <span class="stat-ic"><?= icon('bell') ?></span>
    <div><small>Rappels actifs</small><strong><?= count($active) ?></strong></div>
  </div>
  <div class="stat">
    <span class="stat-ic"><?= icon('clock') ?></span>
    <div><small>À valider</small><strong><?= count($pending) ?></strong></div>
  </div>
  <div class="stat">
    <span class="stat-ic"><?= icon('pulse') ?></span>
    <div><small>Prochain</small><strong class="stat-sm"><?= e($soon ? ucfirst(rappel_when($soon)) : '—') ?></strong></div>
  </div>
</section>

<section class="panel" id="sujet">
  <form method="post" class="form rp-form">
    <?= csrf_field() ?><input type="hidden" name="action" value="topic_save">
    <label class="field"><span>Sujet ntfy de tous mes rappels</span>
      <input name="rappel_topic" value="<?= e($me['rappel_topic']) ?>" maxlength="64" pattern="[A-Za-z0-9_\-]{1,64}" spellcheck="false" autocomplete="off"
             placeholder="<?= e(setting('ntfy_topic') ?: 'portail-rappels') ?> (celui du portail)"></label>
    <label class="check"><input type="checkbox" name="quiet_on" value="1" data-quiet <?= $me['quiet_start'] !== '' ? 'checked' : '' ?>> 🌙 Heures de silence</label>
    <div class="rp-row rp-quiet">
    <label class="field"><span>De</span><input type="time" name="quiet_start" value="<?= e($me['quiet_start'] ?: '23:00') ?>"></label>
    <label class="field"><span>À</span><input type="time" name="quiet_end" value="<?= e($me['quiet_end'] ?: '07:00') ?>"></label>
    </div>
    <div class="ct-actions">
    <button class="btn btn-ghost btn-sm" type="submit">Enregistrer</button>
    <button class="btn btn-ghost btn-sm" type="submit" name="test" value="1"><?= icon('bell') ?> Enregistrer et tester</button>
    </div>
  </form>
  <p class="muted small rp-hint">Sujet vide : les rappels arrivent sur le même sujet que les alertes du portail. Pendant les heures de silence, seuls les rappels réglés en « Urgente » partent ; les autres attendent la fin et arrivent avec leur heure d'origine.</p>
</section>

<section class="panel panel-accent" id="nouveau">
  <details class="ct-edit" <?= !$list || ($retry && !$retry['id']) ? 'open' : '' ?>>
    <summary><?= icon('bell') ?> Nouveau rappel</summary>
    <?php rappel_form(null, $retry && !$retry['id'] ? $retry['post'] : null); ?>
  </details>
</section>

<?php if ($list): ?>
<div class="ct-list">
<?php foreach ($list as $r):
    $on   = (int) $r['enabled'] === 1;
    $done = !$on && $r['next_at'] === null && $r['last_sent_at'];
    $edit = $retry && (int) $retry['id'] === (int) $r['id'];
    $wait = $on && $r['pending_since'] !== null;
    ?>
  <article class="ct rp<?= $on ? ' ct-run' : ' rp-off' ?><?= $wait ? ' rp-wait' : '' ?>" id="r<?= (int) $r['id'] ?>">
    <div class="ct-head">
      <span class="ct-ic"><?= icon('bell') ?></span>
      <div class="ct-title">
        <strong><?= e($r['title']) ?><?php if ((int) $r['priority'] >= 4): ?> <span class="badge badge-warn"><?= e(RAPPEL_PRIOS[(int) $r['priority']]) ?></span><?php endif; ?></strong>
        <span class="host"><?= e(rappel_summary($r)) ?><?= (int) $r['nag_every'] ? ' · 🔁 relance ' . e(RAPPEL_RELANCES[(int) $r['nag_every']] ?? '') : '' ?></span>
      </div>
      <span class="ct-state"><span class="dot <?= $on ? 'up' : '' ?>"></span><?= $wait ? 'À valider' : ($on ? 'Actif' : ($done ? 'Terminé' : 'En pause')) ?></span>
    </div>
    <?php if ($r['message'] !== ''): ?><p class="rp-msg"><?= nl2br(e($r['message'])) ?></p><?php endif; ?>
    <?php if ($wait): ?>
      <form method="post" class="rp-ack">
        <?= csrf_field() ?><input type="hidden" name="action" value="ack"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
        <button class="btn btn-primary" type="submit">✅ C'est fait</button>
        <?php foreach (RAPPEL_REPORTS as $code => $label): ?>
          <button class="btn btn-ghost btn-sm" type="submit" name="a" value="<?= e($code) ?>">⏰ <?= e($label) ?></button>
        <?php endforeach; ?>
        <span class="small">Prévu <?= e(rappel_when((int) $r['pending_since'])) ?><?= (int) $r['nag_count'] ? ' · relancé ' . (int) $r['nag_count'] . ' fois' : '' ?> · prochaine relance <?= e(substr(rappel_when((int) $r['nag_next']), -5)) ?></span>
      </form>
    <?php endif; ?>
    <p class="ct-sum">
      <?php if ($on && $r['next_at'] === null): ?><span class="muted">Pas d'autre envoi prévu après celui-ci</span><?php elseif ($on): ?>Prochain envoi : <strong><?= e(rappel_when((int) $r['next_at'])) ?></strong><?php else: ?><span class="muted"><?= $done ? 'Envoyé' : 'Aucun envoi tant qu\'il est en pause' ?></span><?php endif; ?>
      <?php if ($r['snooze_at']): ?><br><span class="small rp-snz">⏰ Reporté : revient <?= e(rappel_when((int) $r['snooze_at'])) ?></span><?php endif; ?>
      <?php if ($r['last_sent_at'] && !$done): ?><br><span class="muted small">Dernier envoi : <?= e(french_datetime((int) $r['last_sent_at'])) ?></span><?php endif; ?>
      <?php if ($done): ?><span class="muted"> le <?= e(french_datetime((int) $r['last_sent_at'])) ?></span><?php endif; ?>
      <?php if ($r['topic'] !== ''): ?><br><span class="muted small">Sujet ntfy de ce rappel : <span class="mono"><?= e($r['topic']) ?></span></span><?php endif; ?>
    </p>
    <?php if ($r['last_error'] !== '' && $on): ?>
      <div class="alert alert-error small" role="alert">Envoi en échec, nouvel essai chaque minute : <?= e($r['last_error']) ?></div>
    <?php endif; ?>
    <div class="ct-actions">
      <?php if (!$done): ?>
      <form method="post">
        <?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
        <button class="btn btn-ghost btn-sm" type="submit"><?= $on ? '⏸ Pause' : '▶ Réactiver' ?></button>
      </form>
      <?php endif; ?>
      <form method="post">
        <?= csrf_field() ?><input type="hidden" name="action" value="test"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
        <button class="btn btn-ghost btn-sm" type="submit"><?= icon('bell') ?> Tester</button>
      </form>
      <form method="post" data-confirm="Supprimer le rappel « <?= e($r['title']) ?> » ?">
        <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
        <button class="btn btn-danger btn-sm" type="submit">Supprimer</button>
      </form>
    </div>
    <details class="ct-edit" <?= $edit ? 'open' : '' ?>>
      <summary><?= $done ? 'Reprogrammer' : 'Modifier' ?></summary>
      <?php rappel_form($r, $edit ? $retry['post'] : null); ?>
    </details>
  </article>
<?php endforeach; ?>
</div>
<?php endif; ?>
<p class="muted small">Un rappel 🔁 insistant revient toutes les X minutes jusqu'à ce que tu appuies sur « C'est fait », dans la notification ou ici (24 h au maximum).<?php if (portal_url() === ''): ?> Le bouton dans la notification apparaîtra dès que cette page aura été ouverte depuis l'adresse du portail.<?php endif; ?> Les rappels partent sur ntfy à la minute près. Si le serveur était éteint à l'heure prévue, le rappel part dès son redémarrage avec l'heure d'origine. Un rappel le 29, 30 ou 31 du mois tombe le dernier jour des mois plus courts.</p>
<?php page_end();
