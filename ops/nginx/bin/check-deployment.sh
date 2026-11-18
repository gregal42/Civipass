#!/bin/bash
# Contrôle des paramètres d'environnement de l'application AVANT d'ouvrir le contrôle public. Lit (sans l'exécuter) un ou plusieurs fichiers .env (le dernier gagne) :
#   check-deployment.sh --domain civipass.exemple.fr [--control-domain controle.civipass.exemple.fr] .env .env.local [.env.prod.local]
# Échoue si : DEFAULT_URI n'est pas en https:// sur le domaine donné (les QR codes, liens bénéficiaires, invitations et réinitialisations sont bâtis à partir de DEFAULT_URI) ;
# APP_ENV n'est pas prod ; APP_DEBUG est actif ; SYMFONY_TRUSTED_PROXIES est défini (nginx est le premier saut) ; PUBLIC_VERIFICATION_ENABLED est actif (informatif :
# l'activation exige l'accord du responsable du projet, ce script ne la décide pas).
# Avec --control-domain (origine dédiée au contrôle) : CONTROL_HOST = ce domaine, TRUSTED_HOSTS limité aux deux domaines, CONTROL_PWA_BASE_URL = https://<domaine de contrôle>/controle/,
# domaines distincts, DEFAULT_URI toujours sur la gestion ; OFFLINE_CONTROL_ENABLED signalé (informatif).
set -u
DOMAIN=""; CONTROL=""; FILES=()
while [ $# -gt 0 ]; do
    case "$1" in --domain) DOMAIN=${2:?}; shift 2;; --control-domain) CONTROL=${2:?}; shift 2;; *) FILES+=("$1"); shift;; esac
done
[ -n "$DOMAIN" ] && [ ${#FILES[@]} -gt 0 ] || { echo "usage: $0 --domain NOM [--control-domain NOM] FICHIER.env [FICHIER.env ...]" >&2; exit 2; }
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
