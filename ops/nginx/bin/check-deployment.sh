#!/bin/bash
# Contrôle des paramètres d'environnement de l'application AVANT d'ouvrir le contrôle public. Lit (sans l'exécuter) un ou plusieurs fichiers .env (le dernier gagne) :
#   check-deployment.sh --domain civipass.exemple.fr .env .env.local [.env.prod.local]
# Échoue si : DEFAULT_URI n'est pas en https:// sur le domaine donné (les QR codes, liens bénéficiaires, invitations et réinitialisations sont bâtis à partir de DEFAULT_URI) ;
# APP_ENV n'est pas prod ; APP_DEBUG est actif ; SYMFONY_TRUSTED_PROXIES est défini (nginx est le premier saut) ; PUBLIC_VERIFICATION_ENABLED est actif (informatif :
# l'activation exige l'accord du responsable du projet, ce script ne la décide pas).
set -u
DOMAIN=""; FILES=()
while [ $# -gt 0 ]; do
    case "$1" in --domain) DOMAIN=${2:?}; shift 2;; *) FILES+=("$1"); shift;; esac
done
[ -n "$DOMAIN" ] && [ ${#FILES[@]} -gt 0 ] || { echo "usage: $0 --domain NOM FICHIER.env [FICHIER.env ...]" >&2; exit 2; }
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
[ "$fail" -eq 0 ] && echo "RESULTAT : CONFORME" || echo "RESULTAT : $fail non-conformité(s)"
exit $((fail > 0))
