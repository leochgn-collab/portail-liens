<?php
declare(strict_types=1);

// ─── Certificats HTTPS (page « Certificats ») ───────────────────────────
// Vérifie la date d'expiration et la validité des certificats de tes sous-domaines.
// La connexion passe par le reverse proxy (Zoraxy) sur le réseau local, avec le nom du site
// annoncé (SNI) : on voit exactement le certificat que reçoivent les visiteurs.

require_once __DIR__ . '/motdepasse.php'; // send_mail(), valid_email()

/** Paliers d'alerte : nombre de jours restants. */
const CERT_PALIERS = [14, 7, 3, 1, 0];

function cert_migrate(): void
{
    db()->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS cert_hosts (
            id          INTEGER PRIMARY KEY,
            host        TEXT NOT NULL UNIQUE COLLATE NOCASE,
            port        INTEGER NOT NULL DEFAULT 443,
            status      TEXT NOT NULL DEFAULT 'new',
            expires_at  INTEGER,
            issued_at   INTEGER,
            issuer      TEXT NOT NULL DEFAULT '',
            names       TEXT NOT NULL DEFAULT '',
            error       TEXT NOT NULL DEFAULT '',
            checked_at  INTEGER,
            alert_level INTEGER,
            fail_count  INTEGER NOT NULL DEFAULT 0,
            created_at  INTEGER NOT NULL
        );
    SQL);
}

/** Nom de domaine valide (pas d'adresse IP, pas de port). */
function cert_valid_host(string $h): bool
{
    return strlen($h) <= 253
        && (bool) preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $h);
}

/** Adresse à laquelle se connecter : CERT_CONNECT, sinon le proxy de confiance, sinon le DNS. */
function cert_target(string $host): string
{
    if (CERT_CONNECT !== '') {
        return CERT_CONNECT;
    }
    $proxy = trim(explode(',', (string) TRUSTED_PROXIES)[0]);
    return filter_var($proxy, FILTER_VALIDATE_IP) ? $proxy : $host;
}

/**
 * Ouvre une connexion TLS et récupère le certificat présenté.
 * @return array{cert:mixed,error:string}
 */
function cert_probe(string $host, int $port, bool $verify): array
{
    $target = cert_target($host);
    $ssl = [
        'capture_peer_cert' => true,
        'SNI_enabled'       => true,
        'peer_name'         => $host,
        'verify_peer'       => $verify,
        'verify_peer_name'  => $verify,
        'allow_self_signed' => !$verify,
    ];
    if ($verify && CERT_CAFILE !== '') {
        $ssl['cafile'] = CERT_CAFILE;
    }
    $ctx = stream_context_create(['ssl' => $ssl]);
    $addr = (filter_var($target, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? "[$target]" : $target) . ':' . $port;
    // OpenSSL donne la vraie raison dans plusieurs avertissements successifs : on les garde tous.
    $warnings = [];
    set_error_handler(static function (int $no, string $msg) use (&$warnings): bool {
        $warnings[] = $msg;
        return true;
    });
    try {
        $fp = stream_socket_client('ssl://' . $addr, $errno, $errstr, CERT_TIMEOUT, STREAM_CLIENT_CONNECT, $ctx);
    } finally {
        restore_error_handler();
    }
    if (!$fp) {
        return ['cert' => null, 'error' => cert_explain(implode(' | ', $warnings), (string) $errstr)];
    }
    $params = stream_context_get_params($fp);
    fclose($fp);
    return ['cert' => $params['options']['ssl']['peer_certificate'] ?? null, 'error' => ''];
}

/** Traduit les erreurs OpenSSL les plus courantes. */
function cert_explain(string $why, string $errstr): string
{
    return match (true) {
        str_contains($why, 'did not match expected') || str_contains($why, 'peer_name')
            => 'Le certificat ne correspond pas à ce nom de domaine.',
        str_contains($why, 'certificate has expired') => 'Certificat expiré.',
        str_contains($why, 'self-signed') || str_contains($why, 'self signed') || str_contains($why, 'unable to get local issuer')
            || str_contains($why, 'certificate verify failed')
            => 'Certificat non reconnu (auto-signé ou autorité inconnue).',
        str_contains($why, 'Connection refused') || str_contains($errstr, 'Connection refused') => 'Connexion refusée.',
        str_contains($why, 'timed out') || str_contains($errstr, 'timed out') => 'Pas de réponse (délai dépassé).',
        str_contains($why, 'getaddrinfo') || str_contains($why, 'php_network_getaddresses') => 'Nom de domaine introuvable.',
        default => 'Connexion TLS impossible' . ($errstr !== '' ? ' : ' . mb_substr($errstr, 0, 120) : '.'),
    };
}

/**
 * Contrôle un domaine. @return array{status:string,expires_at:?int,issued_at:?int,issuer:string,names:string,error:string}
 * status : ok | warn (expire bientôt) | expired | invalid (refusé par les navigateurs) | error (injoignable)
 */
function cert_check(string $host, int $port): array
{
    $r = ['status' => 'error', 'expires_at' => null, 'issued_at' => null, 'issuer' => '', 'names' => '', 'error' => ''];
    $p = cert_probe($host, $port, false);
    if (!$p['cert']) {
        $r['error'] = $p['error'];
        return $r;
    }
    $c = openssl_x509_parse($p['cert']);
    if (!is_array($c)) {
        $r['error'] = 'Certificat illisible.';
        return $r;
    }
    $r['expires_at'] = (int) ($c['validTo_time_t'] ?? 0) ?: null;
    $r['issued_at']  = (int) ($c['validFrom_time_t'] ?? 0) ?: null;
    $iss = $c['issuer'] ?? [];
    $r['issuer'] = mb_substr(trim((string) ($iss['O'] ?? '') . ((isset($iss['O'], $iss['CN'])) ? ' · ' : '') . (string) ($iss['CN'] ?? '')), 0, 120);
    $san = (string) ($c['extensions']['subjectAltName'] ?? '');
    $r['names'] = mb_substr(implode(', ', array_map(fn ($n) => preg_replace('/^DNS:/', '', trim($n)), array_filter(explode(',', $san)))), 0, 500);

    $left = $r['expires_at'] !== null ? $r['expires_at'] - time() : -1;
    if ($left < 0) {
        $r['status'] = 'expired';
        $r['error'] = 'Certificat expiré.';
        return $r;
    }
    $v = cert_probe($host, $port, true);
    if (!$v['cert']) {
        $r['status'] = 'invalid';
        $r['error'] = $v['error'];
        return $r;
    }
    $r['status'] = $left <= CERT_WARN_DAYS * 86400 ? 'warn' : 'ok';
    return $r;
}

function cert_days(?int $expires): ?int
{
    return $expires === null ? null : (int) floor(($expires - time()) / 86400);
}

/** Adresse qui reçoit les alertes de certificats (réglée sur la page), ou ''. */
function cert_mail_to(): string
{
    $m = setting('cert_mail');
    return valid_email($m) ? $m : '';
}

/**
 * Alerte de certificat : par e-mail si une adresse est réglée et l'envoi configuré,
 * sinon (ou si l'envoi échoue) sur ntfy, pour qu'elle ne soit jamais perdue.
 */
function cert_alert(string $title, string $body, int $priority, array $tags): void
{
    $to = cert_mail_to();
    if ($to !== '' && mail_configured()) {
        $err = send_mail($to, '[' . APP_NAME . '] ' . $title, $body . "\n\n— Page Certificats du portail"
            . (portal_base() !== '' ? ' : ' . portal_base() . '/certificats.php' : '') . "\n");
        if ($err === null) {
            return;
        }
        log_event(null, "E-mail d'alerte de certificat non envoyé : $err");
        $body .= "\n(E-mail non envoyé : $err)";
    }
    notify($title, $body, $priority, $tags);
}

/** Adresse publique du portail pour le lien dans l'e-mail, ou ''. */
function portal_base(): string
{
    if (function_exists('portal_url')) {
        return portal_url();
    }
    return rtrim(defined('APP_URL') && APP_URL !== '' ? (string) APP_URL : setting('portal_url'), '/');
}

/** Contrôle un domaine enregistré, met la base à jour et envoie les alertes utiles. */
function cert_update(array $row): array
{
    $res  = cert_check((string) $row['host'], (int) $row['port']);
    $host = (string) $row['host'];
    $days = cert_days($res['expires_at']);
    $fail = in_array($res['status'], ['invalid', 'error'], true) ? (int) $row['fail_count'] + 1 : 0;
    $level = $row['alert_level'] !== null ? (int) $row['alert_level'] : null;

    // Renouvelé : la date d'expiration a avancé alors qu'une alerte avait été envoyée.
    if ($res['expires_at'] !== null && $row['expires_at'] !== null
        && $res['expires_at'] > (int) $row['expires_at'] + 86400 && $level !== null) {
        cert_alert("Certificat renouvelé : $host", 'Valable jusqu\'au ' . date('d/m/Y', $res['expires_at']) . " ($days jours).", 2, ['white_check_mark']);
        log_event(null, "Certificat de $host renouvelé (jusqu'au " . date('d/m/Y', $res['expires_at']) . ')');
        $level = null;
    }

    // Expiration proche : une alerte par palier (14, 7, 3, 1, 0 jour).
    if ($days !== null && $days <= CERT_WARN_DAYS) {
        $palier = null;
        foreach (CERT_PALIERS as $p) {
            if ($days <= $p) {
                $palier = $p;
            }
        }
        $palier ??= CERT_WARN_DAYS;
        if ($level === null || $palier < $level) {
            $txt = $days < 0 ? 'est EXPIRÉ depuis ' . abs($days) . ' jour(s)' : ($days === 0 ? 'expire AUJOURD\'HUI' : "expire dans $days jour(s)");
            cert_alert("Certificat : $host $txt", 'Le renouvellement automatique a peut-être échoué : vérifie Zoraxy (Certificats ACME) et que le port 80 arrive bien jusqu\'à Zoraxy.',
                $days <= 3 ? 5 : 4, [$days <= 3 ? 'rotating_light' : 'warning']);
            log_event(null, "Certificat de $host : $txt");
            $level = $palier;
        }
    } elseif ($days !== null && $days > CERT_WARN_DAYS) {
        $level = null;
    }

    // Certificat refusé ou site injoignable : alerte au 2e contrôle raté d'affilée (évite les faux positifs).
    if ($fail === 2) {
        cert_alert("Certificat : problème sur $host", $res['error'], 4, ['warning']);
        log_event(null, "Certificat de $host : {$res['error']}");
    } elseif ($fail === 0 && (int) $row['fail_count'] >= 2) {
        cert_alert("Certificat : $host rétabli", 'Le certificat est de nouveau valide.', 2, ['white_check_mark']);
        log_event(null, "Certificat de $host de nouveau valide");
    }

    db()->prepare('UPDATE cert_hosts SET status = ?, expires_at = ?, issued_at = ?, issuer = ?, names = ?, error = ?,
            checked_at = ?, alert_level = ?, fail_count = ? WHERE id = ?')
        ->execute([$res['status'], $res['expires_at'] ?? $row['expires_at'], $res['issued_at'] ?? $row['issued_at'],
            $res['issuer'] !== '' ? $res['issuer'] : $row['issuer'], $res['names'] !== '' ? $res['names'] : $row['names'],
            $res['error'], time(), $level, $fail, $row['id']]);
    return $res;
}

/** Contrôle tous les domaines (planificateur). */
function cert_run_all(): void
{
    foreach (db()->query('SELECT * FROM cert_hosts ORDER BY host')->fetchAll() as $row) {
        cert_update($row);
    }
}

/**
 * Tes propres domaines (même domaine que le portail, ex. *.leo44.cloud) présents dans les liens
 * et pas encore surveillés. Les sites des autres (github.com…) ne sont pas proposés. @return string[]
 */
function cert_suggestions(): array
{
    $known = array_map('strtolower', db()->query('SELECT host FROM cert_hosts')->fetchAll(PDO::FETCH_COLUMN));
    $self = strtolower(faceid_rp_id());
    $base = implode('.', array_slice(explode('.', $self), -2)); // leo44.cloud
    if (!cert_valid_host($base)) {
        return [];
    }
    $out = [];
    foreach (db()->query("SELECT url FROM links WHERE url LIKE 'https://%'")->fetchAll(PDO::FETCH_COLUMN) as $u) {
        $h = strtolower((string) parse_url((string) $u, PHP_URL_HOST));
        $port = parse_url((string) $u, PHP_URL_PORT);
        $mine = $h === $base || str_ends_with($h, '.' . $base);
        if ($mine && ($port === null || $port === 443) && cert_valid_host($h) && !is_private_host($h) && !in_array($h, $known, true)) {
            $out[$h] = true;
        }
    }
    if ($self !== '' && cert_valid_host($self) && !is_private_host($self) && !in_array($self, $known, true)) {
        $out[$self] = true;
    }
    ksort($out);
    return array_keys($out);
}
