<?php
declare(strict_types=1);

// ─── Rappels (page « Rappels ») ─────────────────────────────────────────
// Chaque rappel garde la date de son prochain envoi (next_at). bin/rappels.php, lancé
// chaque minute par cron, envoie ceux dont l'heure est passée puis calcule l'envoi suivant.

const RAPPEL_FREQS = [
    'once'     => 'Une seule fois',
    'daily'    => 'Tous les jours',
    'weekly'   => 'Chaque semaine',
    'monthly'  => 'Chaque mois',
    'yearly'   => 'Chaque année',
    'interval' => 'Tous les N jours',
];
const RAPPEL_JOURS = [1 => 'lun', 2 => 'mar', 3 => 'mer', 4 => 'jeu', 5 => 'ven', 6 => 'sam', 7 => 'dim'];
const RAPPEL_PRIOS = [1 => 'Très basse (silencieuse)', 2 => 'Basse', 3 => 'Normale', 4 => 'Haute', 5 => 'Urgente'];
const RAPPEL_MAX_PAR_COMPTE = 200;
// Rappels insistants : intervalle de relance proposé (minutes) et durée maximale des relances.
const RAPPEL_RELANCES = [5 => 'toutes les 5 min', 10 => 'toutes les 10 min', 15 => 'toutes les 15 min', 30 => 'toutes les 30 min', 60 => 'toutes les heures'];
const RAPPEL_RELANCE_MAX = 24 * 3600;

function rappel_tz(): DateTimeZone
{
    return new DateTimeZone(APP_TIMEZONE);
}

/** Date + heure locales → DateTimeImmutable, ou null si invalide. */
function rappel_at(string $date, string $time): ?DateTimeImmutable
{
    $d = DateTimeImmutable::createFromFormat('!Y-m-d H:i', "$date $time", rappel_tz());
    $err = DateTimeImmutable::getLastErrors();
    return $d && (!$err || ($err['warning_count'] === 0 && $err['error_count'] === 0)) ? $d : null;
}

/** Prochain envoi strictement après $after (timestamp), ou null s'il n'y en a plus. */
function rappel_next(array $r, int $after): ?int
{
    [$h, $m] = array_map('intval', explode(':', (string) $r['at_time']));
    $base = (new DateTimeImmutable('@' . $after))->setTimezone(rappel_tz())->setTime(0, 0);
    $kind = (string) $r['kind'];

    if ($kind === 'once') {
        $d = rappel_at((string) $r['on_date'], (string) $r['at_time']);
        return $d && $d->getTimestamp() > $after ? $d->getTimestamp() : null;
    }
    if ($kind === 'daily' || $kind === 'weekly') {
        $days = $kind === 'weekly' ? array_map('intval', explode(',', (string) $r['weekdays'])) : [1, 2, 3, 4, 5, 6, 7];
        for ($i = 0; $i <= 8; $i++) {
            $d = $base->modify("+$i day")->setTime($h, $m);
            if (in_array((int) $d->format('N'), $days, true) && $d->getTimestamp() > $after) {
                return $d->getTimestamp();
            }
        }
        return null;
    }
    if ($kind === 'monthly') {
        $want = max(1, min(31, (int) $r['month_day']));
        $first = $base->modify('first day of this month');
        for ($i = 0; $i <= 2; $i++) {
            $mo = $first->modify("+$i month");
            // Le 31 tombe le dernier jour des mois plus courts (30, 28 ou 29).
            $d = $mo->setDate((int) $mo->format('Y'), (int) $mo->format('n'), min($want, (int) $mo->format('t')))->setTime($h, $m);
            if ($d->getTimestamp() > $after) {
                return $d->getTimestamp();
            }
        }
        return null;
    }
    if ($kind === 'yearly') {
        [, $mo, $day] = array_map('intval', explode('-', (string) $r['on_date']));
        $y = (int) $base->format('Y');
        for ($i = 0; $i <= 1; $i++) {
            $d = $base->setDate($y + $i, $mo, 1);
            $d = $d->setDate($y + $i, $mo, min($day, (int) $d->format('t')))->setTime($h, $m); // 29 février → 28
            if ($d->getTimestamp() > $after) {
                return $d->getTimestamp();
            }
        }
        return null;
    }
    if ($kind === 'interval') {
        $n = max(1, (int) $r['every_n']);
        $start = rappel_at((string) $r['on_date'], (string) $r['at_time']);
        if (!$start) {
            return null;
        }
        if ($start->getTimestamp() > $after) {
            return $start->getTimestamp();
        }
        $gap = (int) $start->setTime(0, 0)->diff($base)->days;
        $d = $start->modify('+' . (intdiv($gap, $n) * $n) . ' day')->setTime($h, $m);
        while ($d->getTimestamp() <= $after) {
            $d = $d->modify("+$n day")->setTime($h, $m);
        }
        return $d->getTimestamp();
    }
    return null;
}

/** « Tous les jours à 08:00 », « Chaque semaine : lun, mer à 18:30 »… */
function rappel_summary(array $r): string
{
    $at = ' à ' . $r['at_time'];
    $mois = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
    switch ($r['kind']) {
        case 'once':
            $d = rappel_at((string) $r['on_date'], (string) $r['at_time']);
            return 'Une fois, le ' . ($d ? french_date($d->getTimestamp()) : $r['on_date']) . $at;
        case 'daily':
            return 'Tous les jours' . $at;
        case 'weekly':
            $days = array_map('intval', explode(',', (string) $r['weekdays']));
            if ($days === [1, 2, 3, 4, 5]) {
                return 'Du lundi au vendredi' . $at;
            }
            if ($days === [6, 7]) {
                return 'Le week-end' . $at;
            }
            return 'Chaque semaine : ' . implode(', ', array_map(fn ($j) => RAPPEL_JOURS[$j] ?? '?', $days)) . $at;
        case 'monthly':
            $j = (int) $r['month_day'];
            return 'Le ' . ($j === 1 ? '1er' : $j) . ' de chaque mois' . $at . ($j > 28 ? ' (ou le dernier jour du mois)' : '');
        case 'yearly':
            [, $mo, $day] = array_map('intval', explode('-', (string) $r['on_date']));
            return 'Chaque année le ' . ($day === 1 ? '1er' : $day) . ' ' . ($mois[$mo - 1] ?? '?') . $at;
        case 'interval':
            return 'Tous les ' . (int) $r['every_n'] . ' jours' . $at;
    }
    return '';
}

/** « aujourd'hui à 18:30 », « demain à 08:00 », « samedi 14 novembre à 09:00 ». */
function rappel_when(?int $ts): string
{
    if (!$ts) {
        return '—';
    }
    $j = (new DateTimeImmutable('@' . $ts))->setTimezone(rappel_tz());
    $today = new DateTimeImmutable('today', rappel_tz());
    $diff = (int) $today->diff($j->setTime(0, 0))->format('%r%a');
    $h = ' à ' . $j->format('H:i');
    if ($diff === 0) {
        return "aujourd'hui" . $h;
    }
    if ($diff === 1) {
        return 'demain' . $h;
    }
    $txt = french_date($ts);
    return ($j->format('Y') === $today->format('Y') ? preg_replace('/ \d{4}$/', '', $txt) : $txt) . $h;
}

/**
 * Vérifie et nettoie le formulaire. Retourne [données, null] ou [null, message d'erreur].
 * @return array{0: ?array, 1: ?string}
 */
function rappel_from_post(array $p): array
{
    $title = trim((string) ($p['title'] ?? ''));
    $kind  = (string) ($p['kind'] ?? '');
    $time  = (string) ($p['at_time'] ?? '');
    $date  = trim((string) ($p['on_date'] ?? ''));
    $topic = trim((string) ($p['topic'] ?? ''));
    $data = [
        'title'     => $title,
        'message'   => trim(str_replace("\r\n", "\n", (string) ($p['message'] ?? ''))),
        'kind'      => $kind,
        'at_time'   => $time,
        'on_date'   => null,
        'weekdays'  => null,
        'month_day' => null,
        'every_n'   => null,
        'priority'  => (int) ($p['priority'] ?? 3),
        'topic'     => $topic,
        'nag_every' => (int) ($p['nag_every'] ?? 0),
    ];
    if (!isset(RAPPEL_RELANCES[$data['nag_every']])) {
        $data['nag_every'] = 0;
    }
    if ($title === '' || mb_strlen($title) > 100) {
        return [null, 'Donne un titre au rappel (100 caractères au maximum).'];
    }
    if (mb_strlen($data['message']) > 1000) {
        return [null, 'Message trop long (1000 caractères au maximum).'];
    }
    if (!isset(RAPPEL_FREQS[$kind])) {
        return [null, 'Choisis une fréquence.'];
    }
    if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) {
        return [null, 'Heure invalide (format HH:MM).'];
    }
    if (!isset(RAPPEL_PRIOS[$data['priority']])) {
        $data['priority'] = 3;
    }
    if ($topic !== '' && !preg_match('/^[A-Za-z0-9_-]{1,64}$/', $topic)) {
        return [null, 'Sujet ntfy invalide : lettres, chiffres, - et _ uniquement (64 caractères au maximum).'];
    }
    if (in_array($kind, ['once', 'yearly', 'interval'], true)) {
        if (!rappel_at($date, $time)) {
            return [null, 'Date invalide.'];
        }
        $data['on_date'] = $date;
    }
    if ($kind === 'weekly') {
        $days = array_values(array_unique(array_filter(array_map('intval', (array) ($p['weekdays'] ?? [])),
            fn ($d) => $d >= 1 && $d <= 7)));
        sort($days);
        if (!$days) {
            return [null, 'Coche au moins un jour de la semaine.'];
        }
        $data['weekdays'] = implode(',', $days);
    }
    if ($kind === 'monthly') {
        $d = (int) ($p['month_day'] ?? 0);
        if ($d < 1 || $d > 31) {
            return [null, 'Jour du mois invalide (1 à 31).'];
        }
        $data['month_day'] = $d;
    }
    if ($kind === 'interval') {
        $n = (int) ($p['every_n'] ?? 0);
        if ($n < 2 || $n > 365) {
            return [null, 'Nombre de jours invalide (2 à 365).'];
        }
        $data['every_n'] = $n;
    }
    if ($kind === 'once' && rappel_next($data, time()) === null) {
        return [null, 'Cette date est déjà passée.'];
    }
    return [$data, null];
}

/** Adresse publique du portail (pour les boutons des notifications), ou '' si inconnue. */
function portal_url(): string
{
    return rtrim(APP_URL !== '' ? APP_URL : setting('portal_url'), '/');
}

/** Retient l'adresse du portail telle que le navigateur l'utilise (le planificateur ne la connaît pas). */
function remember_portal_url(): void
{
    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    if (APP_URL !== '' || !preg_match('/^[a-z0-9.-]+(:\d{1,5})?$/', $host)) {
        return;
    }
    $url = (is_https() ? 'https://' : 'http://') . $host;
    if (setting('portal_url') !== $url) {
        set_setting('portal_url', $url);
    }
}

/** Jeton du bouton « C'est fait » : valable seulement pour l'occurrence en attente. */
function rappel_ack_token(array $r): string
{
    return substr(hash_hmac('sha256', 'ack|' . $r['id'] . '|' . $r['pending_since'], app_key()), 0, 40);
}

/** Boutons ajoutés à la notification d'un rappel insistant. */
function rappel_actions(array $r): array
{
    $base = portal_url();
    if ($base === '' || (int) $r['nag_every'] === 0) {
        return [];
    }
    $ack = $base . '/rappel-ok.php?id=' . (int) $r['id'] . '&k=' . rappel_ack_token($r);
    // « click » : toucher la notification ouvre aussi la page de validation (si les boutons ne s'affichent pas).
    return ['click' => $ack, 'actions' => [
        ['action' => 'http', 'label' => "✅ C'est fait", 'url' => $ack, 'method' => 'POST', 'clear' => true],
        ['action' => 'view', 'label' => 'Ouvrir', 'url' => $ack],
    ]];
}

/** Sujet ntfy d'un rappel : le sien, sinon celui choisi pour tous les rappels du compte, sinon celui du portail. */
function rappel_topic(array $r): ?string
{
    foreach ([$r['topic'] ?? '', $r['user_topic'] ?? ''] as $t) {
        if ($t !== '' && $t !== null) {
            return (string) $t;
        }
    }
    return null;
}

/** Requête des rappels avec le sujet ntfy de leur compte. */
const RAPPEL_SELECT = 'SELECT r.*, u.rappel_topic AS user_topic FROM reminders r JOIN users u ON u.id = r.user_id';

function rappel_send(array $r, string $title, string $msg, array $tags): ?string
{
    return ntfy_send($title, $msg, (int) $r['priority'], $tags, rappel_topic($r), rappel_actions($r));
}

/** Valide l'occurrence en attente : fin des relances. */
function rappel_ack(array $r, ?int $userId, string $how): void
{
    db()->prepare('UPDATE reminders SET pending_since = NULL, nag_next = NULL, nag_count = 0,
            enabled = CASE WHEN next_at IS NULL THEN 0 ELSE enabled END WHERE id = ?')->execute([$r['id']]);
    log_event($userId ?? (int) $r['user_id'], "Rappel « {$r['title']} » validé ($how)");
}

/** Envoie les rappels arrivés à échéance et les relances (appelé chaque minute par bin/rappels.php). */
function rappels_run(int $now): void
{
    $st = db()->prepare(RAPPEL_SELECT . ' WHERE r.enabled = 1 AND r.next_at IS NOT NULL AND r.next_at <= ? ORDER BY r.next_at');
    $st->execute([$now]);
    foreach ($st->fetchAll() as $r) {
        $late = $now - (int) $r['next_at'];
        $msg  = $r['message'] !== '' ? (string) $r['message'] : 'Rappel';
        if ($late > 300) { // serveur arrêté au moment prévu : on le signale
            $msg .= "\n(prévu " . rappel_when((int) $r['next_at']) . ')';
        }
        $nag = (int) $r['nag_every'] > 0;
        if ($nag) {
            // Nouvelle occurrence : elle remplace une éventuelle occurrence précédente jamais validée.
            $r['pending_since'] = (int) $r['next_at'];
            $msg .= "\nRelance toutes les " . (int) $r['nag_every'] . " min jusqu'à validation.";
        }
        $err = rappel_send($r, (string) $r['title'], $msg, $nag ? ['alarm_clock', 'repeat'] : ['alarm_clock']);
        if ($err === null) {
            // Les envois manqués pendant une panne ne sont pas rattrapés un par un : on repart de maintenant.
            $next = rappel_next($r, max($now, (int) $r['next_at']));
            db()->prepare('UPDATE reminders SET last_sent_at = ?, next_at = ?, enabled = ?, last_error = \'\',
                    pending_since = ?, nag_next = ?, nag_count = 0 WHERE id = ?')
                ->execute([$now, $next, $next === null && !$nag ? 0 : 1,
                    $nag ? $r['pending_since'] : null, $nag ? $now + 60 * (int) $r['nag_every'] : null, $r['id']]);
        } elseif ($r['last_error'] !== $err) {
            // Nouvel essai chaque minute, mais une seule ligne au journal par erreur.
            db()->prepare('UPDATE reminders SET last_error = ? WHERE id = ?')->execute([$err, $r['id']]);
            log_event((int) $r['user_id'], "Rappel « {$r['title']} » non envoyé : $err");
        }
    }

    // Relances des rappels insistants pas encore validés.
    $st = db()->prepare(RAPPEL_SELECT . ' WHERE r.enabled = 1 AND r.pending_since IS NOT NULL AND r.nag_next <= ?');
    $st->execute([$now]);
    foreach ($st->fetchAll() as $r) {
        if ($now - (int) $r['pending_since'] > RAPPEL_RELANCE_MAX) {
            db()->prepare('UPDATE reminders SET pending_since = NULL, nag_next = NULL, nag_count = 0,
                    enabled = CASE WHEN next_at IS NULL THEN 0 ELSE enabled END WHERE id = ?')->execute([$r['id']]);
            log_event((int) $r['user_id'], "Rappel « {$r['title']} » : relances arrêtées après 24 h sans validation");
            continue;
        }
        $n   = (int) $r['nag_count'] + 1;
        $msg = ($r['message'] !== '' ? $r['message'] . "\n" : '') . "Relance n°$n · prévu " . rappel_when((int) $r['pending_since']) . ', pas encore validé.';
        if (rappel_send($r, '🔁 ' . $r['title'], $msg, ['repeat']) === null) {
            db()->prepare('UPDATE reminders SET nag_count = ?, nag_next = ? WHERE id = ?')
                ->execute([$n, $now + 60 * (int) $r['nag_every'], $r['id']]);
        }
        // En cas d'échec, nag_next ne bouge pas : nouvel essai à la minute suivante.
    }
}
