#!/bin/bash
# Supervision : la rotation horaire du journal d'erreur brut tourne-t-elle ? Le fichier ACTIF contient des secrets en clair ; si la rotation (donc le filtrage) ne tourne plus,
# l'exposition dépasse l'heure prévue. Échoue si la PREMIÈRE ligne du fichier actif date de plus de N heures.
#   check-rotation.sh [fichier-actif] [heures-max]     (défaut : /var/log/civipass/nginx/civipass-error.log, 2)
# À brancher sur la supervision (cron, agent de supervision). Nécessite GNU date (Linux).
set -u
FILE=${1:-/var/log/civipass/nginx/civipass-error.log}; MAX=${2:-2}
[ -f "$FILE" ] || { echo "KO  $FILE introuvable"; exit 1; }
first=$(head -n 1 "$FILE")
[ -n "$first" ] || { echo "ok  $FILE est vide"; exit 0; }
stamp=$(printf '%s' "$first" | sed -nE 's#^([0-9]{4})/([0-9]{2})/([0-9]{2}) ([0-9]{2}:[0-9]{2}:[0-9]{2}).*#\1-\2-\3 \4#p')
[ -n "$stamp" ] || { echo "KO  première ligne illisible dans $FILE (format nginx attendu)"; exit 1; }
then=$(date -d "$stamp" +%s 2>/dev/null) || { echo "KO  GNU date requis"; exit 1; }
age=$(( ($(date +%s) - then) / 3600 ))
if [ "$age" -gt "$MAX" ]; then
    echo "KO  la plus ancienne ligne brute de $FILE a ${age} h (maximum ${MAX} h) : la rotation horaire ne tourne pas (systemctl status civipass-nginx-logrotate.timer)"
    exit 1
fi
echo "ok  plus ancienne ligne brute : ${age} h (maximum ${MAX} h)"
