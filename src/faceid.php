<?php
declare(strict_types=1);

// ─── Face ID / Touch ID (WebAuthn) ──────────────────────────────────────
// Sur un appareil de confiance, l'utilisateur peut créer une clé d'accès protégée par Face ID.
// La session est alors verrouillée après FACEID_LOCK secondes sans activité, et Face ID est
// demandé pour la rouvrir. Seule la clé publique est enregistrée : le serveur vérifie la
// signature produite par le téléphone (ECDSA P-256, attestation « none »).

function b64u_enc(string $bin): string
{
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

function b64u_dec(string $s): string
{
    $d = base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true);
    return $d === false ? '' : $d;
}

/** Identifiant du site pour les clés d'accès : le nom de domaine, sans port. */
function faceid_rp_id(): string
{
    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    $host = preg_replace('/:\d+$/', '', $host) ?? '';
    return preg_match('/^[a-z0-9.-]+$/', $host) ? $host : '';
}

function faceid_origin(): string
{
    $port = '';
    if (preg_match('/:(\d+)$/', (string) ($_SERVER['HTTP_HOST'] ?? ''), $m)) {
        $port = ':' . $m[1];
    }
    return (is_https() ? 'https://' : 'http://') . faceid_rp_id() . $port;
}

/** Décodeur CBOR minimal (entiers, chaînes, tableaux, tables) : suffit pour WebAuthn. */
function cbor_decode(string $d, int &$o): mixed
{
    if ($o >= strlen($d)) {
        throw new RuntimeException('CBOR tronqué');
    }
    $b = ord($d[$o++]);
    $type = $b >> 5;
    $info = $b & 0x1f;
    if ($info < 24) {
        $len = $info;
    } elseif ($info === 24) {
        $len = ord($d[$o]);
        $o += 1;
    } elseif ($info === 25) {
        $len = unpack('n', substr($d, $o, 2))[1];
        $o += 2;
    } elseif ($info === 26) {
        $len = unpack('N', substr($d, $o, 4))[1];
        $o += 4;
    } elseif ($info === 27) {
        $len = unpack('J', substr($d, $o, 8))[1];
        $o += 8;
    } elseif ($type === 7 && $info >= 20 && $info <= 22) {
        $len = 0;
    } else {
        throw new RuntimeException('CBOR non pris en charge');
    }
    switch ($type) {
        case 0: return $len;
        case 1: return -1 - $len;
        case 2:
        case 3:
            if ($o + $len > strlen($d)) {
                throw new RuntimeException('CBOR tronqué');
            }
            $v = substr($d, $o, $len);
            $o += $len;
            return $v;
        case 4:
            $a = [];
            for ($i = 0; $i < $len; $i++) {
                $a[] = cbor_decode($d, $o);
            }
            return $a;
        case 5:
            $m = [];
            for ($i = 0; $i < $len; $i++) {
                $k = cbor_decode($d, $o);
                $m[$k] = cbor_decode($d, $o);
            }
            return $m;
        case 7: return [20 => false, 21 => true, 22 => null][$info];
    }
    throw new RuntimeException('CBOR non pris en charge');
}

/** Lit les données d'authentification. @return array{rp:string,flags:int,count:int,cred:?string,key:?array} */
function faceid_auth_data(string $ad, bool $withCred): array
{
    if (strlen($ad) < 37) {
        throw new RuntimeException('Données trop courtes');
    }
    $out = [
        'rp'    => substr($ad, 0, 32),
        'flags' => ord($ad[32]),
        'count' => unpack('N', substr($ad, 33, 4))[1],
        'cred'  => null,
        'key'   => null,
    ];
    if ($withCred) {
        if (!($out['flags'] & 0x40) || strlen($ad) < 55) {
            throw new RuntimeException('Clé absente');
        }
        $n = unpack('n', substr($ad, 53, 2))[1];
        $out['cred'] = substr($ad, 55, $n);
        $o = 55 + $n;
        $out['key'] = cbor_decode($ad, $o);
    }
    return $out;
}

/** Clé COSE (EC2, P-256, ES256) → clé publique PEM. */
function faceid_cose_pem(array $k): ?string
{
    if (($k[1] ?? null) !== 2 || ($k[3] ?? null) !== -7 || ($k[-1] ?? null) !== 1
        || strlen((string) ($k[-2] ?? '')) !== 32 || strlen((string) ($k[-3] ?? '')) !== 32) {
        return null;
    }
    $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . "\x04" . $k[-2] . $k[-3];
    return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
}

/** Vérifie clientDataJSON (type, défi, origine). */
function faceid_client_ok(string $json, string $type, string $challenge): bool
{
    $c = json_decode($json, true);
    return is_array($c)
        && ($c['type'] ?? '') === $type
        && hash_equals($challenge, (string) ($c['challenge'] ?? ''))
        && ($c['origin'] ?? '') === faceid_origin();
}

function faceid_new_challenge(string $purpose): string
{
    $ch = b64u_enc(random_bytes(32));
    $_SESSION['faceid_challenge'] = [$purpose, $ch, time()];
    return $ch;
}

function faceid_take_challenge(string $purpose): ?string
{
    $c = $_SESSION['faceid_challenge'] ?? null;
    unset($_SESSION['faceid_challenge']);
    if (!is_array($c) || $c[0] !== $purpose || time() - (int) $c[2] > 300) {
        return null;
    }
    return (string) $c[1];
}

/** Clé Face ID de l'appareil de confiance, ou null. */
function faceid_cred_for_device(int $deviceId): ?array
{
    $st = db()->prepare('SELECT * FROM faceid_creds WHERE device_id = ?');
    $st->execute([$deviceId]);
    return $st->fetch() ?: null;
}

/**
 * Enregistre la clé créée par le téléphone. Retourne null si c'est bon, sinon l'erreur.
 */
function faceid_register(array $user, int $deviceId, string $clientJson, string $attestation): ?string
{
    $challenge = faceid_take_challenge('register');
    if ($challenge === null || !faceid_client_ok($clientJson, 'webauthn.create', $challenge)) {
        return 'Demande expirée ou invalide, réessaie.';
    }
    try {
        $o = 0;
        $att = cbor_decode($attestation, $o);
        $ad = faceid_auth_data((string) ($att['authData'] ?? ''), true);
    } catch (Throwable) {
        return 'Réponse du téléphone illisible.';
    }
    if (!hash_equals(hash('sha256', faceid_rp_id(), true), $ad['rp'])) {
        return 'Site inattendu.';
    }
    if (($ad['flags'] & 0x05) !== 0x05) { // présence + vérification (Face ID / code)
        return 'Face ID n\'a pas été validé.';
    }
    $pem = faceid_cose_pem((array) $ad['key']);
    if ($pem === null) {
        return 'Type de clé non pris en charge.';
    }
    db()->prepare('DELETE FROM faceid_creds WHERE device_id = ?')->execute([$deviceId]);
    db()->prepare('INSERT INTO faceid_creds (user_id, device_id, cred_id, public_key, sign_count, created_at) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([$user['id'], $deviceId, b64u_enc((string) $ad['cred']), $pem, $ad['count'], time()]);
    log_event((int) $user['id'], 'Face ID activé sur un appareil de confiance');
    return null;
}

/** Vérifie la signature Face ID. Retourne null si c'est bon, sinon l'erreur. */
function faceid_verify(array $cred, string $credId, string $clientJson, string $authData, string $signature): ?string
{
    $challenge = faceid_take_challenge('unlock');
    if ($challenge === null || !faceid_client_ok($clientJson, 'webauthn.get', $challenge)) {
        return 'Demande expirée ou invalide, réessaie.';
    }
    if (!hash_equals((string) $cred['cred_id'], $credId)) {
        return 'Clé inconnue.';
    }
    try {
        $ad = faceid_auth_data($authData, false);
    } catch (Throwable) {
        return 'Réponse du téléphone illisible.';
    }
    if (!hash_equals(hash('sha256', faceid_rp_id(), true), $ad['rp']) || ($ad['flags'] & 0x05) !== 0x05) {
        return 'Face ID n\'a pas été validé.';
    }
    $ok = openssl_verify($authData . hash('sha256', $clientJson, true), $signature, (string) $cred['public_key'], OPENSSL_ALGO_SHA256);
    if ($ok !== 1) {
        return 'Signature invalide.';
    }
    // Compteur : les clés d'accès Apple restent à 0 ; sinon il doit toujours augmenter (clé clonée).
    if (($ad['count'] !== 0 || (int) $cred['sign_count'] !== 0) && $ad['count'] <= (int) $cred['sign_count']) {
        return 'Compteur de la clé incohérent.';
    }
    db()->prepare('UPDATE faceid_creds SET sign_count = ?, last_used = ? WHERE id = ?')
        ->execute([$ad['count'], time(), $cred['id']]);
    return null;
}
