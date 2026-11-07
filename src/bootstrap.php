<?php
declare(strict_types=1);

define('ROOT', dirname(__DIR__));
define('DATA_DIR', ROOT . '/data');

require __DIR__ . '/config.php';
require __DIR__ . '/totp.php';

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('expose_php', '0');

// Seules les méthodes GET, HEAD et POST sont utiles au portail : les autres (PUT, DELETE…) sont refusées.
if (PHP_SAPI !== 'cli' && !in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD', 'POST'], true)) {
    header('Allow: GET, HEAD, POST');
    http_response_code(405);
    exit('Méthode non autorisée.');
}

// ─── Base de données (SQLite, hors de la racine web) ───────────────────

function db(): PDO
{
    static $pdo = null;
    if ($pdo) {
        return $pdo;
    }
    if (!is_dir(DATA_DIR)) {
        mkdir(DATA_DIR, 0700, true);
    }
    $pdo = new PDO('sqlite:' . DATA_DIR . '/portail.sqlite', null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA busy_timeout = 5000');
    migrate($pdo);
    return $pdo;
}

function migrate(PDO $pdo): void
{
    $pdo->exec(<<<SQL
        CREATE TABLE IF NOT EXISTS users (
            id              INTEGER PRIMARY KEY,
            username        TEXT NOT NULL UNIQUE COLLATE NOCASE,
            password_hash   TEXT NOT NULL,
            totp_secret     TEXT,
            totp_enabled    INTEGER NOT NULL DEFAULT 0,
            totp_last_step  INTEGER NOT NULL DEFAULT 0,
            is_admin        INTEGER NOT NULL DEFAULT 0,
            must_change_pwd INTEGER NOT NULL DEFAULT 0,
            pwd_version     INTEGER NOT NULL DEFAULT 1,
            last_login_at   INTEGER,
            last_login_ip   TEXT,
            created_at      INTEGER NOT NULL
        );
        CREATE TABLE IF NOT EXISTS recovery_codes (
            id        INTEGER PRIMARY KEY,
            user_id   INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            code_hash TEXT NOT NULL,
            used_at   INTEGER
        );
        CREATE TABLE IF NOT EXISTS attempts (
            id   INTEGER PRIMARY KEY,
            kind TEXT NOT NULL,
            key  TEXT NOT NULL,
            at   INTEGER NOT NULL
        );
        CREATE INDEX IF NOT EXISTS attempts_idx ON attempts(kind, key, at);
        CREATE TABLE IF NOT EXISTS categories (
            id       INTEGER PRIMARY KEY,
            name     TEXT NOT NULL,
            position INTEGER NOT NULL DEFAULT 0
        );
        CREATE TABLE IF NOT EXISTS links (
            id          INTEGER PRIMARY KEY,
            category_id INTEGER REFERENCES categories(id) ON DELETE SET NULL,
            title       TEXT NOT NULL,
            url         TEXT NOT NULL,
            description TEXT NOT NULL DEFAULT '',
            icon        TEXT NOT NULL DEFAULT '',
            position    INTEGER NOT NULL DEFAULT 0
        );
        CREATE TABLE IF NOT EXISTS notes (
            id          INTEGER PRIMARY KEY,
            category_id INTEGER REFERENCES categories(id) ON DELETE SET NULL,
            title       TEXT NOT NULL DEFAULT '',
            body        TEXT NOT NULL,
            position    INTEGER NOT NULL DEFAULT 0,
            updated_at  INTEGER NOT NULL
        );
        CREATE TABLE IF NOT EXISTS journal (
            id      INTEGER PRIMARY KEY,
            user_id INTEGER,
            event   TEXT NOT NULL,
            ip      TEXT,
            at      INTEGER NOT NULL
        );
        CREATE TABLE IF NOT EXISTS settings (
            key   TEXT PRIMARY KEY,
            value TEXT NOT NULL
        );
        CREATE TABLE IF NOT EXISTS bans (
            ip      TEXT PRIMARY KEY,
            level   INTEGER NOT NULL,
            until   INTEGER NOT NULL,
            last_at INTEGER NOT NULL
        );
    SQL);

    // Mise à jour : type d'accès des liens (public / privé).
    $cols = array_column($pdo->query('PRAGMA table_info(links)')->fetchAll(), 'name');
    if (!in_array('kind', $cols, true)) {
        $pdo->exec("ALTER TABLE links ADD COLUMN kind TEXT NOT NULL DEFAULT 'public'");
        foreach ($pdo->query('SELECT id, url FROM links')->fetchAll() as $l) {
            $pdo->prepare('UPDATE links SET kind = ? WHERE id = ?')
                ->execute([is_private_host((string) parse_url($l['url'], PHP_URL_HOST)) ? 'private' : 'public', $l['id']]);
        }
    }
}

// ─── Chiffrement des secrets 2FA (libsodium) ───────────────────────────

function app_key(): string
{
    static $key = null;
    if ($key !== null) {
        return $key;
    }
    $file = DATA_DIR . '/secret.key';
    if (!is_file($file)) {
        if (!is_dir(DATA_DIR)) {
            mkdir(DATA_DIR, 0700, true);
        }
        $old = umask(0077);
        file_put_contents($file, bin2hex(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES)), LOCK_EX);
        umask($old);
    }
    $key = hex2bin(trim((string) file_get_contents($file)));
    if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
        throw new RuntimeException('Clé data/secret.key invalide.');
    }
    return $key;
}

function encrypt_secret(string $plain): string
{
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    return base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, app_key()));
}

function decrypt_secret(?string $stored): ?string
{
    if ($stored === null || $stored === '') {
        return null;
    }
    $raw = base64_decode($stored, true);
    $n   = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
    if ($raw === false || strlen($raw) <= $n) {
        return null;
    }
    $plain = sodium_crypto_secretbox_open(substr($raw, $n), substr($raw, 0, $n), app_key());
    return $plain === false ? null : $plain;
}

// ─── Utilitaires HTTP ──────────────────────────────────────────────────

function e(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** La requête arrive-t-elle par le reverse proxy de confiance (Zoraxy…) ? */
function from_trusted_proxy(): bool
{
    $list = array_filter(array_map('trim', explode(',', TRUSTED_PROXIES)));
    return $list && in_array((string) ($_SERVER['REMOTE_ADDR'] ?? ''), $list, true);
}

function is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        return true;
    }
    // Derrière le proxy, le HTTPS est géré par lui : on le suppose sauf indication contraire.
    return from_trusted_proxy() && strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? 'https')) !== 'http';
}

function client_ip(): string
{
    $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    if (!from_trusted_proxy()) {
        return $remote;
    }
    $real = trim((string) ($_SERVER['HTTP_X_REAL_IP'] ?? ''));
    if ($real !== '' && filter_var($real, FILTER_VALIDATE_IP)) {
        return $real;
    }
    // Dernière adresse ajoutée à X-Forwarded-For : celle écrite par le proxy, pas par le visiteur.
    $chain = array_filter(array_map('trim', explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''))));
    $last = end($chain);
    return $last && filter_var($last, FILTER_VALIDATE_IP) ? $last : $remote;
}

function redirect(string $path): never
{
    header('Location: ' . $path, true, 303);
    exit;
}

function csp_nonce(): string
{
    static $nonce = null;
    return $nonce ??= base64_encode(random_bytes(16));
}

function send_security_headers(): void
{
    $nonce = csp_nonce();
    header("Content-Security-Policy: default-src 'none'; script-src 'self' 'nonce-$nonce'; style-src 'self'; "
        . "img-src 'self' data:; connect-src 'self'; font-src 'self'; form-action 'self'; "
        . "frame-ancestors 'none'; base-uri 'none'");
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
    header('Cross-Origin-Opener-Policy: same-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header('Cache-Control: no-store, max-age=0');
    if (is_https()) {
        header('Strict-Transport-Security: max-age=31536000');
    }
    header_remove('X-Powered-By');
}

// ─── Session ───────────────────────────────────────────────────────────

function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $https = is_https();
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    ini_set('session.gc_maxlifetime', (string) SESSION_MAX);
    // Sessions rangées dans data/sessions (Debian nettoie sinon les sessions au bout de 24 min).
    $dir = DATA_DIR . '/sessions';
    if (!is_dir($dir)) {
        mkdir($dir, 0700, true);
    }
    session_save_path($dir);
    ini_set('session.gc_probability', '1');
    ini_set('session.gc_divisor', '100');
    session_name($https ? '__Host-portail' : 'portail');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();

    $now = time();
    $_SESSION['created'] ??= $now;
    $expired = isset($_SESSION['last'])
        && ($now - $_SESSION['last'] > SESSION_IDLE || $now - $_SESSION['created'] > SESSION_MAX);
    if ($expired) {
        $_SESSION = ['created' => $now, 'flash' => 'Session expirée, reconnecte-toi.'];
        session_regenerate_id(true);
    }
    $_SESSION['last'] = $now;
}

function flash(?string $msg = null): ?string
{
    if ($msg !== null) {
        $_SESSION['flash'] = $msg;
        return null;
    }
    $m = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $m;
}

// ─── CSRF ──────────────────────────────────────────────────────────────

function csrf_token(): string
{
    return $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function require_post_csrf(): void
{
    $attendu = (string) ($_SESSION['csrf'] ?? '');
    $recu    = (string) ($_POST['csrf'] ?? '');
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST'
        || strlen($attendu) !== 64 || !hash_equals($attendu, $recu)) {
        http_response_code(400);
        exit('Requête refusée (jeton expiré). Recharge la page et réessaie.');
    }
}

// ─── Limitation des essais ─────────────────────────────────────────────

function too_many_attempts(string $kind, string $key, int $max): bool
{
    $since = time() - LOGIN_WINDOW;
    db()->prepare('DELETE FROM attempts WHERE at < ?')->execute([time() - 86400]);
    $st = db()->prepare('SELECT COUNT(*) FROM attempts WHERE kind = ? AND key = ? AND at >= ?');
    $st->execute([$kind, $key, $since]);
    return (int) $st->fetchColumn() >= $max;
}

function record_attempt(string $kind, string $key): void
{
    db()->prepare('INSERT INTO attempts (kind, key, at) VALUES (?, ?, ?)')->execute([$kind, $key, time()]);
}

function clear_attempts(string $kind, string $key): void
{
    db()->prepare('DELETE FROM attempts WHERE kind = ? AND key = ?')->execute([$kind, $key]);
}

function log_event(?int $userId, string $event): void
{
    db()->prepare('INSERT INTO journal (user_id, event, ip, at) VALUES (?, ?, ?, ?)')
        ->execute([$userId, $event, client_ip(), time()]);
    if (random_int(1, 50) === 1) { // ménage : on garde 1 an de journal
        db()->prepare('DELETE FROM journal WHERE at < ?')->execute([time() - 365 * 86400]);
    }
}

// ─── Réglages enregistrés en base (modifiables dans l'administration) ───

function setting(string $key, string $default = ''): string
{
    $st = db()->prepare('SELECT value FROM settings WHERE key = ?');
    $st->execute([$key]);
    $v = $st->fetchColumn();
    return $v === false ? $default : (string) $v;
}

function set_setting(string $key, string $value): void
{
    db()->prepare('INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value')
        ->execute([$key, $value]);
}

// ─── Notifications ntfy ────────────────────────────────────────────────

function ntfy_configured(): bool
{
    return setting('ntfy_url') !== '' && setting('ntfy_topic') !== '';
}

/** Envoie tout de suite une notification. Retourne null si c'est bon, sinon le message d'erreur. */
function ntfy_send(string $title, string $message, int $priority = 3, array $tags = []): ?string
{
    if (!ntfy_configured()) {
        return 'Serveur ntfy non configuré.';
    }
    $headers = ['Content-Type: application/json'];
    $token = decrypt_secret(setting('ntfy_token')) ?? '';
    if ($token !== '') {
        $headers[] = str_contains($token, ':')
            ? 'Authorization: Basic ' . base64_encode($token)   // identifiant:mot de passe
            : 'Authorization: Bearer ' . $token;               // jeton d'accès tk_…
    }
    $ch = curl_init(rtrim(setting('ntfy_url'), '/') . '/');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode([
            'topic'    => setting('ntfy_topic'),
            'title'    => $title,
            'message'  => $message,
            'priority' => $priority,
            'tags'     => $tags,
        ], JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err  = curl_error($ch);
    if ($body === false) {
        return 'Serveur injoignable : ' . $err;
    }
    if ($code === 401 || $code === 403) {
        return "Accès refusé par ntfy (code $code) : vérifie le jeton ou les droits sur le sujet.";
    }
    return $code >= 200 && $code < 300 ? null : "Réponse inattendue de ntfy (code $code).";
}

/**
 * Prépare une notification : elle part une fois la page envoyée au visiteur,
 * pour ne pas ralentir la connexion.
 */
function notify(string $title, string $message, int $priority = 3, array $tags = []): void
{
    static $queue = null;
    if (!ntfy_configured()) {
        return;
    }
    if ($queue === null) {
        $queue = new ArrayObject();
        register_shutdown_function(static function () use (&$queue): void {
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close(); // la session doit être enregistrée avant de libérer le visiteur
            }
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }
            foreach ($queue as $n) {
                ntfy_send(...$n);
            }
        });
    }
    $queue[] = [$title, $message . "\nIP : " . client_ip() . ' · ' . date('d/m/Y H:i'), $priority, $tags];
}

// ─── Blocage progressif des adresses IP ────────────────────────────────

function human_duration(int $s): string
{
    if ($s >= 86400) {
        $n = (int) ceil($s / 86400);
        return $n . ' jour' . ($n > 1 ? 's' : '');
    }
    if ($s >= 3600) {
        $n = (int) ceil($s / 3600);
        return $n . ' heure' . ($n > 1 ? 's' : '');
    }
    $n = max(1, (int) ceil($s / 60));
    return $n . ' minute' . ($n > 1 ? 's' : '');
}

/** Secondes restantes si l'IP est bloquée, sinon 0. */
function ip_ban_remaining(string $ip): int
{
    $st = db()->prepare('SELECT until FROM bans WHERE ip = ?');
    $st->execute([$ip]);
    return max(0, (int) $st->fetchColumn() - time());
}

/** Bloque l'IP, chaque fois plus longtemps (15 min, puis 1 h, puis 24 h). Retourne la durée. */
function ban_ip(string $ip): int
{
    $now = time();
    $st = db()->prepare('SELECT level, last_at FROM bans WHERE ip = ?');
    $st->execute([$ip]);
    $row = $st->fetch();
    // On repart du premier niveau si l'IP n'a pas été bloquée depuis BAN_RESET secondes.
    $level = $row && $now - (int) $row['last_at'] < BAN_RESET ? (int) $row['level'] + 1 : 1;
    $steps = BAN_STEPS;
    $duration = (int) $steps[min($level, count($steps)) - 1];
    db()->prepare('INSERT INTO bans (ip, level, until, last_at) VALUES (?, ?, ?, ?)
                   ON CONFLICT(ip) DO UPDATE SET level = excluded.level, until = excluded.until, last_at = excluded.last_at')
        ->execute([$ip, $level, $now + $duration, $now]);
    clear_attempts('ip', $ip);
    log_event(null, 'Adresse IP bloquée ' . human_duration($duration) . " (blocage n°$level)");
    notify('IP bloquée ' . human_duration($duration), "Trop d'essais ratés sur le portail (blocage n°$level).",
        $level >= 2 ? 5 : 4, ['no_entry']);
    return $duration;
}

function unban_ip(string $ip): void
{
    db()->prepare('DELETE FROM bans WHERE ip = ?')->execute([$ip]);
    clear_attempts('ip', $ip);
}

/** Arrête la page si l'IP du visiteur est bloquée. */
function stop_if_banned(): void
{
    $left = ip_ban_remaining(client_ip());
    if ($left > 0) {
        http_response_code(429);
        header('Retry-After: ' . $left);
        exit('Trop de tentatives depuis ton adresse. Réessaie dans ' . human_duration($left) . '.');
    }
}

/**
 * Enregistre un essai raté (mot de passe ou code 2FA) : compteurs, ralentissement,
 * blocage progressif de l'IP et alertes ntfy.
 */
function login_failed(string $kind, string $key, ?array $user, string $what): void
{
    $ip = client_ip();
    record_attempt($kind, $key);
    record_attempt('ip', $ip);
    log_event($user ? (int) $user['id'] : null, $what);

    $since = time() - LOGIN_WINDOW;
    $st = db()->prepare('SELECT COUNT(*) FROM attempts WHERE kind = ? AND key = ? AND at >= ?');
    $st->execute([$kind, $key, $since]);
    $count = (int) $st->fetchColumn();

    if ($user) {
        $name = $user['username'];
        if ($kind === '2fa' && $count === 1) {
            notify("Code 2FA faux pour « $name »", 'Le mot de passe était correct, mais le code à 6 chiffres est faux. '
                . "Si ce n'est pas toi, change ton mot de passe.", 4, ['warning']);
        } elseif ($count === LOGIN_ALERT_AFTER) {
            notify("$count essais ratés pour « $name »", $what . '.', 4, ['warning']);
        }
        if ($count === LOGIN_MAX_PER_USER) {
            notify("Compte « $name » bloqué 15 min", 'Trop d\'essais ratés.', 4, ['lock']);
        }
    }

    $st->execute(['ip', $ip, $since]);
    if ((int) $st->fetchColumn() >= LOGIN_MAX_PER_IP) {
        ban_ip($ip);
    }

    usleep(LOGIN_DELAY_MS * 1000); // ralentit les robots
}

// ─── Utilisateurs et authentification ──────────────────────────────────

function password_algo(): string|int
{
    return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
}

function password_problem(string $pwd): ?string
{
    if (mb_strlen($pwd) < 12) {
        return 'Le mot de passe doit faire au moins 12 caractères.';
    }
    if (mb_strlen($pwd) > 200) {
        return 'Mot de passe trop long.';
    }
    return null;
}

function find_user(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM users WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/** Utilisateur entièrement connecté (mot de passe + 2FA), ou null. */
function current_user(): ?array
{
    start_session();
    $uid = $_SESSION['uid'] ?? null;
    if (!$uid) {
        return null;
    }
    $user = find_user((int) $uid);
    // Session invalidée si le compte a été supprimé ou si le mot de passe a changé ailleurs.
    if (!$user || (int) $user['pwd_version'] !== (int) ($_SESSION['pwd_version'] ?? -1) || !$user['totp_enabled']) {
        logout_session();
        return null;
    }
    return $user;
}

function require_login(): array
{
    $user = current_user();
    if (!$user) {
        redirect('/login.php');
    }
    if ($user['must_change_pwd'] && basename($_SERVER['SCRIPT_NAME']) !== 'compte.php') {
        flash('Choisis un nouveau mot de passe avant de continuer.');
        redirect('/compte.php');
    }
    return $user;
}

function require_admin(): array
{
    $user = require_login();
    if (!$user['is_admin']) {
        http_response_code(403);
        exit('Accès réservé aux administrateurs.');
    }
    return $user;
}

/** Termine la connexion une fois le mot de passe ET le code 2FA validés. */
function complete_login(array $user, string $to = '/', array $extra = []): never
{
    session_regenerate_id(true);
    $created = time();
    $_SESSION = [
        'uid'         => (int) $user['id'],
        'pwd_version' => (int) $user['pwd_version'],
        'created'     => $created,
        'last'        => $created,
        'prev_login'  => $user['last_login_at'] ? (int) $user['last_login_at'] : null,
        'prev_ip'     => $user['last_login_ip'],
    ] + $extra;
    db()->prepare('UPDATE users SET last_login_at = ?, last_login_ip = ? WHERE id = ?')
        ->execute([$created, client_ip(), $user['id']]);
    log_event((int) $user['id'], 'Connexion réussie');
    if (setting('ntfy_notify_login', '1') === '1') {
        notify("Connexion de « {$user['username']} »", 'Connexion réussie au portail.', 3, ['white_check_mark']);
    }
    redirect($to);
}

function logout_session(): void
{
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
}

/** Utilisateur ayant validé son mot de passe mais pas encore la 2FA (5 min max). */
function pending_user(): ?array
{
    start_session();
    $uid = $_SESSION['pre_uid'] ?? null;
    if (!$uid || time() - (int) ($_SESSION['pre_at'] ?? 0) > 900) {
        unset($_SESSION['pre_uid'], $_SESSION['pre_at']);
        return null;
    }
    return find_user((int) $uid);
}

// ─── Codes de secours ──────────────────────────────────────────────────

function hash_recovery(string $code): string
{
    $norm = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code));
    return hash_hmac('sha256', $norm, app_key());
}

/** @return string[] codes en clair, à afficher une seule fois */
function regenerate_recovery_codes(int $userId): array
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $codes = [];
    $pdo = db();
    $pdo->beginTransaction();
    $pdo->prepare('DELETE FROM recovery_codes WHERE user_id = ?')->execute([$userId]);
    $ins = $pdo->prepare('INSERT INTO recovery_codes (user_id, code_hash) VALUES (?, ?)');
    for ($i = 0; $i < 8; $i++) {
        $c = '';
        for ($j = 0; $j < 10; $j++) {
            $c .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        $code = substr($c, 0, 5) . '-' . substr($c, 5);
        $codes[] = $code;
        $ins->execute([$userId, hash_recovery($code)]);
    }
    $pdo->commit();
    return $codes;
}

function use_recovery_code(int $userId, string $code): bool
{
    $st = db()->prepare('UPDATE recovery_codes SET used_at = ? WHERE user_id = ? AND code_hash = ? AND used_at IS NULL');
    $st->execute([time(), $userId, hash_recovery($code)]);
    return $st->rowCount() === 1;
}

function remaining_recovery_codes(int $userId): int
{
    $st = db()->prepare('SELECT COUNT(*) FROM recovery_codes WHERE user_id = ? AND used_at IS NULL');
    $st->execute([$userId]);
    return (int) $st->fetchColumn();
}

// ─── Liens ─────────────────────────────────────────────────────────────

function valid_url(string $url): bool
{
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return false;
    }
    $port = parse_url($url, PHP_URL_PORT);
    if ($port !== null && ($port < 1 || $port > 65535)) {
        return false;
    }
    return in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true);
}

/** Adresse du réseau local : IP privée, localhost, .local/.lan/.home, ou nom sans point. */
function is_private_host(string $host): bool
{
    $host = strtolower(trim($host, '[]'));
    if ($host === '') {
        return false;
    }
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        return !filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)
            || (($n = ip2long($host)) !== false && ($n & 0xFFC00000) === 0x64400000); // 100.64/10 : Tailscale
    }
    return $host === 'localhost'
        || !str_contains($host, '.')
        || (bool) preg_match('/\.(local|lan|home|internal|localdomain|home\.arpa)$/', $host);
}

/**
 * Complète une adresse saisie : « 192.168.1.10:8080 » → « http://192.168.1.10:8080 »,
 * « mail.leo44.cloud » → « https://mail.leo44.cloud ».
 */
function normalize_url(string $input): string
{
    $url = trim($input);
    if ($url === '' || preg_match('#^[a-z][a-z0-9+.-]*://#i', $url)) {
        return $url;
    }
    $host = (string) parse_url('http://' . $url, PHP_URL_HOST);
    return (is_private_host($host) ? 'http://' : 'https://') . $url;
}

function link_host(string $url): string
{
    $host = (string) parse_url($url, PHP_URL_HOST);
    $port = parse_url($url, PHP_URL_PORT);
    $path = rtrim((string) parse_url($url, PHP_URL_PATH), '/');
    return preg_replace('/^www\./', '', $host) . ($port ? ':' . $port : '') . $path;
}

require __DIR__ . '/layout.php';
