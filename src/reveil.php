<?php
declare(strict_types=1);

// ─── Réveil réseau (Wake-on-LAN) — page « Réveil » ───────────────────────
// Les appareils sont déclarés dans config.local.php (voir README, WOL_DEVICES).

/**
 * Appareils configurés, indexés par leur clé.
 * @return array<string, array{name: string, mac: string, ip: string, port: int}>
 */
function wol_devices(): array
{
    $out = [];
    foreach (WOL_DEVICES as $key => $d) {
        $mac = strtoupper(str_replace('-', ':', trim((string) ($d['mac'] ?? ''))));
        if (!preg_match('/^([0-9A-F]{2}:){5}[0-9A-F]{2}$/', $mac)) {
            continue; // adresse MAC invalide : appareil ignoré
        }
        $out[(string) $key] = [
            'name' => (string) ($d['name'] ?? $key),
            'mac'  => $mac,
            'ip'   => (string) ($d['ip'] ?? ''),
            'port' => (int) ($d['port'] ?? 80),
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
