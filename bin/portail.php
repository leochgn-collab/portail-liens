<?php
declare(strict_types=1);

// Outil en ligne de commande, à lancer en tant que www-data :
//   runuser -u www-data -- php /var/www/portail-liens/bin/portail.php create-admin leo
//   runuser -u www-data -- php /var/www/portail-liens/bin/portail.php reset-password leo
//   runuser -u www-data -- php /var/www/portail-liens/bin/portail.php reset-2fa leo
//   runuser -u www-data -- php /var/www/portail-liens/bin/portail.php list
//   runuser -u www-data -- php /var/www/portail-liens/bin/portail.php bans
//   runuser -u www-data -- php /var/www/portail-liens/bin/portail.php unban 1.2.3.4   (ou : unban all)

if (PHP_SAPI !== 'cli') {
    exit(1);
}
$_SERVER['REMOTE_ADDR'] = 'console';
require dirname(__DIR__) . '/src/bootstrap.php';

function temp_pwd(): string
{
    $a = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $p = '';
    for ($i = 0; $i < 16; $i++) {
        $p .= $a[random_int(0, strlen($a) - 1)];
    }
    return implode('-', str_split($p, 4));
}

function user_by_name(string $name): array
{
    $st = db()->prepare('SELECT * FROM users WHERE username = ?');
    $st->execute([$name]);
    $u = $st->fetch();
    if (!$u) {
        fwrite(STDERR, "Compte « $name » introuvable.\n");
        exit(1);
    }
    return $u;
}

$cmd  = $argv[1] ?? '';
$name = $argv[2] ?? '';

switch ($cmd) {
    case 'create-admin':
        if (!preg_match('/^[A-Za-z0-9._-]{3,32}$/', $name)) {
            fwrite(STDERR, "Identifiant invalide (3 à 32 caractères : lettres, chiffres, . _ -).\n");
            exit(1);
        }
        $tmp = temp_pwd();
        try {
            db()->prepare('INSERT INTO users (username, password_hash, is_admin, must_change_pwd, created_at) VALUES (?,?,1,1,?)')
                ->execute([$name, password_hash($tmp, password_algo()), time()]);
        } catch (PDOException) {
            fwrite(STDERR, "Ce compte existe déjà. Utilise reset-password.\n");
            exit(1);
        }
        log_event(null, "Administrateur « $name » créé en console");
        echo "\nCompte administrateur créé.\n  Identifiant : $name\n  Mot de passe provisoire : $tmp\n\n";
        echo "À la première connexion : activation de la double authentification, puis choix d'un nouveau mot de passe.\n";
        break;

    case 'reset-password':
        $u = user_by_name($name);
        $tmp = temp_pwd();
        db()->prepare('UPDATE users SET password_hash=?, must_change_pwd=1, pwd_version=pwd_version+1 WHERE id=?')
            ->execute([password_hash($tmp, password_algo()), $u['id']]);
        db()->prepare('DELETE FROM attempts')->execute();
        db()->prepare('DELETE FROM bans')->execute();
        log_event((int) $u['id'], 'Mot de passe réinitialisé en console');
        echo "Nouveau mot de passe provisoire pour $name : $tmp\n";
        break;

    case 'reset-2fa':
        $u = user_by_name($name);
        db()->prepare('UPDATE users SET totp_enabled=0, totp_secret=NULL, totp_last_step=0, pwd_version=pwd_version+1 WHERE id=?')
            ->execute([$u['id']]);
        db()->prepare('DELETE FROM recovery_codes WHERE user_id=?')->execute([$u['id']]);
        db()->prepare('DELETE FROM attempts')->execute();
        log_event((int) $u['id'], 'Double authentification réinitialisée en console');
        echo "Double authentification de $name réinitialisée : elle sera reconfigurée à la prochaine connexion.\n";
        break;

    case 'list':
        foreach (db()->query('SELECT username, is_admin, totp_enabled FROM users ORDER BY username') as $u) {
            printf("%-24s %-6s 2FA %s\n", $u['username'], $u['is_admin'] ? 'admin' : '', $u['totp_enabled'] ? 'active' : 'à configurer');
        }
        break;

    case 'bans':
        $n = 0;
        foreach (db()->query('SELECT * FROM bans WHERE until > ' . time() . ' ORDER BY until') as $b) {
            printf("%-40s blocage n°%d jusqu'au %s\n", $b['ip'], $b['level'], date('d/m/Y H:i', (int) $b['until']));
            $n++;
        }
        echo $n ? '' : "Aucune adresse bloquée.\n";
        break;

    case 'unban':
        if ($name === 'all') {
            db()->exec('DELETE FROM bans');
            db()->exec("DELETE FROM attempts");
            log_event(null, 'Toutes les adresses débloquées en console');
            echo "Toutes les adresses et tous les comptes sont débloqués.\n";
        } elseif (filter_var($name, FILTER_VALIDATE_IP)) {
            unban_ip($name);
            log_event(null, "Adresse IP $name débloquée en console");
            echo "Adresse $name débloquée.\n";
        } else {
            fwrite(STDERR, "Indique une adresse IP, ou « all » pour tout débloquer.\n");
            exit(1);
        }
        break;

    default:
        echo "Usage : php bin/portail.php create-admin|reset-password|reset-2fa|list|bans|unban <identifiant ou IP>\n";
        exit(1);
}
