<?php
declare(strict_types=1);

// Diagnostic des rappels. À lancer sur le serveur du portail :
//   runuser -u www-data -- php /var/www/portail-liens/bin/rappels-diag.php

if (PHP_SAPI !== 'cli') {
    exit(1);
}
$_SERVER['REMOTE_ADDR'] = 'console';
require dirname(__DIR__) . '/src/bootstrap.php';

$h = fn (?int $t) => $t ? date('d/m H:i:s', $t) : '-';
echo '== Heure du serveur : ', date('d/m/Y H:i:s T'), "\n";
echo '== Version : ', trim((string) shell_exec('git -c safe.directory=' . escapeshellarg(ROOT) . ' -C ' . escapeshellarg(ROOT) . ' log -1 --format="%h %cd" --date=format:"%d/%m %H:%M" 2>/dev/null')), "\n";
echo '== Cron : ', is_file('/etc/cron.d/portail-ct') ? str_replace("\n", ' | ', trim((string) file_get_contents('/etc/cron.d/portail-ct'))) : 'FICHIER ABSENT', "\n";
echo '== Service cron : ', trim((string) shell_exec('systemctl is-active cron 2>&1')), "\n";
echo '== ntfy : ', ntfy_configured() ? setting('ntfy_url') . ' · sujet portail « ' . setting('ntfy_topic') . ' »' : 'NON CONFIGURÉ', "\n";
echo '== Adresse du portail pour les boutons : ', portal_url() ?: '(inconnue)', "\n\n";

foreach (db()->query(RAPPEL_SELECT . ' ORDER BY r.id DESC LIMIT 8')->fetchAll() as $r) {
    printf("#%d « %s » %s | actif=%d | sujet=%s | prochain=%s | envoyé=%s | en attente depuis=%s | relances=%d (prochaine %s) | report=%s | silence=%s | erreur=%s\n",
        $r['id'], $r['title'], rappel_summary($r), $r['enabled'], rappel_topic($r) ?? setting('ntfy_topic'),
        $h($r['next_at'] ? (int) $r['next_at'] : null), $h($r['last_sent_at'] ? (int) $r['last_sent_at'] : null),
        $h($r['pending_since'] ? (int) $r['pending_since'] : null), $r['nag_count'], $h($r['nag_next'] ? (int) $r['nag_next'] : null),
        $h($r['snooze_at'] ? (int) $r['snooze_at'] : null), $r['quiet_start'] ? $r['quiet_start'] . '-' . $r['quiet_end'] : 'non', $r['last_error'] ?: '-');
}
echo "\n== Journal (rappels) :\n";
foreach (db()->query("SELECT * FROM journal WHERE event LIKE '%appel%' ORDER BY id DESC LIMIT 15")->fetchAll() as $j) {
    echo '  ', $h((int) $j['at']), ' · ', $j['event'], ' · IP ', $j['ip'], "\n";
}
echo "\n== Test d'envoi direct à ntfy : ";
echo ntfy_send('Diagnostic des rappels', 'Si tu lis ceci, le portail joint bien ntfy.', 3, ['wrench'], rappel_topic(['user_topic' => db()->query('SELECT rappel_topic FROM users ORDER BY id LIMIT 1')->fetchColumn()])) ?? 'OK', "\n";
