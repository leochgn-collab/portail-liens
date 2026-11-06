<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';

start_session();
require_post_csrf();
$uid = $_SESSION['uid'] ?? null;
if ($uid) {
    log_event((int) $uid, 'Déconnexion');
}
logout_session();
flash('Tu es déconnecté.');
redirect('/login.php');
