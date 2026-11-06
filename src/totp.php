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

function totp_uri(string $secretB32, string $account): string
{
    $issuer = rawurlencode(APP_NAME);
    return 'otpauth://totp/' . $issuer . ':' . rawurlencode($account)
        . '?secret=' . $secretB32 . '&issuer=' . $issuer . '&algorithm=SHA1&digits=6&period=30';
}
