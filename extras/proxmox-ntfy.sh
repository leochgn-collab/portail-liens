#!/usr/bin/env bash
# Alertes ntfy des connexions à Proxmox (interface web et SSH).
#
# Installation, en root sur le serveur Proxmox :
#   wget -qO proxmox-ntfy.sh https://raw.githubusercontent.com/leochgn-collab/portail-liens/main/extras/proxmox-ntfy.sh
#   bash proxmox-ntfy.sh install
#
# Autres commandes :
#   bash /usr/local/bin/proxmox-ntfy.sh test        envoyer une notification de test
#   bash /usr/local/bin/proxmox-ntfy.sh uninstall   tout retirer

set -uo pipefail

CONF=/etc/proxmox-ntfy.conf
BIN=/usr/local/bin/proxmox-ntfy.sh
UNIT=/etc/systemd/system/proxmox-ntfy.service

# Une connexion web réussie n'est signalée qu'une fois par heure et par compte :
# l'interface de Proxmox renouvelle sa session régulièrement et chaque
# renouvellement apparaît dans le journal comme une nouvelle connexion.
SUCCES_PAUSE=3600
# Les échecs sont regroupés : une alerte par adresse toutes les 10 minutes au plus.
ECHEC_PAUSE=600

json() { # échappe une valeur pour du JSON
  local s=${1//\\/\\\\}
  s=${s//\"/\\\"}
  printf '%s' "${s//$'\n'/\\n}"
}

envoyer() { # envoyer "titre" "message" priorité "tag"
  local auth=()
  if [ -n "${NTFY_TOKEN:-}" ]; then
    if [[ $NTFY_TOKEN == *:* ]]; then
      auth=(-u "$NTFY_TOKEN")
    else
      auth=(-H "Authorization: Bearer $NTFY_TOKEN")
    fi
  fi
  curl -sS -m 8 -o /dev/null -w '%{http_code}' "${auth[@]}" -H 'Content-Type: application/json' \
    -d "{\"topic\":\"$(json "$NTFY_TOPIC")\",\"title\":\"$(json "$1")\",\"message\":\"$(json "$2")\",\"priority\":$3,\"tags\":[\"$4\"]}" \
    "${NTFY_URL%/}/"
}

ip_propre() { # ::ffff:192.168.1.20 → 192.168.1.20
  local ip=${1#::ffff:}
  printf '%s' "${ip:-inconnue}"
}

surveiller() {
  # shellcheck source=/dev/null
  . "$CONF"
  local hote; hote=$(hostname)
  declare -A dernier=()
  local maintenant ligne

  journalctl -f -n 0 -o cat \
    SYSLOG_IDENTIFIER=pvedaemon SYSLOG_IDENTIFIER=sshd SYSLOG_IDENTIFIER=sshd-session |
  while IFS= read -r ligne; do
    maintenant=$(date +%s)

    if [[ $ligne =~ successful\ auth\ for\ user\ \'([^\']+)\' ]]; then
      local u=${BASH_REMATCH[1]}
      if (( maintenant - ${dernier["ok-$u"]:-0} >= SUCCES_PAUSE )); then
        dernier["ok-$u"]=$maintenant
        envoyer "Connexion à Proxmox" "$u s'est connecté à l'interface web de $hote." 4 "computer" >/dev/null
      fi

    elif [[ $ligne =~ authentication\ failure\;\ rhost=([^ ]*)\ user=([^ ]*) ]]; then
      local ip u; ip=$(ip_propre "${BASH_REMATCH[1]}"); u=${BASH_REMATCH[2]}
      if (( maintenant - ${dernier["ko-$ip"]:-0} >= ECHEC_PAUSE )); then
        dernier["ko-$ip"]=$maintenant
        envoyer "Échec de connexion à Proxmox" "Mot de passe refusé pour $u sur l'interface web de $hote.
IP : $ip" 4 "warning" >/dev/null
      fi

    elif [[ $ligne =~ Accepted\ ([a-z-]+)\ for\ ([^ ]+)\ from\ ([^ ]+) ]]; then
      local u=${BASH_REMATCH[2]} ip=${BASH_REMATCH[3]}
      envoyer "Connexion SSH à Proxmox" "$u s'est connecté en SSH à $hote.
IP : $ip" 4 "key" >/dev/null

    elif [[ $ligne =~ Failed\ [a-z-]+\ for\ (invalid\ user\ )?([^ ]+)\ from\ ([^ ]+) ]]; then
      local u=${BASH_REMATCH[2]} ip=${BASH_REMATCH[3]}
      if (( maintenant - ${dernier["ko-$ip"]:-0} >= ECHEC_PAUSE )); then
        dernier["ko-$ip"]=$maintenant
        envoyer "Échec SSH sur Proxmox" "Connexion SSH refusée pour $u sur $hote.
IP : $ip" 4 "warning" >/dev/null
      fi
    fi
  done
}

installer() {
  [ "$(id -u)" -eq 0 ] || { echo "Lance ce script en root." >&2; exit 1; }
  command -v curl >/dev/null || apt-get install -y -q curl

  local url topic token
  read -r -p "Adresse du serveur ntfy [http://192.168.1.208:2586] : " url </dev/tty
  read -r -p "Sujet (topic) [portail-alertes] : " topic </dev/tty
  read -r -p "Jeton d'accès ntfy (tk_…) : " token </dev/tty
  url=${url:-http://192.168.1.208:2586}
  topic=${topic:-portail-alertes}

  umask 077
  cat > "$CONF" <<EOF
NTFY_URL='$url'
NTFY_TOPIC='$topic'
NTFY_TOKEN='$token'
EOF
  chmod 600 "$CONF"

  [ "$(readlink -f "$0")" = "$BIN" ] || install -m 755 "$0" "$BIN"

  cat > "$UNIT" <<EOF
[Unit]
Description=Alertes ntfy des connexions Proxmox
After=network-online.target
Wants=network-online.target

[Service]
ExecStart=$BIN run
Restart=always
RestartSec=10

[Install]
WantedBy=multi-user.target
EOF
  systemctl daemon-reload
  systemctl enable --now proxmox-ntfy.service >/dev/null 2>&1
  systemctl restart proxmox-ntfy.service

  tester
  echo "Service installé et démarré (systemctl status proxmox-ntfy)."
}

tester() {
  # shellcheck source=/dev/null
  . "$CONF"
  local code
  code=$(envoyer "Test Proxmox" "Les alertes de connexion de $(hostname) sont actives." 3 "white_check_mark")
  case "$code" in
    2??) echo "✔ Notification de test envoyée : regarde ton téléphone." ;;
    401|403) echo "✘ ntfy refuse l'accès (code $code) : vérifie le jeton et les droits sur le sujet." ;;
    *) echo "✘ Échec de l'envoi (code ${code:-aucun}) : vérifie l'adresse du serveur ntfy." ;;
  esac
}

desinstaller() {
  systemctl disable --now proxmox-ntfy.service 2>/dev/null
  rm -f "$UNIT" "$BIN" "$CONF"
  systemctl daemon-reload
  echo "Alertes Proxmox retirées."
}

case "${1:-}" in
  install)   installer ;;
  run)       surveiller ;;
  test)      tester ;;
  uninstall) desinstaller ;;
  *) echo "Usage : bash $0 install|test|uninstall" ; exit 1 ;;
esac
