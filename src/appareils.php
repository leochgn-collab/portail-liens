<?php
declare(strict_types=1);

// ─── Appareils de confiance ─────────────────────────────────────────────
// Après la double authentification, l'utilisateur peut faire confiance à son appareil :
// un cookie longue durée (sélecteur + secret) le reconnecte ensuite sans mot de passe ni code.
// Seul le haché du secret est en base ; changer de mot de passe, réinitialiser la 2FA ou retirer
// l'appareil dans « Mon compte » coupe l'accès immédiatement.

function trust_cookie_name(): string
{
    return is_https() ? '__Host-portail_appareil' : 'portail_appareil';
}

function set_trust_cookie(string $value, int $expires): void
{
    setcookie(trust_cookie_name(), $value, [
        'expires'  => $expires,
        'path'     => '/',
        'secure'   => is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function clear_trust_cookie(): void
{
    if (isset($_COOKIE[trust_cookie_name()])) {
        set_trust_cookie('', time() - 3600);
        unset($_COOKIE[trust_cookie_name()]);
    }
}

/** Nom lisible de l'appareil d'après le navigateur (« iPhone · application », « Windows · Chrome »…). */
function device_label(string $ua, bool $app): string
{
    $os = match (true) {
        str_contains($ua, 'iPhone')                                   => 'iPhone',
        str_contains($ua, 'iPad')                                     => 'iPad',
        str_contains($ua, 'Android')                                  => 'Android',
        str_contains($ua, 'Windows')                                  => 'Windows',
        str_contains($ua, 'Macintosh')                                => 'Mac',
        str_contains($ua, 'Linux')                                    => 'Linux',
        default                                                       => 'Appareil',
    };
    $nav = match (true) {
        $app                                                          => 'application',
        str_contains($ua, 'Edg/')                                     => 'Edge',
        str_contains($ua, 'Firefox/') || str_contains($ua, 'FxiOS')   => 'Firefox',
        str_contains($ua, 'Chrome/') || str_contains($ua, 'CriOS')    => 'Chrome',
        str_contains($ua, 'Safari/')                                  => 'Safari',
        default                                                       => 'navigateur',
    };
    return "$os · $nav";
}

/** Enregistre l'appareil actuel comme appareil de confiance (à appeler juste après la 2FA). */
function trust_device(array $user, bool $app): void
{
    $selector  = bin2hex(random_bytes(12));
    $validator = bin2hex(random_bytes(32));
    $now       = time();
    $expires   = $now + TRUST_DAYS * 86400;
    $label     = device_label((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), $app);
    db()->prepare('INSERT INTO trusted_devices (user_id, selector, validator_hash, pwd_version, label, created_at, last_used, last_ip, expires_at)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$user['id'], $selector, hash('sha256', $validator), (int) $user['pwd_version'], $label, $now, $now, client_ip(), $expires]);
    set_trust_cookie("$selector:$validator", $expires);
    $GLOBALS['trusted_device_id'] = (int) db()->lastInsertId(); // repris par complete_login()
    log_event((int) $user['id'], "Nouvel appareil de confiance : $label");
    notify("Nouvel appareil de confiance pour « {$user['username']} »",
        "$label pourra se connecter sans code pendant " . TRUST_DAYS . " jours. Si ce n'est pas toi, retire-le dans Mon compte.",
        4, ['iphone']);
}

/**
 * Reconnecte automatiquement grâce au cookie d'appareil de confiance.
 * Retourne l'utilisateur, ou null (cookie absent, expiré, révoqué ou falsifié).
 */
function device_login(): ?array
{
    $raw = (string) ($_COOKIE[trust_cookie_name()] ?? '');
    if ($raw === '') {
        return null;
    }
    if (!preg_match('/^([a-f0-9]{24}):([a-f0-9]{64})$/', $raw, $m)) {
        clear_trust_cookie();
        return null;
    }
    $pdo = db();
    $st = $pdo->prepare('SELECT * FROM trusted_devices WHERE selector = ?');
    $st->execute([$m[1]]);
    $dev = $st->fetch() ?: null;
    $now = time();
    $forget = static function () use ($pdo, $dev): void {
        if ($dev) {
            $pdo->prepare('DELETE FROM trusted_devices WHERE id = ?')->execute([$dev['id']]);
        }
        clear_trust_cookie();
    };

    if (!$dev || (int) $dev['expires_at'] < $now) {
        $forget();
        return null;
    }
    if (!hash_equals((string) $dev['validator_hash'], hash('sha256', $m[2]))) {
        // Bon identifiant mais mauvais secret : cookie bricolé ou volé. On coupe cet appareil.
        $forget();
        log_event((int) $dev['user_id'], "Appareil de confiance retiré (cookie invalide) : {$dev['label']}");
        notify('Appareil de confiance retiré', "Un cookie invalide a été présenté pour « {$dev['label']} ». L'appareil a été retiré par sécurité.", 4, ['warning']);
        return null;
    }
    $user = find_user((int) $dev['user_id']);
    if (!$user || (int) $user['pwd_version'] !== (int) $dev['pwd_version'] || !$user['totp_enabled']) {
        $forget(); // compte supprimé, mot de passe changé ou 2FA réinitialisée
        return null;
    }

    session_regenerate_id(true);
    $flash = $_SESSION['flash'] ?? null;
    $_SESSION = [
        'uid'         => (int) $user['id'],
        'pwd_version' => (int) $user['pwd_version'],
        'created'     => $now,
        'last'        => $now,
        'device_id'   => (int) $dev['id'],
        'prev_login'  => $user['last_login_at'] ? (int) $user['last_login_at'] : null,
        'prev_ip'     => $user['last_login_ip'],
    ];
    if ($flash !== null && !str_starts_with((string) $flash, 'Session expirée')) {
        $_SESSION['flash'] = $flash;
    }

    // Mise à jour discrète : une ligne au journal par heure au plus, et prolongation glissante une fois par jour.
    if ($now - (int) $dev['last_used'] > 3600) {
        log_event((int) $user['id'], "Connexion automatique (appareil de confiance : {$dev['label']})");
        $pdo->prepare('UPDATE users SET last_login_at = ?, last_login_ip = ? WHERE id = ?')->execute([$now, client_ip(), $user['id']]);
    }
    $expires = (int) $dev['expires_at'];
    if ($now - (int) $dev['last_used'] > 86400) {
        $expires = $now + TRUST_DAYS * 86400;
        set_trust_cookie($raw, $expires);
    }
    $pdo->prepare('UPDATE trusted_devices SET last_used = ?, last_ip = ?, expires_at = ? WHERE id = ?')
        ->execute([$now, client_ip(), $expires, $dev['id']]);
    return $user;
}

/** Oublie l'appareil actuel (déconnexion). */
function forget_current_device(): void
{
    $raw = (string) ($_COOKIE[trust_cookie_name()] ?? '');
    if (preg_match('/^([a-f0-9]{24}):/', $raw, $m)) {
        db()->prepare('DELETE FROM trusted_devices WHERE selector = ?')->execute([$m[1]]);
    }
    clear_trust_cookie();
}

/** @return array<int, array<string, mixed>> appareils de confiance actifs d'un compte */
function user_devices(int $uid): array
{
    db()->prepare('DELETE FROM trusted_devices WHERE expires_at < ?')->execute([time()]);
    $st = db()->prepare('SELECT * FROM trusted_devices WHERE user_id = ? ORDER BY last_used DESC');
    $st->execute([$uid]);
    return $st->fetchAll();
}

function forget_devices(int $uid, ?int $deviceId = null): int
{
    if ($deviceId !== null) {
        $st = db()->prepare('DELETE FROM trusted_devices WHERE user_id = ? AND id = ?');
        $st->execute([$uid, $deviceId]);
    } else {
        $st = db()->prepare('DELETE FROM trusted_devices WHERE user_id = ?');
        $st->execute([$uid]);
    }
    return $st->rowCount();
}
