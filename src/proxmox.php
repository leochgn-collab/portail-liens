<?php
declare(strict_types=1);

// Client minimal de l'API Proxmox VE + logique des plages horaires (page « Conteneurs »).

function pve_configured(): bool
{
    return PVE_URL !== '' && PVE_TOKEN !== '';
}

/** Le certificat de Proxmox est-il vérifié ? */
function pve_verify(): bool
{
    return PVE_CA !== '' || PVE_VERIFY_TLS;
}

/** @return array{ok:bool,data:mixed,error:?string} */
function pve_request(string $method, string $path, array $data = []): array
{
    if (!pve_configured()) {
        return ['ok' => false, 'data' => null, 'error' => 'Proxmox non configuré (PVE_URL / PVE_TOKEN).'];
    }
    $ch = curl_init(rtrim(PVE_URL, '/') . '/api2/json' . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => ['Authorization: PVEAPIToken=' . PVE_TOKEN],
        CURLOPT_SSL_VERIFYPEER => pve_verify(),
        CURLOPT_SSL_VERIFYHOST => pve_verify() ? 2 : 0,
        CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$statusText): int {
            if (preg_match('#^HTTP/\S+\s+\d+\s+(.+)$#', trim($line), $m)) {
                $statusText = $m[1];
            }
            return strlen($line);
        },
    ]);
    $statusText = '';
    if (PVE_CA !== '') {
        curl_setopt($ch, CURLOPT_CAINFO, PVE_CA);
    }
    if ($data && $method !== 'GET') {
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    }
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err  = curl_error($ch);
    if ($body === false) {
        $tls = stripos($err, 'certificate') !== false || stripos($err, 'SSL') !== false;
        return ['ok' => false, 'data' => null, 'error' => ($tls ? 'Certificat de Proxmox refusé (vérifie PVE_CA et l\'adresse PVE_URL) : ' : 'Proxmox injoignable : ') . $err];
    }
    $json = json_decode((string) $body, true);
    if (!is_array($json)) {
        $json = [];
    }
    $json['message'] ??= $statusText;
    if ($code >= 400) {
        if ($code === 401 || $code === 403) {
            $msg = 'Proxmox refuse l\'accès (jeton ou droits insuffisants).';
        } else {
            // Proxmox donne la raison dans le texte de statut HTTP (ex. « CT 209 already running »).
            $raison = trim((string) ($json['message'] ?? ''));
            $msg = "Erreur Proxmox HTTP $code" . ($raison !== '' ? ' : ' . mb_substr($raison, 0, 200) : '.');
        }
        return ['ok' => false, 'data' => null, 'error' => $msg];
    }
    return ['ok' => true, 'data' => $json['data'] ?? null, 'error' => null];
}

/** Liste des CT du nœud, triés par identifiant. @return array{cts:array,error:?string} */
function pve_list_cts(): array
{
    $r = pve_request('GET', '/nodes/' . rawurlencode(PVE_NODE) . '/lxc');
    if (!$r['ok']) {
        return ['cts' => [], 'error' => $r['error']];
    }
    $cts = is_array($r['data']) ? $r['data'] : [];
    usort($cts, fn ($a, $b) => (int) $a['vmid'] <=> (int) $b['vmid']);
    return ['cts' => $cts, 'error' => null];
}

/**
 * Action : start | shutdown | stop. Renvoie un message d'erreur ou null.
 * « shutdown » est un arrêt propre ; Proxmox force l'arrêt seulement si le CT
 * ne s'est pas éteint au bout de PVE_SHUTDOWN_TIMEOUT secondes.
 */
function pve_action(int $vmid, string $action): ?string
{
    if (!in_array($action, ['start', 'shutdown', 'stop'], true)) {
        return 'Action inconnue.';
    }
    if ($action !== 'start' && in_array($vmid, PVE_PROTECTED, true)) {
        return "Le CT $vmid est protégé : le portail ne peut pas l'arrêter.";
    }
    $data = $action === 'shutdown' ? ['timeout' => PVE_SHUTDOWN_TIMEOUT, 'forceStop' => 1] : [];
    $r = pve_request('POST', '/nodes/' . rawurlencode(PVE_NODE) . "/lxc/$vmid/status/$action", $data);
    return $r['ok'] ? null : $r['error'];
}

function ct_valid_time(string $t): bool
{
    return (bool) preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t);
}

function ct_minutes(string $t): int
{
    [$h, $m] = explode(':', $t);
    return (int) $h * 60 + (int) $m;
}

/** Vrai si le CT doit être éteint à $now (HH:MM). Gère les plages qui passent minuit. */
function ct_should_be_off(string $off, string $on, string $now): bool
{
    $a = ct_minutes($off);
    $b = ct_minutes($on);
    $n = ct_minutes($now);
    if ($a === $b) {
        return false;
    }
    return $a < $b ? ($n >= $a && $n < $b) : ($n >= $a || $n < $b);
}

/** Frise 24 h : vert = allumé, rouge = éteint, trait blanc = maintenant. */
function ct_timeline(string $off, string $on, bool $enabled = true): string
{
    $a = ct_minutes($off);
    $b = ct_minutes($on);
    $segs = $a < $b ? [[$a, $b]] : [[$a, 1440], [0, $b]];
    $k = 240 / 1440;
    $svg = '<svg class="tl' . ($enabled ? '' : ' tl-paused') . '" viewBox="0 0 240 14" preserveAspectRatio="none" role="img" aria-label="Frise sur 24 heures : éteint de '
        . e($off) . ' à ' . e($on) . '">'
        . '<rect class="tl-on" x="0" y="0" width="240" height="14" rx="7"/>';
    foreach ($segs as [$x, $y]) {
        $svg .= '<rect class="tl-off" x="' . round($x * $k, 2) . '" y="0" width="' . round(($y - $x) * $k, 2) . '" height="14"/>';
    }
    $n = ct_minutes(date('H:i'));
    return $svg . '<rect class="tl-now" x="' . round($n * $k - 0.75, 2) . '" y="-2" width="1.5" height="18" rx=".75"/></svg>'
        . '<div class="tl-scale"><span>0 h</span><span>6 h</span><span>12 h</span><span>18 h</span><span>24 h</span></div>';
}

/**
 * Compare la liste des CT à celle déjà connue : enregistre les nouveaux, oublie les supprimés
 * et prévient sur ntfy. Au tout premier passage, les CT existants sont enregistrés sans alerte.
 * @return array{new:array,gone:array}
 */
function ct_detect_changes(array $cts): array
{
    $pdo = db();
    $known = [];
    foreach ($pdo->query('SELECT vmid, name FROM ct_known') as $r) {
        $known[(int) $r['vmid']] = (string) $r['name'];
    }
    $first = !$known && setting('ct_known_init') !== '1';
    $now = time();
    $seen = [];
    $new = [];
    foreach ($cts as $ct) {
        $id = (int) $ct['vmid'];
        $name = (string) ($ct['name'] ?? '');
        $seen[$id] = true;
        if (!isset($known[$id])) {
            $pdo->prepare('INSERT INTO ct_known (vmid, name, first_seen) VALUES (?, ?, ?)')
                ->execute([$id, $name, $first ? 0 : $now]);
            if (!$first) {
                $new[$id] = $name;
            }
        } elseif ($known[$id] !== $name) {
            $pdo->prepare('UPDATE ct_known SET name = ? WHERE vmid = ?')->execute([$name, $id]);
        }
    }
    $gone = array_diff_key($known, $seen);
    foreach ($gone as $id => $name) {
        $pdo->prepare('DELETE FROM ct_known WHERE vmid = ?')->execute([$id]);
        // Plage gardée mais mise en pause : si le CT revient (droits rétablis…), il suffit de la réactiver.
        $pdo->prepare('UPDATE ct_schedule SET enabled = 0 WHERE vmid = ?')->execute([$id]);
    }
    if ($first) {
        set_setting('ct_known_init', '1');
    }
    foreach ($new as $id => $name) {
        log_event(null, "Nouveau conteneur détecté : CT $id « $name »");
        notify("Nouveau conteneur : CT $id", "« $name » vient d'apparaître dans Proxmox. Il est ajouté à la page Conteneurs du portail.", 3, ['new']);
    }
    foreach ($gone as $id => $name) {
        log_event(null, "Conteneur disparu : CT $id « $name »");
        notify("Conteneur supprimé : CT $id", "« $name » n'existe plus dans Proxmox (ou le portail n'a plus le droit de le voir).", 3, ['wastebasket']);
    }
    return ['new' => $new, 'gone' => $gone];
}

/** CT apparus depuis moins de 24 h. @return array<int,int> vmid => date d'apparition */
function ct_recent(): array
{
    $st = db()->prepare('SELECT vmid, first_seen FROM ct_known WHERE first_seen > ?');
    $st->execute([time() - 86400]);
    $out = [];
    foreach ($st as $r) {
        $out[(int) $r['vmid']] = (int) $r['first_seen'];
    }
    return $out;
}
