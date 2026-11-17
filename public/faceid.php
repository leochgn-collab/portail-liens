<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';

// Échanges Face ID (clé d'accès WebAuthn) avec app.js. Réponses en JSON.
start_session();
header('Content-Type: application/json; charset=utf-8');

function repondre(array $data, int $code = 200): never
{
    http_response_code($code);
    exit(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    repondre(['error' => 'Méthode non autorisée.'], 405);
}
require_post_csrf();
$action = (string) ($_POST['action'] ?? '');

// ── Déverrouillage (écran verrouillé) ──
if ($action === 'unlock') {
    $uid  = (int) ($_SESSION['unlock_uid'] ?? 0);
    $devId = (int) ($_SESSION['unlock_dev'] ?? 0);
    $user = $uid ? find_user($uid) : null;
    $cred = $devId ? faceid_cred_for_device($devId) : null;
    $st = db()->prepare('SELECT * FROM trusted_devices WHERE id = ? AND user_id = ? AND expires_at >= ?');
    $st->execute([$devId, $uid, time()]);
    $dev = $st->fetch() ?: null;
    if (!$user || !$cred || !$dev || (int) $cred['user_id'] !== $uid
        || (int) $dev['pwd_version'] !== (int) $user['pwd_version'] || !$user['totp_enabled']) {
        unset($_SESSION['unlock_uid'], $_SESSION['unlock_dev']);
        repondre(['error' => 'Session expirée.', 'next' => '/login.php'], 401);
    }
    $key = 'u' . $uid;
    if (too_many_attempts('faceid', $key, LOGIN_MAX_PER_USER)) {
        unset($_SESSION['unlock_uid'], $_SESSION['unlock_dev']);
        repondre(['error' => 'Trop d\'essais : connecte-toi avec ton mot de passe.', 'next' => '/login.php?motdepasse=1'], 429);
    }
    $err = faceid_verify(
        $cred,
        (string) ($_POST['id'] ?? ''),
        b64u_dec((string) ($_POST['clientDataJSON'] ?? '')),
        b64u_dec((string) ($_POST['authenticatorData'] ?? '')),
        b64u_dec((string) ($_POST['signature'] ?? ''))
    );
    if ($err !== null) {
        record_attempt('faceid', $key);
        log_event($uid, "Échec du déverrouillage Face ID : $err");
        repondre(['error' => $err], 400);
    }
    clear_attempts('faceid', $key);
    $next = (string) ($_SESSION['unlock_next'] ?? '/');
    open_device_session($user, $devId, true);
    db()->prepare('UPDATE trusted_devices SET last_used = ?, last_ip = ? WHERE id = ?')->execute([time(), client_ip(), $devId]);
    if (time() - (int) $dev['last_used'] > 3600) {
        log_event($uid, "Déverrouillage Face ID ({$dev['label']})");
    }
    repondre(['ok' => true, 'next' => safe_path($next)]);
}

// ── Activation sur l'appareil de confiance actuel (depuis Mon compte) ──
$user = current_user();
if (!$user) {
    repondre(['error' => 'Reconnecte-toi.'], 401);
}
$devId = (int) ($_SESSION['device_id'] ?? 0);
if ($devId === 0) {
    repondre(['error' => 'Fais d\'abord confiance à cet appareil (case à cocher lors de la double authentification).'], 400);
}

if ($action === 'register_options') {
    repondre([
        'challenge' => faceid_new_challenge('register'),
        'rp'        => ['id' => faceid_rp_id(), 'name' => APP_NAME],
        'user'      => ['id' => b64u_enc(hash('sha256', 'user|' . $user['id'] . '|' . app_key(), true)), 'name' => $user['username'], 'displayName' => $user['username'] . ' · ' . APP_NAME],
    ]);
}

if ($action === 'register') {
    $err = faceid_register($user, $devId,
        b64u_dec((string) ($_POST['clientDataJSON'] ?? '')),
        b64u_dec((string) ($_POST['attestationObject'] ?? '')));
    if ($err !== null) {
        repondre(['error' => $err], 400);
    }
    $_SESSION['faceid'] = true;
    $_SESSION['seen'] = time();
    notify("Face ID activé pour « {$user['username']} »", 'L\'application demandera Face ID à son ouverture sur cet appareil.', 3, ['lock']);
    repondre(['ok' => true]);
}

repondre(['error' => 'Action inconnue.'], 400);
