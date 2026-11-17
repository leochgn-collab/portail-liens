<?php
declare(strict_types=1);

// ─── Réveil réseau (Wake-on-LAN) — page « Réveil » ───────────────────────
// Les appareils sont déclarés dans config.local.php (voir README, WOL_DEVICES).

/**
 * Appareils configurés, indexés par leur clé.
 * @return array<string, array{name: string, mac: string, ip: string, port: int, ssh: string}>
 */
function wol_devices(): array
{
    $out = [];
    foreach (WOL_DEVICES as $key => $d) {
        $mac = strtoupper(str_replace('-', ':', trim((string) ($d['mac'] ?? ''))));
        if (!preg_match('/^([0-9A-F]{2}:){5}[0-9A-F]{2}$/', $mac)) {
            continue; // adresse MAC invalide : appareil ignoré
        }
        $ssh = trim((string) ($d['ssh'] ?? ''));
        $out[(string) $key] = [
            'name' => (string) ($d['name'] ?? $key),
            'mac'  => $mac,
            'ip'   => (string) ($d['ip'] ?? ''),
            'port' => (int) ($d['port'] ?? 80),
            // Extinction : compte SSH « utilisateur@adresse » (vide = pas de bouton Éteindre)
            'ssh'  => preg_match('/^[A-Za-z0-9._-]+@[A-Za-z0-9.:-]+$/', $ssh) ? $ssh : '',
        ];
    }
    return $out;
}

/** L'appareil répond-il sur son port (interface web, SSH…) ? null si pas d'IP configurée. */
function wol_online(array $device): ?bool
{
    if ($device['ip'] === '' || !filter_var($device['ip'], FILTER_VALIDATE_IP)) {
        return null;
    }
    $f = @fsockopen($device['ip'], $device['port'], $errno, $errstr, WOL_CHECK_TIMEOUT);
    if ($f) {
        fclose($f);
        return true;
    }
    return false;
}

/** Envoie le paquet magique. Retourne null si tout va bien, sinon le message d'erreur. */
function wol_send(string $mac): ?string
{
    if (!function_exists('socket_create')) {
        return "L'extension PHP « sockets » est absente sur ce serveur.";
    }
    $bin = hex2bin(str_replace(':', '', $mac));
    if ($bin === false || strlen($bin) !== 6) {
        return 'Adresse MAC invalide.';
    }
    $paquet = str_repeat("\xFF", 6) . str_repeat($bin, 16);

    $sock = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
    if ($sock === false) {
        return "Impossible d'ouvrir une connexion réseau.";
    }
    socket_set_option($sock, SOL_SOCKET, SO_BROADCAST, 1);
    $envoyes = 0;
    foreach ([9, 7] as $port) { // ports Wake-on-LAN habituels
        if (@socket_sendto($sock, $paquet, strlen($paquet), 0, WOL_BROADCAST, $port) !== false) {
            $envoyes++;
        }
    }
    socket_close($sock);
    return $envoyes > 0 ? null : "L'envoi du signal a échoué (adresse de diffusion " . WOL_BROADCAST . ' ?).';
}

/** La clé SSH d'extinction est-elle prête ? */
function wol_ssh_ready(): bool
{
    return is_file(WOL_SSH_KEY) && is_readable(WOL_SSH_KEY) && function_exists('proc_open');
}

/**
 * Éteint l'appareil par SSH avec la clé dédiée du portail.
 * Côté appareil, la clé est limitée à la seule commande d'extinction (voir README).
 * Retourne null si la demande est partie, sinon le message d'erreur.
 */
function wol_shutdown(array $device): ?string
{
    if ($device['ssh'] === '') {
        return "L'extinction n'est pas configurée pour cet appareil.";
    }
    if (!wol_ssh_ready()) {
        return 'Clé SSH du portail introuvable (voir README, page « Réveil réseau »).';
    }
    // Tableau d'arguments : aucun shell n'est utilisé, rien ne peut être injecté.
    $cmd = [
        'ssh', '-i', WOL_SSH_KEY,
        '-o', 'BatchMode=yes',
        '-o', 'IdentitiesOnly=yes',
        '-o', 'ConnectTimeout=5',
        '-o', 'StrictHostKeyChecking=accept-new',
        '-o', 'UserKnownHostsFile=' . WOL_SSH_KNOWN_HOSTS,
        $device['ssh'],
        'poweroff', // ignorée si la clé a une commande imposée côté appareil
    ];
    $proc = @proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($proc)) {
        return "Impossible de lancer ssh (paquet openssh-client installé ?).";
    }
    fclose($pipes[0]);
    stream_set_timeout($pipes[2], 15);
    $err = trim((string) stream_get_contents($pipes[2]));
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);
    // 255 = erreur de connexion SSH. Un autre code peut venir de la coupure pendant l'extinction : c'est normal.
    // « Connection closed by… » : l'appareil s'est coupé pendant la réponse, donc l'ordre est passé.
    if ($code === 255 && !preg_match('/closed by|connection reset|broken pipe/i', $err)) {
        $ligne = $err !== '' ? strtok($err, "\n") : 'connexion refusée';
        return 'Connexion SSH impossible : ' . $ligne;
    }
    return null;
}
