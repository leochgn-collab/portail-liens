<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';

$user = require_login();

$cats  = db()->query('SELECT * FROM categories ORDER BY position, name')->fetchAll();
$links = db()->query('SELECT * FROM links ORDER BY position, title')->fetchAll();
$notes = db()->query('SELECT * FROM notes ORDER BY position, id')->fetchAll();

$groups = [];
foreach ($cats as $c) {
    $groups[$c['id']] = ['name' => $c['name'], 'links' => [], 'notes' => []];
}
$groups[0] = ['name' => 'Sans catégorie', 'links' => [], 'notes' => []];
foreach ($links as $l) {
    $groups[isset($groups[(int) $l['category_id']]) ? (int) $l['category_id'] : 0]['links'][] = $l;
}
foreach ($notes as $n) {
    $groups[isset($groups[(int) $n['category_id']]) ? (int) $n['category_id'] : 0]['notes'][] = $n;
}
if (!$groups[0]['links'] && $groups[0]['notes']) {
    $groups[0]['name'] = 'Notes';
}
$groups = array_filter($groups, fn ($g) => $g['links'] || $g['notes']);

function tile_hue(string $s): int
{
    return hexdec(substr(md5($s), 0, 4)) % 8;
}

$nPriv = count(array_filter($links, fn ($l) => $l['kind'] === 'private'));
$nPub  = count($links) - $nPriv;
$prev = $_SESSION['prev_login'] ?? null;
$hour = (int) date('G');
$hello = $hour < 5 || $hour >= 18 ? 'Bonsoir' : 'Bonjour';

page_start('Tableau de bord', $user, 'dashboard');
?>
<header class="topbar">
  <div>
    <p class="eyebrow"><?= e(ucfirst(french_date(time()))) ?></p>
    <h1><?= e($hello) ?>, <?= e($user['username']) ?></h1>
  </div>
  <label class="search">
    <?= icon('search') ?>
    <input id="search" type="search" placeholder="Rechercher un lien ou une note…" autocomplete="off" aria-label="Rechercher">
    <kbd>/</kbd>
  </label>
</header>

<section class="stats">
  <div class="stat">
    <span class="stat-ic"><?= icon('link') ?></span>
    <div><small>Liens</small><strong><?= count($links) ?></strong><span class="stat-sub"><?= $nPub ?> publics · <?= $nPriv ?> privés</span></div>
  </div>
  <div class="stat">
    <span class="stat-ic"><?= icon('note') ?></span>
    <div><small>Notes</small><strong><?= count($notes) ?></strong><span class="stat-sub"><?= count($groups) ?> catégorie<?= count($groups) > 1 ? 's' : '' ?></span></div>
  </div>
  <div class="stat">
    <span class="stat-ic"><?= icon('pulse') ?></span>
    <div><small>Services en ligne</small><strong id="stat-online"><?= STATUS_CHECK && $links ? '…' : '—' ?></strong></div>
  </div>
  <div class="stat">
    <span class="stat-ic"><?= icon('clock') ?></span>
    <div><small>Connexion précédente</small><strong class="stat-sm"><?= e(french_datetime($prev)) ?></strong></div>
  </div>
</section>

<?php if (!$links && !$notes): ?>
  <section class="empty">
    <span class="empty-ic"><?= icon('link') ?></span>
    <h2>Aucun lien pour l'instant</h2>
    <?php if ($user['is_admin']): ?>
      <p class="muted">Ajoute tes premiers liens et notes depuis l'administration.</p>
      <a class="btn btn-primary" href="/admin.php">Ajouter un lien</a>
    <?php else: ?>
      <p class="muted">Un administrateur doit encore ajouter des liens.</p>
    <?php endif; ?>
  </section>
<?php endif; ?>

<?php if (($nPub > 0) + ($nPriv > 0) + (count($notes) > 0) > 1): ?>
<div class="filters" role="group" aria-label="Filtrer par type d'accès">
  <button type="button" class="chip active" data-filter="all">Tout <span><?= count($links) + count($notes) ?></span></button>
  <?php if ($nPub): ?><button type="button" class="chip" data-filter="public">🌐 Publics <span><?= $nPub ?></span></button><?php endif; ?>
  <?php if ($nPriv): ?><button type="button" class="chip" data-filter="private">🔒 Privés <span><?= $nPriv ?></span></button><?php endif; ?>
  <?php if ($notes): ?><button type="button" class="chip" data-filter="note">📝 Notes <span><?= count($notes) ?></span></button><?php endif; ?>
</div>
<?php endif; ?>

<div id="groups" <?= STATUS_CHECK && $links ? 'data-status="/status.php"' : '' ?>>
<?php foreach ($groups as $g): ?>
  <section class="group">
    <h2 class="group-title"><?= e($g['name']) ?> <span class="count"><?= count($g['links']) + count($g['notes']) ?></span></h2>
    <?php if ($g['links']): ?>
    <div class="grid">
      <?php foreach ($g['links'] as $l):
          $hue = tile_hue($l['title']);
          $priv = $l['kind'] === 'private';
          $search = mb_strtolower($l['title'] . ' ' . $l['url'] . ' ' . $l['description'] . ' ' . $g['name'] . ($priv ? ' privé local' : ' public'));
          ?>
        <a class="tile<?= $priv ? ' tile-priv' : '' ?>" data-kind="<?= $priv ? 'private' : 'public' ?>" href="<?= e($l['url']) ?>" target="_blank" rel="noopener noreferrer"
           data-id="<?= (int) $l['id'] ?>" data-search="<?= e($search) ?>">
          <span class="tile-ic h<?= $hue ?>">
            <?= $l['icon'] !== '' ? e($l['icon']) : e(mb_strtoupper(mb_substr($l['title'], 0, 1))) ?>
          </span>
          <span class="tile-body">
            <strong><span class="t"><?= e($l['title']) ?></span><?php if ($priv): ?><span class="badge badge-priv" title="Accessible uniquement depuis ton réseau local ou ton VPN">Privé</span><?php endif; ?></strong>
            <span class="host mono"><?= e(link_host($l['url'])) ?></span>
            <?php if ($l['description'] !== ''): ?><span class="desc"><?= e($l['description']) ?></span><?php endif; ?>
          </span>
          <span class="tile-side">
            <span class="dot" title="État inconnu"></span>
            <span class="go"><?= icon('arrow') ?></span>
          </span>
        </a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <?php if ($g['notes']): ?>
    <div class="grid grid-notes">
      <?php foreach ($g['notes'] as $n):
          $search = mb_strtolower($n['title'] . ' ' . $n['body'] . ' ' . $g['name'] . ' note');
          ?>
        <article class="note" data-kind="note" data-search="<?= e($search) ?>">
          <header class="note-head">
            <span class="note-ic"><?= icon('note') ?></span>
            <strong><?= e($n['title'] !== '' ? $n['title'] : 'Note') ?></strong>
            <button class="icon-btn icon-btn-sm" type="button" data-copy="<?= e($n['body']) ?>" title="Copier le texte" aria-label="Copier le texte"><?= icon('copy') ?></button>
          </header>
          <div class="note-body"><?= e($n['body']) ?></div>
          <button class="note-more" type="button" hidden>Afficher plus</button>
          <footer class="note-foot">Modifiée le <?= e(french_datetime((int) $n['updated_at'])) ?>
            <?php if ($user['is_admin']): ?> · <a href="/admin.php?tab=notes&amp;edit=<?= (int) $n['id'] ?>">Modifier</a><?php endif; ?></footer>
        </article>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </section>
<?php endforeach; ?>
</div>
<p id="no-result" class="empty-search" hidden>Rien ne correspond à ta recherche.</p>
<?php page_end();
