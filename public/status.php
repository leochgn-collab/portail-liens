<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';

// Renvoie l'état (en ligne / hors ligne) de chaque lien, mis en cache quelques minutes.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

if (!current_user()) {
    http_response_code(401);
    exit('{}');
}
session_write_close(); // ne bloque pas les autres pages pendant la vérification

if (!STATUS_CHECK || !function_exists('curl_multi_init')) {
    exit('{}');
}

$cacheFile = DATA_DIR . '/status-cache.json';
$cache = is_file($cacheFile) ? json_decode((string) file_get_contents($cacheFile), true) : null;
$links = db()->query('SELECT id, url, kind FROM links')->fetchAll();
$sig = md5(json_encode($links));

if (is_array($cache) && ($cache['sig'] ?? '') === $sig && time() - (int) ($cache['at'] ?? 0) < STATUS_CACHE) {
    exit(json_encode($cache['data']));
}

$mh = curl_multi_init();
$handles = [];
foreach ($links as $l) {
    $ch = curl_init($l['url']);
    curl_setopt_array($ch, [
        CURLOPT_NOBODY         => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_TIMEOUT        => STATUS_TIMEOUT,
        CURLOPT_CONNECTTIMEOUT => STATUS_TIMEOUT,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_USERAGENT      => 'PortailLiens-Status/1.0',
    ]);
    if ($l['kind'] === 'private') {
        // Appareils du réseau local : certificats souvent auto-signés, on vérifie seulement qu'ils répondent.
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    }
    curl_multi_add_handle($mh, $ch);
    $handles[(int) $l['id']] = $ch;
}
do {
    $st = curl_multi_exec($mh, $running);
    if ($running) {
        curl_multi_select($mh, 0.5);
    }
} while ($running && $st === CURLM_OK);

$data = [];
foreach ($handles as $id => $ch) {
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $ms   = (int) round(curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000);
    // Tout serveur qui répond (même 401/403/405) est considéré en ligne ; 5xx ou absence de réponse = hors ligne.
    $data[$id] = ['up' => $code > 0 && $code < 500, 'code' => $code, 'ms' => $ms];
    curl_multi_remove_handle($mh, $ch);
}
curl_multi_close($mh);

file_put_contents($cacheFile, json_encode(['sig' => $sig, 'at' => time(), 'data' => $data]), LOCK_EX);
echo json_encode($data);
