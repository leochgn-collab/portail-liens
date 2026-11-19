<?php
declare(strict_types=1);

// TOTP (RFC 6238) : codes à 6 chiffres renouvelés toutes les 30 s,
// compatibles Google Authenticator, Authy, Mots de passe (iPhone), etc.

function base32_encode(string $bin): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split($bin) as $c) {
        $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 5) as $chunk) {
        $out .= $alphabet[bindec(str_pad($chunk, 5, '0'))];
    }
    return $out;
}

function base32_decode(string $b32): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $b32 = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $b32));
    $bits = '';
    foreach (str_split($b32) as $c) {
        $bits .= str_pad(decbin(strpos($alphabet, $c)), 5, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 8) as $byte) {
        if (strlen($byte) === 8) {
            $out .= chr(bindec($byte));
        }
    }
    return $out;
}

function totp_new_secret(): string
{
    return base32_encode(random_bytes(20)); // 160 bits
}

function totp_code(string $secretB32, int $step): string
{
    $hash   = hash_hmac('sha1', pack('J', $step), base32_decode($secretB32), true);
    $offset = ord($hash[19]) & 0x0f;
    $num    = ((ord($hash[$offset]) & 0x7f) << 24)
            | (ord($hash[$offset + 1]) << 16)
            | (ord($hash[$offset + 2]) << 8)
            | ord($hash[$offset + 3]);
    return str_pad((string) ($num % 1000000), 6, '0', STR_PAD_LEFT);
}

/**
 * Vérifie un code (tolérance ±1 période pour le décalage d'horloge).
 * Renvoie la période acceptée, ou null. $lastStep empêche de réutiliser un code.
 */
function totp_verify(string $secretB32, string $code, int $lastStep = 0): ?int
{
    $code = preg_replace('/\D/', '', $code);
    if (strlen($code) !== 6) {
        return null;
    }
    $now = intdiv(time(), 30);
    foreach ([0, -1, 1] as $d) {
        $step = $now + $d;
        if ($step > $lastStep && hash_equals(totp_code($secretB32, $step), $code)) {
            return $step;
        }
    }
    return null;
}

/**
 * Explique pourquoi un code a été refusé, pour afficher un message utile :
 *  - 'used'  : bon code, mais déjà utilisé (un code ne sert qu'une fois) ;
 *  - 'drift' : correspond à une autre heure → horloge du téléphone décalée ;
 *  - 'other' : aucun rapport (mauvaise entrée dans l'application, faute de frappe).
 * @return array{0:string,1:int} [raison, secondes (attente avant le prochain code, ou décalage)]
 */
function totp_diagnose(string $secretB32, string $code, int $lastStep = 0): array
{
    $code = preg_replace('/\D/', '', $code);
    if (strlen($code) !== 6) {
        return ['other', 0];
    }
    $now = intdiv(time(), 30);
    foreach ([0, -1, 1] as $d) {
        if ($now + $d <= $lastStep && hash_equals(totp_code($secretB32, $now + $d), $code)) {
            return ['used', 30 - time() % 30];
        }
    }
    for ($d = 2; $d <= 20; $d++) { // jusqu'à 10 minutes d'écart
        foreach ([$d, -$d] as $x) {
            if (hash_equals(totp_code($secretB32, $now + $x), $code)) {
                return ['drift', $x * 30];
            }
        }
    }
    return ['other', 0];
}

/** Message à afficher pour un code refusé. */
function totp_error_message(string $secretB32, string $code, int $lastStep = 0): string
{
    [$why, $n] = totp_diagnose($secretB32, $code, $lastStep);
    return match ($why) {
        'used'  => "Ce code vient déjà de servir : un code ne marche qu'une seule fois. Attends le code suivant (dans $n s) et saisis-le.",
        'drift' => "Ce code correspond à une heure décalée d'environ " . max(1, (int) round(abs($n) / 60)) . ' min : '
            . "l'heure de ton téléphone n'est pas juste. Mets « Date et heure » en automatique (Réglages → Général) puis réessaie.",
        default => 'Code incorrect. Vérifie que tu utilises bien l\'entrée « ' . APP_NAME . ' » de ton application '
            . "d'authentification (s'il y en a plusieurs, la plus récente, et supprime les anciennes).",
    };
}

function totp_uri(string $secretB32, string $account): string
{
    $issuer = rawurlencode(APP_NAME);
    return 'otpauth://totp/' . $issuer . ':' . rawurlencode($account)
        . '?secret=' . $secretB32 . '&issuer=' . $issuer . '&algorithm=SHA1&digits=6&period=30';
}
