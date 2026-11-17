<?php
declare(strict_types=1);
// Manifeste de l'application installable (écran d'accueil du téléphone ou de l'ordinateur).
require dirname(__DIR__) . '/src/config.php';

header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: public, max-age=3600');
header('X-Content-Type-Options: nosniff');

$name = (string) APP_NAME;
echo json_encode([
    'id'               => '/',
    'name'             => $name,
    'short_name'       => mb_strlen($name) > 12 ? mb_substr($name, 0, 12) : $name,
    'description'      => 'Portail de liens, notes et outils du serveur',
    'lang'             => 'fr',
    'start_url'        => '/',
    'scope'            => '/',
    'display'          => 'standalone',
    'orientation'      => 'any',
    'background_color' => '#06080d',
    'theme_color'      => '#06080d',
    'icons'            => [
        ['src' => '/assets/icons/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => '/assets/icons/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => '/assets/icons/icon-maskable-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
    ],
    'shortcuts'        => [
        ['name' => 'Conteneurs', 'url' => '/conteneurs.php', 'icons' => [['src' => '/assets/icons/icon-192.png', 'sizes' => '192x192']]],
        ['name' => 'Rappels', 'url' => '/rappels.php', 'icons' => [['src' => '/assets/icons/icon-192.png', 'sizes' => '192x192']]],
        ['name' => 'Sauvegardes', 'url' => '/sauvegardes.php', 'icons' => [['src' => '/assets/icons/icon-192.png', 'sizes' => '192x192']]],
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
