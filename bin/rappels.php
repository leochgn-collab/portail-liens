<?php
declare(strict_types=1);

// Envoi des rappels de la page « Rappels ». Lancé chaque minute par cron, en www-data :
//   * * * * * www-data php /var/www/portail-liens/bin/rappels.php

if (PHP_SAPI !== 'cli') {
    exit(1);
}
$_SERVER['REMOTE_ADDR'] = 'console';
require dirname(__DIR__) . '/src/bootstrap.php';

if (ntfy_configured()) {
    rappels_run(time());
}
