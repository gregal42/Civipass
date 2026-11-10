#!/bin/bash
# Scénarios de rotation EXÉCUTÉS DANS un conteneur Debian jetable avec un vrai nginx (amont PHP volontairement mort : chaque requête écrit une erreur brute avec son secret) et un vrai
# logrotate. Lancé par verify-rotation.sh, jamais directement sur un serveur. Sortie : lignes « ok » / « KO » ; code de sortie non nul au moindre KO.
set -u
LOG=/var/log/civipass/nginx; ERR=$LOG/civipass-error.log; CONF=/etc/civipass/nginx/logrotate.conf; STATE=/var/lib/civipass/logrotate-nginx.status
SAN=/usr/local/sbin/civipass-sanitize-nginx-error
fail=0
ok()  { echo "ok  $*"; }
ko()  { echo "KO  $*"; fail=$((fail + 1)); }
send() { # $1 = nombre de requêtes ; secret reconnaissable, unique
    local i
    for i in $(seq 1 "$1"); do
        curl -sk --resolve civipass.test:443:127.0.0.1 -o /dev/null --path-as-is "https://civipass.test/laissez-passer/ZZsecretZZ$(date +%s%N)${RANDOM}${RANDOM}abcdefghijkl?q=DupontMartinSentinelle"
    done
}
count() { grep -c "$1" "$2" 2>/dev/null || true; }
secrets_in() { grep -c -E 'ZZsecretZZ|DupontMartin' "$1" 2>/dev/null || true; }
rotate() { logrotate -f -s "$STATE" "$CONF" 2>/tmp/rotate.err; echo $? > /tmp/rotate.rc; }
archives() { ls "$LOG"/civipass-error.log.* 2>/dev/null; }
all_lines() { local n=0 f; for f in "$ERR" $(archives); do case "$f" in *.gz) n=$((n + $(zcat "$f" | wc -l)));; *) n=$((n + $(wc -l < "$f")));; esac; done; echo $n; }
secrets_archived() { local n=0 f c; for f in $(archives); do case "$f" in *.gz) c=$(zcat "$f" | grep -c -E 'ZZsecretZZ|DupontMartin');; *) c=$(secrets_in "$f");; esac; n=$((n + c)); done; echo $n; }
reset_logs() { rm -f "$LOG"/civipass-error.log.* "$STATE"; : > "$ERR"; chown nginx:adm "$ERR"; chmod 0640 "$ERR"; kill -USR1 "$(cat /run/nginx.pid)"; sleep 1; }

echo "== S1 : séquence de base (écriture, rotation, réouverture, filtrage)"
reset_logs
send 40
before=$(wc -l < "$ERR"); raw=$(secrets_in "$ERR")
[ "$before" -eq 40 ] && ok "40 requêtes = 40 lignes d'erreur brutes ($raw avec secret : exposition du fichier ACTIF)" || ko "lignes d'erreur attendues 40, obtenues $before"
pid_before=$(cat /run/nginx.pid)
rotate
[ "$(cat /tmp/rotate.rc)" -eq 0 ] && ok "logrotate : code de sortie 0" || { ko "logrotate a échoué"; cat /tmp/rotate.err; }
[ "$(wc -l < "$ERR.1")" -eq 40 ] && ok "fichier tourné : 40 lignes conservées (aucune perte)" || ko "fichier tourné : $(wc -l < "$ERR.1") lignes"
[ "$(secrets_in "$ERR.1")" -eq 0 ] && ok "fichier tourné : 0 secret" || ko "fichier tourné : secrets présents"
[ "$(cat /run/nginx.pid)" = "$pid_before" ] && ok "nginx n'a pas été redémarré (même PID $pid_before)" || ko "PID nginx modifié"
send 3; sleep 1
[ "$(wc -l < "$ERR")" -eq 3 ] && ok "nginx a rouvert le journal : 3 nouvelles lignes dans le fichier actif" || ko "réouverture : $(wc -l < "$ERR") ligne(s) dans le fichier actif"
[ "$(wc -l < "$ERR.1")" -eq 40 ] && ok "l'ancien fichier ne reçoit plus rien" || ko "l'ancien fichier a reçu des écritures"
echo "    permissions : $(stat -c '%U:%G %a %n' "$ERR" "$ERR.1" "$LOG" | tr '\n' ' ')"
[ "$(stat -c '%a' "$ERR.1")" = "640" ] && [ "$(stat -c '%G' "$ERR.1")" = "adm" ] && ok "fichier tourné : 0640, groupe adm" || ko "permissions du fichier tourné"
[ "$(stat -c '%a' "$ERR")" = "640" ] && [ "$(stat -c '%G' "$ERR")" = "adm" ] && ok "fichier actif : 0640, groupe adm" || ko "permissions du fichier actif"
[ "$(stat -c '%a %U:%G' "$LOG")" = "750 nginx:adm" ] && ok "dossier des journaux : 0750 nginx:adm (les workers doivent pouvoir rouvrir leurs fichiers)" || ko "permissions du dossier : $(stat -c '%a %U:%G' "$LOG")"
rotate; rotate
[ "$(secrets_archived)" -eq 0 ] && ok "après trois rotations : 0 secret dans toutes les archives ($(archives | wc -l) fichier(s))" || ko "secrets dans les archives"
ls "$LOG" | grep -qE '\.tmp$|~$|\.bak$|\.raw$' && ko "fichier temporaire ou copie résiduelle" || ok "aucun fichier temporaire ni copie résiduelle"

echo "== S2 : écritures PENDANT les rotations (trafic continu, 6 rotations)"
reset_logs
echo 0 > /dev/shm/sent
( while :; do send 1; echo $(( $(cat /dev/shm/sent) + 1 )) > /dev/shm/sent; done ) &
sender=$!
for i in 1 2 3 4 5 6; do sleep 2; rotate; [ "$(cat /tmp/rotate.rc)" -eq 0 ] || ko "rotation $i : code $(cat /tmp/rotate.rc)"; done
kill "$sender" 2>/dev/null; wait "$sender" 2>/dev/null; sleep 1
sent=$(cat /dev/shm/sent); total=$(all_lines)
echo "    requêtes envoyées : $sent ; lignes retrouvées (actif + archives) : $total"
[ "$total" -ge "$sent" ] && ok "aucune ligne perdue pendant les rotations" || ko "$((sent - total)) ligne(s) perdue(s)"
[ "$(secrets_archived)" -eq 0 ] && ok "0 secret dans les archives malgré le trafic continu" || ko "$(secrets_archived) secret(s) dans les archives"
echo "    secrets dans le fichier ACTIF (exposition résiduelle, bornée à une heure en production) : $(secrets_in "$ERR")"

echo "== S3 : échec du filtre principal (repli grossier)"
reset_logs; cp "$SAN" /tmp/san.good; printf '#!/bin/sh\nexit 1\n' > "$SAN"; chmod 0755 "$SAN"
send 20; rotate; rc=$(cat /tmp/rotate.rc)
[ "$rc" -ne 0 ] && ok "logrotate signale l'échec (code $rc) : visible par systemd (service en échec)" || ko "échec silencieux (code 0)"
grep -q 'filtre principal en échec' /tmp/rotate.err && ok "alerte écrite (sortie d'erreur et journal système)" || ko "aucune alerte"
[ "$(wc -l < "$ERR.1")" -eq 20 ] && ok "20 lignes conservées (diagnostic partiel : heure, erreur, client, amont)" || ko "lignes conservées : $(wc -l < "$ERR.1")"
[ "$(secrets_in "$ERR.1")" -eq 0 ] && ok "0 secret dans le fichier tourné" || ko "secrets conservés"
grep -q 'request: "\[masque\]"' "$ERR.1" && ok "repli grossier appliqué (champs request/referrer remplacés)" || ko "repli non appliqué"
cp /tmp/san.good "$SAN"; chmod 0755 "$SAN"

echo "== S4 : filtre ET repli en échec (aucun brut conservé, perte VISIBLE)"
reset_logs; printf '#!/bin/sh\nexit 1\n' > "$SAN"; chmod 0755 "$SAN"
send 10; mkdir -p "$ERR.1.tmp"; rotate; rc=$(cat /tmp/rotate.rc); rmdir "$ERR.1.tmp" 2>/dev/null
[ "$rc" -ne 0 ] && ok "logrotate signale l'échec (code $rc)" || ko "échec silencieux"
grep -q 'SUPPRIME' "$ERR.1" && [ "$(wc -l < "$ERR.1")" -eq 1 ] && ok "le fichier tourné ne contient plus qu'une ligne qui DIT ce qui s'est passé : $(cut -c1-120 "$ERR.1")" || ko "perte silencieuse ou brut conservé"
grep -q 'supprimée' /tmp/rotate.err && ok "alerte critique écrite (nombre de lignes perdues)" || ko "aucune alerte critique"
[ "$(secrets_in "$ERR.1")" -eq 0 ] && ok "0 secret conservé" || ko "secrets conservés"
cp /tmp/san.good "$SAN"; chmod 0755 "$SAN"

echo "== S5 : rotation interrompue (fichier tourné resté brut) : rattrapage avant la rotation suivante"
reset_logs; send 15; cp "$ERR" "$ERR.1"; : > "$ERR"
[ "$(secrets_in "$ERR.1")" -gt 0 ] && ok "situation simulée : un .1 BRUT existe ($(secrets_in "$ERR.1") lignes avec secret)" || ko "simulation"
send 2; rotate
[ "$(secrets_archived)" -eq 0 ] && ok "la rotation suivante l'a filtré avant de le compresser : 0 secret dans toutes les archives" || ko "le brut a été archivé : $(secrets_archived) secret(s)"

echo "== S6 : aucune copie du journal brut ailleurs sur le système"
reset_logs; send 10; rotate; rotate; send 5
stray=$(grep -rIl -E 'ZZsecretZZ|DupontMartin' /var/log /var/lib /etc /root /tmp /var/tmp /usr/local 2>/dev/null | grep -v "^$ERR$" | grep -v '^/tmp/\(san.good\|rotate.err\)$' || true)
[ -z "$stray" ] && ok "aucun secret hors du fichier actif (journaux, /tmp, /var/lib, /etc)" || { ko "copies trouvées :"; echo "$stray" | sed 's/^/      /'; }
find "$LOG" -type f -perm /o=rwx | grep -q . && ko "fichier lisible par tous" || ok "aucun fichier de journal accessible à « autres »"

echo "== S7 : dossier des journaux inaccessible à l'utilisateur nginx (erreur de déploiement) : le crochet doit le DIRE"
reset_logs; chown root:adm "$LOG"; send 3; rotate; rc=$(cat /tmp/rotate.rc)
[ "$rc" -ne 0 ] && grep -q "ne peut pas écrire" /tmp/rotate.err && ok "alerte critique et code $rc : la perte de journalisation est détectée à la rotation" || ko "défaut de permissions non détecté (code $rc)"
send 3; sleep 1
[ "$(wc -l < "$ERR")" -eq 0 ] && ok "constat : nginx ne journalise plus rien tant que les permissions ne sont pas corrigées" || ok "nginx journalise malgré tout"
chown nginx:adm "$LOG"; kill -USR1 "$(cat /run/nginx.pid)"; sleep 1; send 2; sleep 1
[ "$(wc -l < "$ERR")" -ge 2 ] && ok "après correction (chown nginx:adm) et réouverture, la journalisation reprend" || ko "journalisation non reprise"

echo; [ "$fail" -eq 0 ] && echo "RESULTAT : CONFORME" || echo "RESULTAT : $fail non-conformité(s)"
exit $((fail > 0))
