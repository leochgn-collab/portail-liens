#!/usr/bin/env bash
# Portail de liens — installation et mise à jour sur Debian 13 (Apache + PHP 8.4)
#
#   wget -qO install.sh https://raw.githubusercontent.com/leochgn-collab/portail-liens/main/install.sh
#   bash install.sh
#
# Relancer le script met à jour le portail (comptes, liens et notes sont conservés).

set -euo pipefail

REPO="https://github.com/leochgn-collab/portail-liens.git"
DIR="/var/www/portail-liens"
PHPV="8.4"
CONF="/etc/apache2/sites-available/portail-liens.conf"

vert()  { printf '\033[1;32m%s\033[0m\n' "$*"; }
jaune() { printf '\033[1;33m%s\033[0m\n' "$*"; }
rouge() { printf '\033[1;31m%s\033[0m\n' "$*" >&2; }
etape() { printf '\n\033[1;34m▶ %s\033[0m\n' "$*"; }
demander() { # demander "Question" "défaut" → réponse dans $REPONSE
  local q="$1" def="${2:-}"
  if [ -n "$def" ]; then printf '%s [%s] : ' "$q" "$def"; else printf '%s : ' "$q"; fi
  read -r REPONSE </dev/tty || REPONSE=""
  REPONSE="${REPONSE:-$def}"
}

# Écrit le VirtualHost du portail (port 80 ; le HTTPS est géré par le proxy ou par certbot).
ecrire_vhost() {
  local domaine="$1"
  cat > "$CONF" <<EOF
<VirtualHost *:80>
    ServerName $domaine
    ServerAlias www.$domaine
    DocumentRoot $DIR/public
    DirectoryIndex index.php

    <Directory $DIR/public>
        Options -Indexes +FollowSymLinks
        AllowOverride None
        Require all granted
        <LimitExcept GET POST>
            Require all denied
        </LimitExcept>
    </Directory>

    <FilesMatch "\.php\$">
        SetHandler "proxy:unix:/run/php/php$PHPV-fpm.sock|fcgi://localhost"
    </FilesMatch>

    <DirectoryMatch "/\.(?!well-known)">
        Require all denied
    </DirectoryMatch>

    <IfModule mod_headers.c>
        Header always unset X-Powered-By
        Header set X-Content-Type-Options "nosniff"
        Header set X-Robots-Tag "noindex, nofollow"
        Header setifempty Referrer-Policy "no-referrer"
        Header setifempty X-Frame-Options "DENY"
        Header setifempty Strict-Transport-Security "max-age=31536000"
    </IfModule>

    <IfModule mod_expires.c>
        ExpiresActive On
        ExpiresByType text/css "access plus 7 days"
        ExpiresByType application/javascript "access plus 7 days"
        ExpiresByType image/svg+xml "access plus 30 days"
    </IfModule>

    ErrorLog \${APACHE_LOG_DIR}/portail-liens_error.log
    CustomLog \${APACHE_LOG_DIR}/portail-liens_access.log combined
</VirtualHost>
EOF
}

# Masque la version d'Apache et de Debian, désactive TRACE.
durcir_apache() {
  cat > /etc/apache2/conf-available/zz-portail-durcissement.conf <<'EOF'
# Ajouté par le Portail de liens
ServerTokens Prod
ServerSignature Off
TraceEnable Off
EOF
  a2enconf -q zz-portail-durcissement >/dev/null
}

# Tout le script est dans main() : bash le lit en entier avant de l'exécuter,
# ce qui permet à la mise à jour de remplacer ce fichier sans danger.
main() {
[ "$(id -u)" -eq 0 ] || { rouge "Lance ce script en root (su -)."; exit 1; }
. /etc/os-release
[ "${ID:-}" = "debian" ] || jaune "Attention : script prévu pour Debian (détecté : ${PRETTY_NAME:-inconnu})."

# ─── Mise à jour d'une installation existante ──────────────────────────
if [ -d "$DIR/.git" ] && [ -f "$CONF" ]; then
  etape "Installation existante trouvée : mise à jour"
  # Le dossier appartient à root:www-data : on indique à git qu'il est sûr.
  git config --global --get-all safe.directory 2>/dev/null | grep -qx "$DIR" \
    || git config --global --add safe.directory "$DIR"
  git -C "$DIR" fetch --quiet origin
  git -C "$DIR" reset --quiet --hard origin/main
  chown -R root:www-data "$DIR"
  chmod -R u=rwX,g=rX,o= "$DIR"
  chown -R www-data:www-data "$DIR/data"
  chmod 700 "$DIR/data"
  [ -f "$DIR/config.local.php" ] && chmod 640 "$DIR/config.local.php"
  if [ ! -e /etc/apache2/sites-enabled/portail-liens-le-ssl.conf ]; then
    ecrire_vhost "$(awk '/^[[:space:]]*ServerName/ {print $2; exit}' "$CONF")"
  fi
  durcir_apache
  apache2ctl configtest
  systemctl reload "php$PHPV-fpm" apache2
  vert "✔ Portail mis à jour (version $(git -C "$DIR" log -1 --format='%h du %cd' --date=format:'%d/%m/%Y'))."
  exit 0
fi

# ─── Questions ─────────────────────────────────────────────────────────
echo
vert "Installation du Portail de liens"
echo
demander "Domaine du portail" "leo44.cloud"; DOMAINE="$REPONSE"
demander "Nom affiché en haut du portail" "Mon Portail"; NOM="$REPONSE"
echo
echo "Comment le portail est-il joint depuis internet ?"
echo "  1) Par un reverse proxy (Zoraxy, Nginx Proxy Manager…) qui gère le HTTPS  [recommandé si tu as Zoraxy]"
echo "  2) Directement : ce serveur reçoit les ports 80/443 (certificat Let's Encrypt via certbot)"
demander "Choix" "1"; MODE="$REPONSE"
PROXY=""
if [ "$MODE" = "1" ]; then
  demander "Adresse IP du reverse proxy" "192.168.1.104"; PROXY="$REPONSE"
fi
demander "Identifiant du compte administrateur" "leo"; ADMIN="$REPONSE"

# ─── Paquets ───────────────────────────────────────────────────────────
etape "Installation d'Apache, PHP $PHPV et des outils"
export DEBIAN_FRONTEND=noninteractive
apt-get update -q
PAQUETS="apache2 php$PHPV-fpm php$PHPV-sqlite3 php$PHPV-mbstring php$PHPV-curl git ca-certificates"
[ "$MODE" = "2" ] && PAQUETS="$PAQUETS certbot python3-certbot-apache"
# shellcheck disable=SC2086
apt-get install -y -q $PAQUETS

# ─── Code ──────────────────────────────────────────────────────────────
etape "Téléchargement du portail dans $DIR"
if [ -d "$DIR" ] && [ ! -d "$DIR/.git" ]; then
  SAUVE="/root/portail-liens-ancien-$(date +%Y%m%d-%H%M%S)"
  jaune "Un ancien dossier $DIR existe : il est déplacé dans $SAUVE (ta base de données est récupérée)."
  mv "$DIR" "$SAUVE"
fi
[ -d "$DIR/.git" ] || git clone --quiet "$REPO" "$DIR"
git config --global --get-all safe.directory 2>/dev/null | grep -qx "$DIR" \
  || git config --global --add safe.directory "$DIR"
mkdir -p "$DIR/data"
if [ -n "${SAUVE:-}" ] && [ -d "$SAUVE/data" ]; then
  cp -a "$SAUVE/data/." "$DIR/data/" 2>/dev/null || true
fi

NOM_PHP=$(printf '%s' "$NOM" | sed "s/'/\\\\'/g")
cat > "$DIR/config.local.php" <<EOF
<?php
// Réglages propres à ce serveur (conservés lors des mises à jour).
const APP_NAME = '$NOM_PHP';
const TRUSTED_PROXIES = '$PROXY';
EOF

chown -R root:www-data "$DIR"
chmod -R u=rwX,g=rX,o= "$DIR"
chown -R www-data:www-data "$DIR/data"
chmod 700 "$DIR/data"
chmod 640 "$DIR/config.local.php"

# ─── Apache ────────────────────────────────────────────────────────────
etape "Configuration d'Apache"
a2dismod -q "php$PHPV" mpm_prefork >/dev/null 2>&1 || true
a2enmod -q mpm_event proxy_fcgi setenvif headers expires >/dev/null
[ "$MODE" = "2" ] && a2enmod -q ssl rewrite >/dev/null
systemctl enable --now "php$PHPV-fpm" >/dev/null

ecrire_vhost "$DOMAINE"
durcir_apache
a2dissite -q 000-default >/dev/null 2>&1 || true
a2ensite -q portail-liens >/dev/null
apache2ctl configtest
systemctl restart apache2

if [ "$MODE" = "2" ]; then
  etape "Certificat HTTPS (Let's Encrypt)"
  if ! certbot --apache -d "$DOMAINE" -d "www.$DOMAINE" --redirect; then
    jaune "Certbot a échoué. Vérifie que $DOMAINE et www.$DOMAINE pointent vers ce serveur, puis relance :"
    jaune "  certbot --apache -d $DOMAINE -d www.$DOMAINE --redirect"
  fi
fi

# ─── Compte administrateur ─────────────────────────────────────────────
etape "Compte administrateur"
NB=$(runuser -u www-data -- php "$DIR/bin/portail.php" list | grep -c . || true)
if [ "$NB" -eq 0 ]; then
  runuser -u www-data -- php "$DIR/bin/portail.php" create-admin "$ADMIN"
else
  echo "Des comptes existent déjà, aucun compte créé."
fi

# ─── Résumé ────────────────────────────────────────────────────────────
IP=$(hostname -I | awk '{print $1}')
echo
vert "✔ Portail installé."
echo
if [ "$MODE" = "1" ]; then
  echo "Dernière étape, dans Zoraxy : crée (ou modifie) la règle de proxy"
  echo "    $DOMAINE   →   http://$IP:80"
  echo "et active le HTTPS pour ce domaine. Ajoute aussi www.$DOMAINE si besoin."
  echo
fi
echo "Ouvre ensuite https://$DOMAINE et connecte-toi avec le mot de passe provisoire ci-dessus."
echo "Pour mettre à jour plus tard : bash $DIR/install.sh"
}

main "$@"
