<?php
declare(strict_types=1);

// Planificateur des plages horaires des CT et VM, et détection des nouveaux. Lancé chaque minute par cron, en www-data :
//   * * * * * www-data php /var/www/portail-liens/bin/scheduler.php
// Il n'agit qu'aux changements de plage (arrêt à l'heure d'extinction, démarrage à l'heure
// d'allumage) : un démarrage ou un arrêt manuel entre deux reste donc respecté.

if (PHP_SAPI !== 'cli') {
    exit(1);
}
$_SERVER['REMOTE_ADDR'] = 'console';
require dirname(__DIR__) . '/src/bootstrap.php';

if (!pve_configured()) {
    exit(0);
}
// Détection des CT créés ou supprimés, et état actuel de chaque CT.
$list = pve_list_cts();
if ($list['error'] !== null) {
    exit(0); // Proxmox injoignable : on n'agit pas à l'aveugle, on réessaiera dans une minute
}
ct_detect_changes($list['cts']);
$status = [];
$types = [];
foreach ($list['cts'] as $ct) {
    $status[(int) $ct['vmid']] = (string) ($ct['status'] ?? '');
    $types[(int) $ct['vmid']] = (string) $ct['type'];
}

$now = date('H:i');
$rows = db()->query('SELECT * FROM ct_schedule WHERE enabled = 1')->fetchAll();
foreach ($rows as $row) {
    $vmid = (int) $row['vmid'];
    if (in_array($vmid, PVE_PROTECTED, true) || !isset($status[$vmid])) {
        continue;
    }
    $off = ct_should_be_off($row['off_time'], $row['on_time'], $now) ? 1 : 0;
    if ($row['last_state'] !== null && (int) $row['last_state'] === $off) {
        continue; // rien n'a changé depuis la dernière action
    }
    $mark = static function () use ($vmid, $off): void {
        db()->prepare('UPDATE ct_schedule SET last_state = ? WHERE vmid = ?')->execute([$off, $vmid]);
        set_setting("ct_fail_$vmid", '');
    };

    // Déjà dans l'état voulu (allumé à la main, déjà éteint…) : rien à faire, on le note.
    $running = $status[$vmid] === 'running';
    if ($off ? !$running : $running) {
        $mark();
        continue;
    }

    $kind = $types[$vmid] === 'qemu' ? 'VM' : 'CT';
    $err = pve_action($vmid, $off ? 'shutdown' : 'start', $types[$vmid]);
    if ($err === null) {
        $mark();
        log_event(null, "$kind $vmid " . ($off ? 'arrêté' : 'démarré') . ($kind === 'VM' ? 'e' : '') . ' (plage horaire)');
        notify("$kind $vmid", $off ? 'Arrêt programmé' : 'Démarrage programmé', 2, [$off ? 'zzz' : 'sunny']);
    } elseif (setting("ct_fail_$vmid") !== $err) {
        // Une seule ligne au journal et une seule alerte par erreur, pas une par minute.
        set_setting("ct_fail_$vmid", $err);
        log_event(null, "$kind $vmid : échec plage horaire — $err (nouvel essai chaque minute)");
        notify("$kind $vmid : échec de la plage horaire", $err, 4, ['warning']);
    }
}
