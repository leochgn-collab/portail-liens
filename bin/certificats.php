<?php
declare(strict_types=1);

// Contrôle horaire des certificats HTTPS (cron, en www-data) :
//   17 * * * * www-data php /var/www/portail-liens/bin/certificats.php

if (PHP_SAPI !== 'cli') {
    exit(1);
}
$_SERVER['REMOTE_ADDR'] = 'console';
require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/certificats.php';

cert_migrate();
cert_run_all();
