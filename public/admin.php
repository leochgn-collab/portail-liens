<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';

$me  = require_admin();
$tab = in_array($_GET['tab'] ?? '', ['liens', 'notes', 'categories', 'utilisateurs', 'journal'], true) ? $_GET['tab'] : 'liens';
$errors = [];

function temp_password(): string
{
    $a = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $p = '';
    for ($i = 0; $i < 16; $i++) {
        $p .= $a[random_int(0, strlen($a) - 1)];
    }
    return implode('-', str_split($p, 4));
}

function admin_count(): int
{
    return (int) db()->query('SELECT COUNT(*) FROM users WHERE is_admin = 1')->fetchColumn();
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    require_post_csrf();
    $a  = (string) ($_POST['action'] ?? '');
    $id = (int) ($_POST['id'] ?? 0);
    $pdo = db();

    switch ($a) {
        case 'link_save':
            $tab   = 'liens';
            $title = trim((string) ($_POST['title'] ?? ''));
            $url   = trim((string) ($_POST['url'] ?? ''));
            $desc  = trim((string) ($_POST['description'] ?? ''));
            $ic    = trim((string) ($_POST['icon'] ?? ''));
            $cat   = (int) ($_POST['category_id'] ?? 0) ?: null;
            $pos   = (int) ($_POST['position'] ?? 0);
            $url   = normalize_url($url);
            $kind  = (string) ($_POST['kind'] ?? 'auto');
            if (!in_array($kind, ['public', 'private'], true)) {
                $kind = is_private_host((string) parse_url($url, PHP_URL_HOST)) ? 'private' : 'public';
            }
            if ($title === '' || mb_strlen($title) > 80) {
                $errors[] = 'Le titre est obligatoire (80 caractères max).';
            }
            if (!valid_url($url)) {
                $errors[] = 'Adresse invalide. Exemples : https://mail.leo44.cloud ou 192.168.1.10:8080';
            }
            if (mb_strlen($desc) > 160) {
                $errors[] = 'Description trop longue (160 caractères max).';
            }
            if (mb_strlen($ic) > 4) {
                $errors[] = 'L\'icône doit être un emoji ou 1 à 2 lettres.';
            }
            if (!$errors) {
                if ($id) {
                    $pdo->prepare('UPDATE links SET title=?, url=?, description=?, icon=?, category_id=?, position=?, kind=? WHERE id=?')
                        ->execute([$title, $url, $desc, $ic, $cat, $pos, $kind, $id]);
                    flash("Lien « $title » modifié.");
                } else {
                    $pdo->prepare('INSERT INTO links (title, url, description, icon, category_id, position, kind) VALUES (?,?,?,?,?,?,?)')
                        ->execute([$title, $url, $desc, $ic, $cat, $pos, $kind]);
                    flash("Lien « $title » ajouté.");
                }
                redirect('/admin.php?tab=liens');
            }
            break;

        case 'link_delete':
            $pdo->prepare('DELETE FROM links WHERE id = ?')->execute([$id]);
            flash('Lien supprimé.');
            redirect('/admin.php?tab=liens');

        case 'note_save':
            $tab   = 'notes';
            $title = trim((string) ($_POST['title'] ?? ''));
            $body  = str_replace("\r\n", "\n", trim((string) ($_POST['body'] ?? '')));
            $cat   = (int) ($_POST['category_id'] ?? 0) ?: null;
            $pos   = (int) ($_POST['position'] ?? 0);
            if (mb_strlen($title) > 80) {
                $errors[] = 'Titre trop long (80 caractères max).';
            }
            if ($body === '' || mb_strlen($body) > 5000) {
                $errors[] = 'Le texte de la note est obligatoire (5 000 caractères max).';
            }
            if (!$errors) {
                if ($id) {
                    $pdo->prepare('UPDATE notes SET title=?, body=?, category_id=?, position=?, updated_at=? WHERE id=?')
                        ->execute([$title, $body, $cat, $pos, time(), $id]);
                    flash('Note modifiée.');
                } else {
                    $pdo->prepare('INSERT INTO notes (title, body, category_id, position, updated_at) VALUES (?,?,?,?,?)')
                        ->execute([$title, $body, $cat, $pos, time()]);
                    flash('Note ajoutée.');
                }
                redirect('/admin.php?tab=notes');
            }
            break;

        case 'note_delete':
            $pdo->prepare('DELETE FROM notes WHERE id = ?')->execute([$id]);
            flash('Note supprimée.');
            redirect('/admin.php?tab=notes');

        case 'cat_save':
            $tab  = 'categories';
            $name = trim((string) ($_POST['name'] ?? ''));
            $pos  = (int) ($_POST['position'] ?? 0);
            if ($name === '' || mb_strlen($name) > 60) {
                $errors[] = 'Le nom est obligatoire (60 caractères max).';
                break;
            }
            if ($id) {
                $pdo->prepare('UPDATE categories SET name=?, position=? WHERE id=?')->execute([$name, $pos, $id]);
            } else {
                $pdo->prepare('INSERT INTO categories (name, position) VALUES (?, ?)')->execute([$name, $pos]);
            }
            flash("Catégorie « $name » enregistrée.");
            redirect('/admin.php?tab=categories');

        case 'cat_delete':
            $pdo->prepare('DELETE FROM categories WHERE id = ?')->execute([$id]);
            flash('Catégorie supprimée. Ses liens et notes sont conservés, sans catégorie.');
            redirect('/admin.php?tab=categories');

        case 'user_create':
            $tab = 'utilisateurs';
            $username = trim((string) ($_POST['username'] ?? ''));
            if (!preg_match('/^[A-Za-z0-9._-]{3,32}$/', $username)) {
                $errors[] = 'Identifiant : 3 à 32 caractères (lettres, chiffres, point, tiret, tiret bas).';
                break;
            }
            $exists = $pdo->prepare('SELECT 1 FROM users WHERE username = ?');
            $exists->execute([$username]);
            if ($exists->fetchColumn()) {
                $errors[] = 'Cet identifiant existe déjà.';
                break;
            }
            $tmp = temp_password();
            $pdo->prepare('INSERT INTO users (username, password_hash, is_admin, must_change_pwd, created_at) VALUES (?,?,?,1,?)')
                ->execute([$username, password_hash($tmp, password_algo()), !empty($_POST['is_admin']) ? 1 : 0, time()]);
            log_event((int) $me['id'], "Compte « $username » créé");
            $_SESSION['temp_pwd'] = [$username, $tmp];
            redirect('/admin.php?tab=utilisateurs');

        case 'user_reset_pwd':
            $u = find_user($id);
            if ($u) {
                $tmp = temp_password();
                $pdo->prepare('UPDATE users SET password_hash=?, must_change_pwd=1, pwd_version=pwd_version+1 WHERE id=?')
                    ->execute([password_hash($tmp, password_algo()), $id]);
                log_event((int) $me['id'], "Mot de passe de « {$u['username']} » réinitialisé");
                $_SESSION['temp_pwd'] = [$u['username'], $tmp];
                if ($id === (int) $me['id']) {
                    $_SESSION['pwd_version'] = (int) $u['pwd_version'] + 1;
                }
            }
            redirect('/admin.php?tab=utilisateurs');

        case 'user_reset_2fa':
            $u = find_user($id);
            if ($u && $id !== (int) $me['id']) {
                $pdo->prepare('UPDATE users SET totp_enabled=0, totp_secret=NULL, totp_last_step=0 WHERE id=?')->execute([$id]);
                $pdo->prepare('DELETE FROM recovery_codes WHERE user_id=?')->execute([$id]);
                log_event((int) $me['id'], "Double authentification de « {$u['username']} » réinitialisée");
                flash("« {$u['username']} » reconfigurera sa double authentification à sa prochaine connexion.");
            }
            redirect('/admin.php?tab=utilisateurs');

        case 'user_toggle_admin':
            $u = find_user($id);
            if ($u && $id !== (int) $me['id']) {
                $pdo->prepare('UPDATE users SET is_admin = 1 - is_admin WHERE id=?')->execute([$id]);
                log_event((int) $me['id'], "Droits administrateur de « {$u['username']} » modifiés");
            }
            redirect('/admin.php?tab=utilisateurs');

        case 'user_delete':
            $u = find_user($id);
            if ($u && $id !== (int) $me['id']) {
                $pdo->prepare('DELETE FROM users WHERE id=?')->execute([$id]);
                log_event((int) $me['id'], "Compte « {$u['username']} » supprimé");
                flash("Compte « {$u['username']} » supprimé.");
            }
            redirect('/admin.php?tab=utilisateurs');
    }
}

$cats = db()->query('SELECT c.*, (SELECT COUNT(*) FROM links l WHERE l.category_id = c.id) AS n, (SELECT COUNT(*) FROM notes o WHERE o.category_id = c.id) AS nn FROM categories c ORDER BY position, name')->fetchAll();
$catNames = array_column($cats, 'name', 'id');
$tempPwd = $_SESSION['temp_pwd'] ?? null;
unset($_SESSION['temp_pwd']);

page_start('Administration', $me, 'admin');
$tabs = ['liens' => 'Liens', 'notes' => 'Notes', 'categories' => 'Catégories', 'utilisateurs' => 'Utilisateurs', 'journal' => 'Journal'];
?>
<header class="topbar">
  <div>
    <p class="eyebrow">Administration</p>
    <h1>Gérer le portail</h1>
  </div>
</header>

<nav class="tabs">
  <?php foreach ($tabs as $k => $label): ?>
    <a href="/admin.php?tab=<?= $k ?>" class="<?= $k === $tab ? 'active' : '' ?>"><?= e($label) ?></a>
  <?php endforeach; ?>
</nav>

<?php foreach ($errors as $err): ?>
  <div class="alert alert-error" role="alert"><?= e($err) ?></div>
<?php endforeach; ?>

<?php if ($tab === 'liens'):
    $links = db()->query('SELECT * FROM links ORDER BY position, title')->fetchAll();
    $edit = null;
    if (isset($_GET['edit'])) {
        $st = db()->prepare('SELECT * FROM links WHERE id = ?');
        $st->execute([(int) $_GET['edit']]);
        $edit = $st->fetch() ?: null;
    }
    $v = $errors ? $_POST : ($edit ?? []);
    ?>
  <div class="cols cols-form">
    <section class="panel">
      <h2><?= $edit || !empty($v['id']) ? 'Modifier le lien' : 'Ajouter un lien' ?></h2>
      <form method="post" class="form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="link_save">
        <input type="hidden" name="id" value="<?= (int) ($v['id'] ?? 0) ?>">
        <label class="field"><span>Titre</span>
          <input name="title" value="<?= e($v['title'] ?? '') ?>" required maxlength="80" placeholder="Ex. MailCow"></label>
        <label class="field"><span>Adresse</span>
          <input name="url" value="<?= e($v['url'] ?? '') ?>" required placeholder="mail.leo44.cloud ou 192.168.1.10:8080" inputmode="url" autocapitalize="none" spellcheck="false"></label>
        <?php $k = $v['kind'] ?? 'auto'; if (!empty($_POST['kind']) && $errors) { $k = $_POST['kind']; } ?>
        <fieldset class="field seg-field"><span>Type d'accès</span>
          <div class="seg">
            <label><input type="radio" name="kind" value="auto" <?= $k === 'auto' ? 'checked' : '' ?>><span>Automatique</span></label>
            <label><input type="radio" name="kind" value="public" <?= $k === 'public' ? 'checked' : '' ?>><span>🌐 Public</span></label>
            <label><input type="radio" name="kind" value="private" <?= $k === 'private' ? 'checked' : '' ?>><span>🔒 Privé</span></label>
          </div>
          <small class="muted">Privé : adresse du réseau local (IP + port). Sans « http:// », il est ajouté automatiquement.</small>
        </fieldset>
        <label class="field"><span>Description <em>facultatif</em></span>
          <input name="description" value="<?= e($v['description'] ?? '') ?>" maxlength="160" placeholder="Messagerie"></label>
        <div class="field-row">
          <label class="field"><span>Icône <em>emoji</em></span>
            <input name="icon" value="<?= e($v['icon'] ?? '') ?>" maxlength="4" placeholder="📧" class="icon-input"></label>
          <label class="field"><span>Catégorie</span>
            <select name="category_id">
              <option value="0">Sans catégorie</option>
              <?php foreach ($cats as $c): ?>
                <option value="<?= (int) $c['id'] ?>" <?= (int) ($v['category_id'] ?? 0) === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
              <?php endforeach; ?>
            </select></label>
          <label class="field field-xs"><span>Ordre</span>
            <input name="position" type="number" value="<?= (int) ($v['position'] ?? 0) ?>"></label>
        </div>
        <div class="row">
          <button class="btn btn-primary" type="submit">Enregistrer</button>
          <?php if ($edit || !empty($v['id'])): ?><a class="btn btn-ghost" href="/admin.php?tab=liens">Annuler</a><?php endif; ?>
        </div>
      </form>
    </section>

    <section class="panel">
      <h2>Liens <span class="count"><?= count($links) ?></span></h2>
      <?php if (!$links): ?><p class="muted">Aucun lien. Ajoute le premier avec le formulaire.</p><?php else: ?>
      <table class="table">
        <thead><tr><th></th><th>Titre</th><th>Catégorie</th><th class="num">Ordre</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($links as $l): ?>
          <tr>
            <td class="ic-cell"><?= e($l['icon'] ?: '·') ?></td>
            <td><strong><?= e($l['title']) ?></strong><?= $l['kind'] === 'private' ? ' <span class="badge badge-priv">Privé</span>' : '' ?><br><span class="host mono"><?= e(link_host($l['url'])) ?></span></td>
            <td><?= e($catNames[$l['category_id']] ?? '—') ?></td>
            <td class="num"><?= (int) $l['position'] ?></td>
            <td class="actions">
              <a class="btn btn-ghost btn-sm" href="/admin.php?tab=liens&amp;edit=<?= (int) $l['id'] ?>">Modifier</a>
              <form method="post" data-confirm="Supprimer le lien « <?= e($l['title']) ?> » ?">
                <?= csrf_field() ?><input type="hidden" name="action" value="link_delete"><input type="hidden" name="id" value="<?= (int) $l['id'] ?>">
                <button class="btn btn-danger btn-sm" type="submit">Supprimer</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </section>
  </div>

<?php elseif ($tab === 'notes'):
    $notes = db()->query('SELECT * FROM notes ORDER BY position, id')->fetchAll();
    $edit = null;
    if (isset($_GET['edit'])) {
        $st = db()->prepare('SELECT * FROM notes WHERE id = ?');
        $st->execute([(int) $_GET['edit']]);
        $edit = $st->fetch() ?: null;
    }
    $v = $errors ? $_POST : ($edit ?? []);
    ?>
  <div class="cols cols-form">
    <section class="panel">
      <h2><?= !empty($v['id']) ? 'Modifier la note' : 'Ajouter une note' ?></h2>
      <form method="post" class="form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="note_save">
        <input type="hidden" name="id" value="<?= (int) ($v['id'] ?? 0) ?>">
        <label class="field"><span>Titre <em>facultatif</em></span>
          <input name="title" value="<?= e($v['title'] ?? '') ?>" maxlength="80" placeholder="Ex. Wi-Fi invités"></label>
        <label class="field"><span>Texte</span>
          <textarea name="body" rows="8" required maxlength="5000" placeholder="Écris ta note ici…"><?= e($v['body'] ?? '') ?></textarea></label>
        <div class="field-row">
          <label class="field"><span>Catégorie</span>
            <select name="category_id">
              <option value="0">Aucune (section Notes)</option>
              <?php foreach ($cats as $c): ?>
                <option value="<?= (int) $c['id'] ?>" <?= (int) ($v['category_id'] ?? 0) === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
              <?php endforeach; ?>
            </select></label>
          <label class="field field-xs"><span>Ordre</span>
            <input name="position" type="number" value="<?= (int) ($v['position'] ?? 0) ?>"></label>
        </div>
        <p class="muted small">Les notes sont visibles par tous les comptes connectés. N'y mets pas de mot de passe important.</p>
        <div class="row">
          <button class="btn btn-primary" type="submit">Enregistrer</button>
          <?php if (!empty($v['id'])): ?><a class="btn btn-ghost" href="/admin.php?tab=notes">Annuler</a><?php endif; ?>
        </div>
      </form>
    </section>

    <section class="panel">
      <h2>Notes <span class="count"><?= count($notes) ?></span></h2>
      <?php if (!$notes): ?><p class="muted">Aucune note. Ajoute la première avec le formulaire.</p><?php else: ?>
      <table class="table">
        <thead><tr><th>Note</th><th>Catégorie</th><th class="num">Ordre</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($notes as $n): ?>
          <tr>
            <td><strong><?= e($n['title'] !== '' ? $n['title'] : 'Sans titre') ?></strong><br>
              <span class="muted small"><?= e(mb_strimwidth(preg_replace('/\s+/', ' ', $n['body']), 0, 70, '…')) ?></span></td>
            <td><?= e($catNames[$n['category_id']] ?? '—') ?></td>
            <td class="num"><?= (int) $n['position'] ?></td>
            <td class="actions">
              <a class="btn btn-ghost btn-sm" href="/admin.php?tab=notes&amp;edit=<?= (int) $n['id'] ?>">Modifier</a>
              <form method="post" data-confirm="Supprimer cette note ?">
                <?= csrf_field() ?><input type="hidden" name="action" value="note_delete"><input type="hidden" name="id" value="<?= (int) $n['id'] ?>">
                <button class="btn btn-danger btn-sm" type="submit">Supprimer</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </section>
  </div>

<?php elseif ($tab === 'categories'):
    $edit = null;
    foreach ($cats as $c) {
        if ((int) ($_GET['edit'] ?? 0) === (int) $c['id']) {
            $edit = $c;
        }
    }
    ?>
  <div class="cols cols-form">
    <section class="panel">
      <h2><?= $edit ? 'Renommer la catégorie' : 'Nouvelle catégorie' ?></h2>
      <form method="post" class="form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="cat_save">
        <input type="hidden" name="id" value="<?= (int) ($edit['id'] ?? 0) ?>">
        <div class="field-row">
          <label class="field"><span>Nom</span><input name="name" value="<?= e($edit['name'] ?? '') ?>" required maxlength="60" placeholder="Ex. Serveurs"></label>
          <label class="field field-xs"><span>Ordre</span><input name="position" type="number" value="<?= (int) ($edit['position'] ?? 0) ?>"></label>
        </div>
        <div class="row">
          <button class="btn btn-primary" type="submit">Enregistrer</button>
          <?php if ($edit): ?><a class="btn btn-ghost" href="/admin.php?tab=categories">Annuler</a><?php endif; ?>
        </div>
      </form>
    </section>
    <section class="panel">
      <h2>Catégories <span class="count"><?= count($cats) ?></span></h2>
      <?php if (!$cats): ?><p class="muted">Aucune catégorie. Les liens s'affichent dans « Sans catégorie ».</p><?php else: ?>
      <table class="table">
        <thead><tr><th>Nom</th><th class="num">Liens</th><th class="num">Notes</th><th class="num">Ordre</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($cats as $c): ?>
          <tr>
            <td><strong><?= e($c['name']) ?></strong></td>
            <td class="num"><?= (int) $c['n'] ?></td>
            <td class="num"><?= (int) $c['nn'] ?></td>
            <td class="num"><?= (int) $c['position'] ?></td>
            <td class="actions">
              <a class="btn btn-ghost btn-sm" href="/admin.php?tab=categories&amp;edit=<?= (int) $c['id'] ?>">Modifier</a>
              <form method="post" data-confirm="Supprimer la catégorie « <?= e($c['name']) ?> » ? Ses liens seront conservés.">
                <?= csrf_field() ?><input type="hidden" name="action" value="cat_delete"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                <button class="btn btn-danger btn-sm" type="submit">Supprimer</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </section>
  </div>

<?php elseif ($tab === 'utilisateurs'):
    $users = db()->query('SELECT * FROM users ORDER BY username')->fetchAll();
    ?>
  <?php if ($tempPwd): ?>
    <section class="panel panel-accent">
      <h2><?= icon('key') ?> Mot de passe provisoire de « <?= e($tempPwd[0]) ?> »</h2>
      <p class="muted">Transmets-le de façon sûre. Il ne sera plus affiché. À la première connexion, la personne devra activer la double authentification puis choisir son propre mot de passe.</p>
      <div class="row"><code class="secret mono"><?= e($tempPwd[1]) ?></code>
        <button class="btn btn-ghost btn-sm" type="button" data-copy="<?= e($tempPwd[1]) ?>">Copier</button></div>
    </section>
  <?php endif; ?>
  <div class="cols cols-form">
    <section class="panel">
      <h2>Nouveau compte</h2>
      <form method="post" class="form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="user_create">
        <label class="field"><span>Identifiant</span>
          <input name="username" required pattern="[A-Za-z0-9._\-]{3,32}" maxlength="32" autocapitalize="none" spellcheck="false" autocomplete="off"></label>
        <label class="check"><input type="checkbox" name="is_admin" value="1"> Administrateur (peut gérer liens et comptes)</label>
        <button class="btn btn-primary" type="submit">Créer le compte</button>
        <p class="muted small">Un mot de passe provisoire sera généré.</p>
      </form>
    </section>
    <section class="panel">
      <h2>Comptes <span class="count"><?= count($users) ?></span></h2>
      <table class="table">
        <thead><tr><th>Identifiant</th><th>2FA</th><th>Dernière connexion</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($users as $u): $self = (int) $u['id'] === (int) $me['id']; ?>
          <tr>
            <td><strong><?= e($u['username']) ?></strong>
              <?php if ($u['is_admin']): ?><span class="badge">Admin</span><?php endif; ?>
              <?php if ($self): ?><span class="badge badge-muted">Toi</span><?php endif; ?>
              <?php if ($u['must_change_pwd']): ?><span class="badge badge-warn">MDP provisoire</span><?php endif; ?></td>
            <td><?= $u['totp_enabled'] ? '<span class="dot up"></span> Active' : '<span class="dot"></span> À configurer' ?></td>
            <td class="nowrap"><?= e(french_datetime($u['last_login_at'] ? (int) $u['last_login_at'] : null)) ?></td>
            <td class="actions">
              <form method="post" data-confirm="Générer un nouveau mot de passe provisoire pour « <?= e($u['username']) ?> » ?">
                <?= csrf_field() ?><input type="hidden" name="action" value="user_reset_pwd"><input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                <button class="btn btn-ghost btn-sm" type="submit">Nouveau MDP</button>
              </form>
              <?php if (!$self): ?>
              <form method="post" data-confirm="Réinitialiser la double authentification de « <?= e($u['username']) ?> » ?">
                <?= csrf_field() ?><input type="hidden" name="action" value="user_reset_2fa"><input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                <button class="btn btn-ghost btn-sm" type="submit">Réinit. 2FA</button>
              </form>
              <form method="post">
                <?= csrf_field() ?><input type="hidden" name="action" value="user_toggle_admin"><input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                <button class="btn btn-ghost btn-sm" type="submit"><?= $u['is_admin'] ? 'Retirer admin' : 'Rendre admin' ?></button>
              </form>
              <form method="post" data-confirm="Supprimer définitivement le compte « <?= e($u['username']) ?> » ?">
                <?= csrf_field() ?><input type="hidden" name="action" value="user_delete"><input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                <button class="btn btn-danger btn-sm" type="submit">Supprimer</button>
              </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </section>
  </div>

<?php else:
    $rows = db()->query('SELECT j.*, u.username FROM journal j LEFT JOIN users u ON u.id = j.user_id ORDER BY j.id DESC LIMIT 100')->fetchAll();
    ?>
  <section class="panel">
    <h2>Journal de sécurité <span class="count">100 derniers</span></h2>
    <table class="table">
      <thead><tr><th>Date</th><th>Compte</th><th>Événement</th><th>Adresse IP</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr><td class="nowrap"><?= e(french_datetime((int) $r['at'])) ?></td><td><?= e($r['username'] ?? '—') ?></td><td><?= e($r['event']) ?></td><td class="mono"><?= e($r['ip']) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </section>
<?php endif;
page_end();
