#!/bin/bash
# Contrôle des paramètres d'environnement de l'application AVANT d'ouvrir le contrôle public. Lit (sans l'exécuter) un ou plusieurs fichiers .env (le dernier gagne) :
#   check-deployment.sh --domain civipass.exemple.fr [--control-domain controle.civipass.exemple.fr] .env .env.local [.env.prod.local]
# Échoue si : DEFAULT_URI n'est pas en https:// sur le domaine donné (les QR codes, liens bénéficiaires, invitations et réinitialisations sont bâtis à partir de DEFAULT_URI) ;
# APP_ENV n'est pas prod ; APP_DEBUG est actif ; SYMFONY_TRUSTED_PROXIES est défini (nginx est le premier saut) ; PUBLIC_VERIFICATION_ENABLED est actif (informatif :
# l'activation exige l'accord du responsable du projet, ce script ne la décide pas).
# Avec --control-domain (origine dédiée au contrôle) : CONTROL_HOST = ce domaine, TRUSTED_HOSTS limité aux deux domaines, CONTROL_PWA_BASE_URL = https://<domaine de contrôle>/controle/,
# domaines distincts, DEFAULT_URI toujours sur la gestion ; OFFLINE_CONTROL_ENABLED signalé (informatif).
# Secrets et exploitation (SEC-005) : APP_SECRET (32 caractères au moins, hors valeur d'exemple), DATABASE_URL (ni mot de passe d'exemple, ni compte root), MAILER_DSN (pas
# null://), NOTIFICATION_KEYRING et NOTIFICATION_ACTIVE_KEY_ID, OPS_ALERT_EMAIL. Aucune valeur n'est jamais affichée.
# OFFLINE_CONTROL_ENABLED actif SANS --control-domain : échec (SEC-013, la PWA partagerait l'origine des cookies de session).
# Avec --app-dir /opt/civipass : var/ et ses sous-dossiers ne doivent pas appartenir à root (une mise à jour lancée en root rendrait le cache inaccessible au pool
# PHP-FPM et les limiteurs inopérants, SEC-014 / 11.2-F5).
set -u
DOMAIN=""; CONTROL=""; APPDIR=""; FILES=()
while [ $# -gt 0 ]; do
    case "$1" in --domain) DOMAIN=${2:?}; shift 2;; --control-domain) CONTROL=${2:?}; shift 2;; --app-dir) APPDIR=${2:?}; shift 2;; *) FILES+=("$1"); shift;; esac
done
[ -n "$DOMAIN" ] && [ ${#FILES[@]} -gt 0 ] || { echo "usage: $0 --domain NOM [--control-domain NOM] [--app-dir CHEMIN] FICHIER.env [FICHIER.env ...]" >&2; exit 2; }
declare_get() { # $1 = clé ; dernière valeur trouvée
    local key=$1 val="" f line
    for f in "${FILES[@]}"; do
        [ -r "$f" ] || { echo "fichier illisible : $f" >&2; exit 2; }
        while IFS= read -r line || [ -n "$line" ]; do
            case "$line" in "$key="*) val=${line#"$key="};; esac
        done < "$f"
    done
    val=${val%\"}; val=${val#\"}; val=${val%\'}; val=${val#\'}
    printf '%s' "$val"
}
fail=0
ko() { echo "KO  $*"; fail=$((fail + 1)); }
ok() { echo "ok  $*"; }
uri=$(declare_get DEFAULT_URI)
case "$uri" in
    "https://$DOMAIN"|"https://$DOMAIN/") ok "DEFAULT_URI = $uri (QR codes et liens sensibles émis en https://)";;
    "") ko "DEFAULT_URI absent (les liens sensibles n'auraient pas de domaine fixe)";;
    *) ko "DEFAULT_URI = $uri : doit être https://$DOMAIN";;
esac
[ "$(declare_get APP_ENV)" = prod ] && ok "APP_ENV=prod" || ko "APP_ENV doit valoir prod (valeur : '$(declare_get APP_ENV)')"
case "$(declare_get APP_DEBUG)" in 1|true|on|yes) ko "APP_DEBUG actif (les pages d'erreur de débogage citent l'URL, jeton compris)";; *) ok "APP_DEBUG inactif";; esac
[ -z "$(declare_get SYMFONY_TRUSTED_PROXIES)" ] && ok "SYMFONY_TRUSTED_PROXIES vide (nginx est le premier saut)" || ko "SYMFONY_TRUSTED_PROXIES défini : aucun proxy de confiance en production mono-instance"
case "$(declare_get PUBLIC_VERIFICATION_ENABLED)" in 1|true|on|yes) echo "!!  PUBLIC_VERIFICATION_ENABLED actif : le contrôle public est OUVERT (exige l'accord explicite du responsable du projet)";; *) ok "PUBLIC_VERIFICATION_ENABLED inactif (contrôle public fermé)";; esac
# --- Secrets et exploitation (SEC-005) : on ne teste que la présence et la forme, jamais on n'affiche une valeur.
secret=$(declare_get APP_SECRET)
if [ -z "$secret" ]; then ko "APP_SECRET absent ou vide (les jetons CSRF et les liens signés ne seraient pas protégés)"
elif [ "${#secret}" -lt 32 ]; then ko "APP_SECRET trop court (${#secret} caractères ; 32 au moins : openssl rand -hex 32)"
else case "$secret" in *CHANGE_ME*|*ChangeMe*|*changeme*) ko "APP_SECRET est une valeur d'exemple";; *) ok "APP_SECRET défini (${#secret} caractères)";; esac; fi
dburl=$(declare_get DATABASE_URL)
if [ -z "$dburl" ]; then ko "DATABASE_URL absent"
else
    dbuser=${dburl#*://}; dbuser=${dbuser%%[:@]*}
    case "$dburl" in *CHANGE_ME*|*ChangeMe*|*changeme*) ko "DATABASE_URL contient un mot de passe d'exemple";; *) ok "DATABASE_URL sans mot de passe d'exemple";; esac
    [ "$dbuser" = root ] && ko "DATABASE_URL utilise le compte root de MySQL (compte applicatif dédié attendu)" || ok "DATABASE_URL : compte applicatif non root"
fi
case "$(declare_get MAILER_DSN)" in
    "") ko "MAILER_DSN absent (aucune invitation ni notification ne partirait)";;
    null://*) ko "MAILER_DSN = null:// (aucun message n'est envoyé : invitations et liens bénéficiaires perdus)";;
    *) ok "MAILER_DSN défini (transport réel)";;
esac
if [ -z "$(declare_get NOTIFICATION_KEYRING)" ] || [ -z "$(declare_get NOTIFICATION_ACTIVE_KEY_ID)" ]; then
    ko "NOTIFICATION_KEYRING ou NOTIFICATION_ACTIVE_KEY_ID absent (secrets bénéficiaires non chiffrables ; à mettre sous séquestre hors serveur, voir sauvegarde)"
else ok "NOTIFICATION_KEYRING et NOTIFICATION_ACTIVE_KEY_ID définis (vérifier : bin/console app:notifications:check-crypto)"; fi
[ -n "$(declare_get OPS_ALERT_EMAIL)" ] && ok "OPS_ALERT_EMAIL défini" || ko "OPS_ALERT_EMAIL vide (aucune alerte d'exploitation ne serait envoyée)"
# Second facteur des comptes CWS (SEC-006) : obligatoire en production (valeur par défaut de l'application : 1). Refus si désactivé explicitement.
case "$(declare_get TWO_FACTOR_REQUIRED)" in 0|false|off|no) ko "TWO_FACTOR_REQUIRED désactivé : le second facteur des comptes CWS est obligatoire en production";; *) ok "second facteur CWS obligatoire (TWO_FACTOR_REQUIRED non désactivé)";; esac
# Chiffrement des sauvegardes (SEC-004) : clés PUBLIQUES age valides, jamais de clé privée sur le serveur. Passer aussi le fichier d'environnement des services.
[ -z "$(declare_get BACKUP_AGE_IDENTITY)" ] && ok "aucune clé privée de sauvegarde déclarée sur le serveur" || ko "BACKUP_AGE_IDENTITY défini : la clé PRIVÉE de sauvegarde ne doit jamais se trouver sur le serveur"
recipients=$(declare_get BACKUP_AGE_RECIPIENTS)
if [ -z "$recipients" ]; then ko "BACKUP_AGE_RECIPIENTS absent (clés publiques age : les sauvegardes sont chiffrées, jamais écrites en clair)"
else
    badkey=0; nkeys=0
    for k in $(printf '%s' "$recipients" | tr ',' ' '); do nkeys=$((nkeys + 1)); [[ "$k" =~ ^age1[0-9a-z]{58}$ ]] || badkey=1; done
    if [ "$badkey" -eq 1 ]; then ko "BACKUP_AGE_RECIPIENTS contient une valeur qui n'est pas une clé publique age (attendu : age1…)"
    elif [ "$nkeys" -lt 2 ]; then ok "BACKUP_AGE_RECIPIENTS : 1 clé publique (une seconde clé de secours est recommandée)"
    else ok "BACKUP_AGE_RECIPIENTS : $nkeys clés publiques"; fi
fi
case "$(declare_get OFFLINE_CONTROL_ENABLED)" in
    1|true|on|yes) [ -n "$CONTROL" ] || ko "OFFLINE_CONTROL_ENABLED actif sans --control-domain : la PWA partagerait l'origine des cookies de session (origine dédiée obligatoire)";;
esac
if [ -n "$APPDIR" ]; then
    if [ -d "$APPDIR/var" ]; then
        bad=$(find "$APPDIR/var" -maxdepth 2 -user root 2>/dev/null | head -3 | tr '\n' ' ')
        [ -z "$bad" ] && ok "var/ : aucun élément appartenant à root" || ko "var/ contient des éléments appartenant à root ($bad) : chown -R sur l'utilisateur du pool PHP-FPM (SEC-014)"
    else ko "$APPDIR/var introuvable"; fi
fi
if [ -n "$CONTROL" ]; then
    [ "$CONTROL" != "$DOMAIN" ] && ok "origines distinctes : gestion $DOMAIN, contrôle $CONTROL" || ko "le domaine de contrôle doit différer de celui de la gestion"
    [ "$(declare_get CONTROL_HOST)" = "$CONTROL" ] && ok "CONTROL_HOST = $CONTROL (liste blanche G1 et refus de /controle sur la gestion G3 actifs)" || ko "CONTROL_HOST doit valoir $CONTROL (valeur : '$(declare_get CONTROL_HOST)')"
    th=$(declare_get TRUSTED_HOSTS)
    d1=${DOMAIN//./\\.}; d2=${CONTROL//./\\.}
    case "$th" in
        "^($d1|$d2)$"|"^($d2|$d1)$") ok "TRUSTED_HOSTS limité aux deux domaines (G2 actif)";;
        "") ko "TRUSTED_HOSTS absent : G2 inactif (attendu : ^($d1|$d2)\$)";;
        *) ko "TRUSTED_HOSTS = $th : doit être exactement les deux domaines (^($d1|$d2)\$)";;
    esac
    case "$(declare_get CONTROL_PWA_BASE_URL)" in
        "https://$CONTROL/controle/") ok "CONTROL_PWA_BASE_URL = https://$CONTROL/controle/ (QR d'activation et lien de la PWA sur l'origine de contrôle)";;
        *) ko "CONTROL_PWA_BASE_URL doit valoir https://$CONTROL/controle/ (valeur : '$(declare_get CONTROL_PWA_BASE_URL)')";;
    esac
    case "$(declare_get OFFLINE_CONTROL_ENABLED)" in 1|true|on|yes) echo "!!  OFFLINE_CONTROL_ENABLED actif : le contrôle hors connexion est OUVERT (exige l'accord explicite du responsable du projet)";; *) ok "OFFLINE_CONTROL_ENABLED inactif";; esac
fi
[ "$fail" -eq 0 ] && echo "RESULTAT : CONFORME" || echo "RESULTAT : $fail non-conformité(s)"
exit $((fail > 0))
