<?php
declare(strict_types=1);

// ─── Mot de passe oublié : envoi de mails et liens de réinitialisation ─────
// Réglages à mettre dans config.local.php (le mot de passe ne doit JAMAIS être dans git) :
//   const SMTP_HOST = 'ssl0.ovh.net';
//   const SMTP_PORT = 465;                       // 465 = SSL/TLS, 587 = STARTTLS
//   const SMTP_USER = 'noreply@leo44.cloud';
//   const SMTP_PASS = 'le mot de passe de la boîte';
//   const SMTP_FROM = 'noreply@leo44.cloud';
// L'adresse du portail (APP_URL, ou celle retenue automatiquement) sert à construire le lien envoyé.

defined('SMTP_HOST')      || define('SMTP_HOST', '');
defined('SMTP_PORT')      || define('SMTP_PORT', 465);
defined('SMTP_USER')      || define('SMTP_USER', '');
defined('SMTP_PASS')      || define('SMTP_PASS', '');
defined('SMTP_FROM')      || define('SMTP_FROM', SMTP_USER);
defined('SMTP_FROM_NAME') || define('SMTP_FROM_NAME', APP_NAME);
// Durée de validité d'un lien de réinitialisation (secondes).
defined('RESET_TTL')      || define('RESET_TTL', 1800);
// Nombre de demandes de lien autorisées par 15 minutes, par adresse IP et par compte.
defined('RESET_MAX_PER_IP')   || define('RESET_MAX_PER_IP', 5);
defined('RESET_MAX_PER_USER') || define('RESET_MAX_PER_USER', 3);

/** Ajoute la colonne e-mail des comptes et la table des liens (une seule fois). */
function reset_migrate(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $pdo = db();
    $cols = array_column($pdo->query('PRAGMA table_info(users)')->fetchAll(), 'name');
    if (!in_array('email', $cols, true)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN email TEXT NOT NULL DEFAULT ''");
    }
    $pdo->exec(<<<SQL
        CREATE TABLE IF NOT EXISTS password_resets (
            id         INTEGER PRIMARY KEY,
            user_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            token_hash TEXT NOT NULL UNIQUE,
            created_at INTEGER NOT NULL,
            expires_at INTEGER NOT NULL,
            ip         TEXT
        );
    SQL);
    $pdo->prepare('DELETE FROM password_resets WHERE expires_at < ?')->execute([time()]);
}

function mail_configured(): bool
{
    return SMTP_HOST !== '' && SMTP_USER !== '' && SMTP_PASS !== '' && valid_email(SMTP_FROM);
}

function valid_email(string $email): bool
{
    return strlen($email) <= 254 && !preg_match('/[\r\n<>]/', $email)
        && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Envoie un mail en texte brut par SMTP authentifié (TLS obligatoire, certificat vérifié).
 * Retourne null si c'est bon, sinon le message d'erreur.
 */
function send_mail(string $to, string $subject, string $text): ?string
{
    if (!mail_configured()) {
        return 'Envoi de mail non configuré (réglages SMTP_… dans config.local.php).';
    }
    if (!valid_email($to)) {
        return 'Adresse du destinataire invalide.';
    }
    $implicit = (int) SMTP_PORT === 465;
    $ctx = stream_context_create(['ssl' => [
        'verify_peer'      => true,
        'verify_peer_name' => true,
        'peer_name'        => SMTP_HOST,
    ]]);
    $fp = @stream_socket_client(($implicit ? 'ssl://' : 'tcp://') . SMTP_HOST . ':' . (int) SMTP_PORT,
        $errno, $errstr, 10, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) {
        return 'Serveur mail injoignable : ' . ($errstr ?: "erreur $errno");
    }
    stream_set_timeout($fp, 15);

    // Envoie une commande (sauf si vide) et vérifie le code de la réponse (réponses sur plusieurs lignes comprises).
    $cmd = static function (string $line, array $ok) use ($fp): void {
        if ($line !== '') {
            fwrite($fp, $line . "\r\n");
        }
        $code = 0;
        $reply = '';
        while (($l = fgets($fp, 2048)) !== false) {
            $reply .= $l;
            $code = (int) substr($l, 0, 3);
            if (strlen($l) < 4 || $l[3] !== '-') {
                break;
            }
        }
        if (!in_array($code, $ok, true)) {
            throw new RuntimeException(trim($reply) !== '' ? trim(preg_replace('/\s+/', ' ', $reply)) : 'pas de réponse du serveur');
        }
    };

    $domain = substr(strrchr(SMTP_FROM, '@'), 1);
    $b64 = static fn (string $s): string => '=?UTF-8?B?' . base64_encode($s) . '?=';
    $message = implode("\r\n", [
        'Date: ' . date('r'),
        'From: ' . $b64(SMTP_FROM_NAME) . ' <' . SMTP_FROM . '>',
        'To: <' . $to . '>',
        'Subject: ' . $b64($subject),
        'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . $domain . '>',
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: base64',
        'Auto-Submitted: auto-generated',
        '',
        rtrim(chunk_split(base64_encode(str_replace(["\r\n", "\n"], "\r\n", $text)), 76, "\r\n")),
    ]);

    try {
        $cmd('', [220]);
        $cmd('EHLO ' . $domain, [250]);
        if (!$implicit) {
            $cmd('STARTTLS', [220]);
            if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('impossible de chiffrer la connexion (STARTTLS)');
            }
            $cmd('EHLO ' . $domain, [250]);
        }
        $cmd('AUTH LOGIN', [334]);
        $cmd(base64_encode(SMTP_USER), [334]);
        $cmd(base64_encode(SMTP_PASS), [235]);
        $cmd('MAIL FROM:<' . SMTP_FROM . '>', [250]);
        $cmd('RCPT TO:<' . $to . '>', [250, 251]);
        $cmd('DATA', [354]);
        $cmd($message . "\r\n.", [250]);
        try {
            $cmd('QUIT', [221]);
        } catch (RuntimeException) {
            // Le mail est parti : une fin de connexion brutale n'est pas grave.
        }
        return null;
    } catch (RuntimeException $e) {
        return 'Envoi refusé : ' . mb_substr($e->getMessage(), 0, 300);
    } finally {
        fclose($fp);
    }
}

/**
 * Prépare un mail : il part une fois la page envoyée au visiteur (même principe que notify()),
 * pour que la réponse ne soit pas plus lente quand le compte existe.
 */
function mail_later(string $to, string $subject, string $text, ?int $userId = null): void
{
    static $queue = null;
    if ($queue === null) {
        $queue = new ArrayObject();
        register_shutdown_function(static function () use (&$queue): void {
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }
            foreach ($queue as [$to, $subject, $text, $uid]) {
                $err = send_mail($to, $subject, $text);
                if ($err !== null) {
                    log_event($uid, 'Mail non envoyé : ' . $err);
                }
            }
        });
    }
    $queue[] = [$to, $subject, $text, $userId];
}

function reset_token_hash(string $token): string
{
    return hash_hmac('sha256', 'reset|' . $token, app_key());
}

/** Crée un lien de réinitialisation (les précédents du compte ne marchent plus). Retourne le jeton en clair. */
function create_reset(int $userId): string
{
    $token = bin2hex(random_bytes(32));
    $pdo = db();
    $pdo->beginTransaction();
    $pdo->prepare('DELETE FROM password_resets WHERE user_id = ?')->execute([$userId]);
    $pdo->prepare('INSERT INTO password_resets (user_id, token_hash, created_at, expires_at, ip) VALUES (?, ?, ?, ?, ?)')
        ->execute([$userId, reset_token_hash($token), time(), time() + RESET_TTL, client_ip()]);
    $pdo->commit();
    return $token;
}

/** Lien encore valable : ['reset_id' => …, + colonnes du compte], ou null. */
function find_reset(string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        return null;
    }
    $st = db()->prepare('SELECT u.*, r.id AS reset_id FROM password_resets r JOIN users u ON u.id = r.user_id
                         WHERE r.token_hash = ? AND r.expires_at > ?');
    $st->execute([reset_token_hash($token), time()]);
    return $st->fetch() ?: null;
}
