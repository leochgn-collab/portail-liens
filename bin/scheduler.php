<?php
declare(strict_types=1);

// Planificateur des plages horaires des CT. Lancé chaque minute par cron, en www-data :
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
$now = date('H:i');
$rows = db()->query('SELECT * FROM ct_schedule WHERE enabled = 1')->fetchAll();
foreach ($rows as $row) {
    $vmid = (int) $row['vmid'];
    if (in_array($vmid, PVE_PROTECTED, true)) {
        continue;
    }
    $off = ct_should_be_off($row['off_time'], $row['on_time'], $now) ? 1 : 0;
    if ($row['last_state'] !== null && (int) $row['last_state'] === $off) {
        continue; // rien n'a changé depuis la dernière action
    }
    $err = pve_action($vmid, $off ? 'shutdown' : 'start');
    if ($err === null) {
        db()->prepare('UPDATE ct_schedule SET last_state = ? WHERE vmid = ?')->execute([$off, $vmid]);
        log_event(null, "CT $vmid " . ($off ? 'arrêté' : 'démarré') . ' (plage horaire)');
        notify('CT ' . $vmid, $off ? 'Arrêt programmé' : 'Démarrage programmé', 2, [$off ? 'zzz' : 'sunny']);
    } else {
        log_event(null, "CT $vmid : échec plage horaire — $err");
    }
}
