<?php
declare(strict_types=1);

// ─── Réglages du portail ────────────────────────────────────────────────
// Ne modifie pas ce fichier : il est remplacé à chaque mise à jour.
// Mets tes réglages dans config.local.php (à la racine du portail), par exemple :
//   <?php
//   const APP_NAME = 'Leo44';
//   const TRUSTED_PROXIES = '192.168.1.104';

$local = dirname(__DIR__) . '/config.local.php';
if (is_file($local)) {
    require $local;
}

// Fuseau horaire des dates affichées et des notifications.
defined('APP_TIMEZONE') || define('APP_TIMEZONE', 'Europe/Paris');
date_default_timezone_set(APP_TIMEZONE);

// Nom affiché en haut du tableau de bord et dans l'application d'authentification.
defined('APP_NAME')     || define('APP_NAME', 'Mon Portail');
defined('APP_SUBTITLE') || define('APP_SUBTITLE', 'Portail de liens');

// Adresse(s) IP du reverse proxy (Zoraxy, Nginx Proxy Manager…), séparées par des virgules.
// Seules ces adresses peuvent transmettre l'IP réelle du visiteur et le HTTPS.
// Laisser vide si Apache est exposé directement sur internet.
defined('TRUSTED_PROXIES') || define('TRUSTED_PROXIES', '');

// Durée de session : déconnexion après 30 min d'inactivité, 12 h au maximum.
defined('SESSION_IDLE') || define('SESSION_IDLE', 1800);
defined('SESSION_MAX')  || define('SESSION_MAX', 43200);

// Anti-force brute : nombre d'essais ratés autorisés par fenêtre de 15 minutes.
defined('LOGIN_WINDOW')       || define('LOGIN_WINDOW', 900);
defined('LOGIN_MAX_PER_IP')   || define('LOGIN_MAX_PER_IP', 10);
defined('LOGIN_MAX_PER_USER') || define('LOGIN_MAX_PER_USER', 5);
// Alerte ntfy à partir de ce nombre d'essais ratés sur un compte.
defined('LOGIN_ALERT_AFTER')  || define('LOGIN_ALERT_AFTER', 3);
// Attente imposée après chaque essai raté (millisecondes).
defined('LOGIN_DELAY_MS')     || define('LOGIN_DELAY_MS', 1000);
// Blocage progressif d'une IP : 15 min, puis 1 h, puis 24 h (et 24 h ensuite)…
defined('BAN_STEPS')          || define('BAN_STEPS', [900, 3600, 86400]);
// … on repart de 15 min si l'IP n'a pas été bloquée depuis 7 jours.
defined('BAN_RESET')          || define('BAN_RESET', 7 * 86400);

// Vérification de l'état des liens (pastille verte/rouge sur le tableau de bord).
defined('STATUS_CHECK')   || define('STATUS_CHECK', true);
defined('STATUS_CACHE')   || define('STATUS_CACHE', 120); // secondes
defined('STATUS_TIMEOUT') || define('STATUS_TIMEOUT', 4); // secondes par lien

// ─── Pilotage des conteneurs Proxmox (page « Conteneurs ») ───────────────
// À définir dans config.local.php (le secret du jeton ne doit JAMAIS être dans git) :
//   const PVE_URL   = 'https://192.168.1.203:8006';
//   const PVE_TOKEN = 'root@pam!portail=xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx';
defined('PVE_URL')        || define('PVE_URL', '');
defined('PVE_TOKEN')      || define('PVE_TOKEN', '');
defined('PVE_NODE')       || define('PVE_NODE', 'leo');
// Certificat Proxmox auto-signé : vérification désactivée par défaut (réseau local uniquement).
defined('PVE_VERIFY_TLS') || define('PVE_VERIFY_TLS', false);
// CT que le portail ne peut jamais arrêter ni planifier (le portail lui-même).
defined('PVE_PROTECTED')  || define('PVE_PROTECTED', [106]);
// CT dont l'arrêt demande une confirmation renforcée (ex. Zoraxy : coupe tous les sites).
defined('PVE_CONFIRM')    || define('PVE_CONFIRM', [104]);
// Délai laissé à un CT pour s'éteindre proprement avant l'arrêt forcé (secondes).
defined('PVE_SHUTDOWN_TIMEOUT') || define('PVE_SHUTDOWN_TIMEOUT', 60);
