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
    ];
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

/** Envoie les rappels arrivés à échéance (appelé chaque minute par bin/rappels.php). */
function rappels_run(int $now): void
{
    $st = db()->prepare('SELECT * FROM reminders WHERE enabled = 1 AND next_at IS NOT NULL AND next_at <= ? ORDER BY next_at');
    $st->execute([$now]);
    foreach ($st->fetchAll() as $r) {
        $late = $now - (int) $r['next_at'];
        $msg  = $r['message'] !== '' ? (string) $r['message'] : 'Rappel';
        if ($late > 300) { // serveur arrêté au moment prévu : on le signale
            $msg .= "\n(prévu " . rappel_when((int) $r['next_at']) . ')';
        }
        $err = ntfy_send((string) $r['title'], $msg, (int) $r['priority'], ['alarm_clock'],
            $r['topic'] !== '' ? (string) $r['topic'] : null);
        if ($err === null) {
            // Les envois manqués pendant une panne ne sont pas rattrapés un par un : on repart de maintenant.
            $next = rappel_next($r, max($now, (int) $r['next_at']));
            db()->prepare('UPDATE reminders SET last_sent_at = ?, next_at = ?, enabled = ?, last_error = \'\' WHERE id = ?')
                ->execute([$now, $next, $next === null ? 0 : 1, $r['id']]);
        } elseif ($r['last_error'] !== $err) {
            // Nouvel essai chaque minute, mais une seule ligne au journal par erreur.
            db()->prepare('UPDATE reminders SET last_error = ? WHERE id = ?')->execute([$err, $r['id']]);
            log_event((int) $r['user_id'], "Rappel « {$r['title']} » non envoyé : $err");
        }
    }
}
