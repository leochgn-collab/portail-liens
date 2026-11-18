<?php
declare(strict_types=1);

function asset(string $file): string
{
    $path = ROOT . '/public/assets/' . $file;
    $v = is_file($path) ? substr(md5((string) filemtime($path)), 0, 8) : '0';
    return '/assets/' . $file . '?v=' . $v;
}

function french_date(int $ts): string
{
    $jours = ['dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'];
    $mois  = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août',
              'septembre', 'octobre', 'novembre', 'décembre'];
    return $jours[(int) date('w', $ts)] . ' ' . date('j', $ts) . ' ' . $mois[(int) date('n', $ts) - 1] . ' ' . date('Y', $ts);
}

function french_datetime(?int $ts): string
{
    if (!$ts) {
        return '—';
    }
    return date('d/m/Y à H:i', $ts);
}

function icon(string $name): string
{
    $paths = [
        'grid'   => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
        'shield' => '<path d="M12 3l8 3v6c0 4.5-3.4 8.3-8 9-4.6-.7-8-4.5-8-9V6l8-3z"/>',
        'user'   => '<circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 3.6-6.5 8-6.5s8 2.5 8 6.5"/>',
        'logout' => '<path d="M15 4h3a2 2 0 012 2v12a2 2 0 01-2 2h-3"/><path d="M10 16l-4-4 4-4"/><path d="M6 12h10"/>',
        'search' => '<circle cx="11" cy="11" r="7"/><path d="M20 20l-4-4"/>',
        'lock'   => '<rect x="4" y="10" width="16" height="11" rx="2"/><path d="M8 10V7a4 4 0 018 0v3"/>',
        'arrow'  => '<path d="M7 17L17 7"/><path d="M8 7h9v9"/>',
        'link'   => '<path d="M10 14a4 4 0 005.7 0l3-3a4 4 0 00-5.7-5.7l-1 1"/><path d="M14 10a4 4 0 00-5.7 0l-3 3a4 4 0 005.7 5.7l1-1"/>',
        'folder' => '<path d="M3 7a2 2 0 012-2h4l2 2h8a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2V7z"/>',
        'pulse'  => '<path d="M3 12h4l3-7 4 14 3-7h4"/>',
        'clock'  => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'note'   => '<path d="M6 3h9l4 4v13a1 1 0 01-1 1H6a1 1 0 01-1-1V4a1 1 0 011-1z"/><path d="M14 3v5h5"/><path d="M8.5 13h7M8.5 17h5"/>',
        'copy'   => '<rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15V6a2 2 0 012-2h9"/>',
        'server' => '<rect x="3" y="4" width="18" height="7" rx="2"/><rect x="3" y="13" width="18" height="7" rx="2"/><path d="M7 7.5h.01M7 16.5h.01"/>',
        'power'  => '<path d="M12 3v9"/><path d="M6.3 6.3a8 8 0 1011.4 0"/>',
        'more'   => '<circle cx="5" cy="12" r="1.3"/><circle cx="12" cy="12" r="1.3"/><circle cx="19" cy="12" r="1.3"/>',
        'bell'   => '<path d="M6 16V11a6 6 0 0112 0v5l1.5 2h-15L6 16z"/><path d="M10 20.5a2 2 0 004 0"/>',
        'archive'=> '<rect x="3" y="4" width="18" height="5" rx="1.5"/><path d="M5 9v10a1 1 0 001 1h12a1 1 0 001-1V9"/><path d="M10 13h4"/>',
        'key'    => '<circle cx="8" cy="15" r="4"/><path d="M11 12l9-9"/><path d="M17 6l3 3"/>',
    ];
    return '<svg class="ic" viewBox="0 0 24 24" aria-hidden="true">' . ($paths[$name] ?? '') . '</svg>';
}

/** Page avec barre latérale (utilisateur connecté). */
function page_start(string $title, array $user, string $active): void
{
    send_security_headers();
    $nav = [
        ['/', 'Tableau de bord', 'grid', 'dashboard'],
        ['/rappels.php', 'Rappels', 'bell', 'rappels'],
        ['/compte.php', 'Mon compte', 'user', 'compte'],
    ];
    if ($user['is_admin']) {
        array_splice($nav, 2, 0, [
            ['/conteneurs.php', 'Conteneurs', 'server', 'conteneurs'],
            ['/sauvegardes.php', 'Sauvegardes', 'archive', 'sauvegardes'],
            ['/reveil.php', 'Réveil réseau', 'power', 'reveil'],
            ['/admin.php', 'Administration', 'shield', 'admin'],
        ]);
    }
    $initial = strtoupper(mb_substr($user['username'], 0, 1));
    [$theme, $fond] = appearance($user);
    ?>
<!doctype html>
<html lang="fr" data-theme="<?= e($theme) ?>" data-fond="<?= e($fond) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="color-scheme" content="dark">
<meta name="theme-color" content="#06080d">
<title><?= e($title) ?> · <?= e(APP_NAME) ?></title>
<link rel="icon" href="<?= e(asset('favicon.svg')) ?>" type="image/svg+xml">
<link rel="manifest" href="/manifest.php">
<link rel="apple-touch-icon" href="/assets/icons/apple-touch-icon.png">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="<?= e(APP_NAME) ?>">
<link rel="stylesheet" href="<?= e(asset('style.css')) ?>">
</head>
<body class="app">
<div class="bg-anim" aria-hidden="true"><i></i></div>
<aside class="sidebar">
  <a class="brand" href="/">
    <span class="brand-mark"><?= icon('link') ?></span>
    <span class="brand-text"><strong><?= e(APP_NAME) ?></strong><small><?= e(APP_SUBTITLE) ?></small></span>
  </a>
  <nav class="nav">
    <?php foreach ($nav as [$href, $label, $ic, $key]): ?>
      <a href="<?= e($href) ?>" class="<?= $key === $active ? 'active' : '' ?>"><?= icon($ic) ?><span><?= e($label) ?></span></a>
    <?php endforeach; ?>
  </nav>
  <div class="sidebar-foot">
    <div class="me">
      <span class="avatar"><?= e($initial) ?></span>
      <span class="me-text"><strong><?= e($user['username']) ?></strong><small><?= $user['is_admin'] ? 'Administrateur' : 'Utilisateur' ?></small></span>
    </div>
    <form method="post" action="/logout.php">
      <?= csrf_field() ?>
      <button class="icon-btn" type="submit" title="Se déconnecter" aria-label="Se déconnecter"><?= icon('logout') ?></button>
    </form>
  </div>
</aside>
<?php
    // Barre d'onglets façon application (téléphone) : 4 onglets principaux, le reste dans « Plus ».
    $tabKeys = ['dashboard' => 'Accueil', 'conteneurs' => 'Conteneurs', 'rappels' => 'Rappels', 'compte' => 'Compte'];
    $tabs = array_values(array_filter($nav, fn ($n) => isset($tabKeys[$n[3]])));
    $more = array_values(array_filter($nav, fn ($n) => !isset($tabKeys[$n[3]])));
    $moreActive = in_array($active, array_column($more, 3), true);
    ?>
<nav class="tabbar" aria-label="Navigation">
  <?php foreach ($tabs as [$href, $label, $ic, $key]): ?>
    <a href="<?= e($href) ?>" class="tab<?= $key === $active ? ' active' : '' ?>"<?= $key === $active ? ' aria-current="page"' : '' ?>><span class="tab-ic"><?= icon($ic) ?></span><span class="tab-lb"><?= e($tabKeys[$key]) ?></span></a>
  <?php endforeach; ?>
  <details class="tab-more<?= $moreActive ? ' active' : '' ?>">
    <summary class="tab"><span class="tab-ic"><?= icon('more') ?></span><span class="tab-lb">Plus</span></summary>
    <div class="sheet-backdrop" data-sheet-close></div>
    <div class="sheet">
      <div class="sheet-grab" aria-hidden="true"></div>
      <div class="sheet-user">
        <span class="avatar"><?= e($initial) ?></span>
        <span class="me-text"><strong><?= e($user['username']) ?></strong><small><?= $user['is_admin'] ? 'Administrateur' : 'Utilisateur' ?></small></span>
      </div>
      <?php foreach ($more as [$href, $label, $ic, $key]): ?>
        <a href="<?= e($href) ?>" class="sheet-item<?= $key === $active ? ' active' : '' ?>"><?= icon($ic) ?><span><?= e($label) ?></span></a>
      <?php endforeach; ?>
      <form method="post" action="/logout.php" data-confirm="Se déconnecter ? Sur ce téléphone, il faudra refaire la double authentification.">
        <?= csrf_field() ?>
        <button class="sheet-item danger" type="submit"><?= icon('logout') ?><span>Se déconnecter</span></button>
      </form>
    </div>
  </details>
</nav>
<main class="main">
<?php
    $f = flash();
    if ($f) {
        echo '<div class="toast" role="status">' . e($f) . '</div>';
    }
}

/** Page centrée (connexion, 2FA). */
function auth_start(string $title): void
{
    send_security_headers();
    [$theme, $fond] = appearance();
    ?>
<!doctype html>
<html lang="fr" data-theme="<?= e($theme) ?>" data-fond="<?= e($fond) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="color-scheme" content="dark">
<meta name="theme-color" content="#06080d">
<title><?= e($title) ?> · <?= e(APP_NAME) ?></title>
<link rel="icon" href="<?= e(asset('favicon.svg')) ?>" type="image/svg+xml">
<link rel="manifest" href="/manifest.php">
<link rel="apple-touch-icon" href="/assets/icons/apple-touch-icon.png">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="<?= e(APP_NAME) ?>">
<link rel="stylesheet" href="<?= e(asset('style.css')) ?>">
</head>
<body class="auth">
<div class="bg-anim" aria-hidden="true"><i></i></div>
<main class="auth-wrap">
  <div class="auth-brand">
    <span class="brand-mark"><?= icon('link') ?></span>
    <span class="brand-text"><strong><?= e(APP_NAME) ?></strong><small><?= e(APP_SUBTITLE) ?></small></span>
  </div>
<?php
    $f = flash();
    if ($f) {
        echo '<div class="alert">' . e($f) . '</div>';
    }
}

function auth_end(): void
{
    ?>
</main>
<script src="<?= e(asset('qrcode.js')) ?>"></script>
<script src="<?= e(asset('app.js')) ?>"></script>
</body>
</html>
<?php
}

function page_end(): void
{
    ?>
</main>
<script src="<?= e(asset('app.js')) ?>"></script>
</body>
</html>
<?php
}
