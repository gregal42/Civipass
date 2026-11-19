# Bilan de la stabilisation locale V1.0 — anomalies P0/P1 (19 novembre 2026)

**Périmètre** : état du code et de la documentation sur `develop` après le correctif B. **Aucune validation n'a eu lieu en environnement cible** (pas de serveur de recette, de DNS, de SMTP réel ni de HTTPS réel). Les registres ([registre-anomalies-v1.md](registre-anomalies-v1.md), [registre-securite.md](registre-securite.md)) restent la source détaillée.

**Définition des trois statuts**
- **Vérifiée localement** : le comportement est entièrement démontré par des tests automatisés ou des preuves locales, sans dépendre de l'environnement serveur.
- **Validation cible restante** : corrigée et testée, mais le résultat dépend de l'infrastructure réelle (nginx, systemd, MySQL du serveur, SMTP, TLS) : à valider à la recette d'installation.
- **Ouverte / arbitrage externe** : pas de correction de code possible ou voulue ici ; décision, prérequis ou recette extérieurs.

QA : `bin/qa full` vert (3 806 tests, 0 échec, 6 ignorés) ; PHPStan niveau 6, CS-Fixer, `verify-docs.sh` OK ; `doctrine:schema:validate` synchronisé sur `civipass` et `civipass_test` ; les tests de chiffrement `age` (9 tests) s'exécutent et échouent désormais s'ils ne peuvent pas s'exécuter.

## A. Anomalies techniques P0/P1

### A1. Corrigées et vérifiées localement
| ID | Anomalie | Preuve locale |
|---|---|---|
| C-01 | Scanneur jsQR absent des commits (`assets/vendor/` ignoré) | fichiers versionnés ; `InstallReproducibilityTest` |
| SEC-006 | Pas de second facteur pour les super-administrateurs CWS | TOTP (vecteurs RFC 6238), codes de secours, barrière sur toutes les routes, rejeu refusé, 429, réinitialisation tracée ; clé dédiée et rotation (`TwoFactorSecretCipherTest`) ; migration appliquée en local |
| SEC-014 | Échec d'écriture des limiteurs ignoré en silence | `LimitersAreStrictTest`, `RateLimiterStorageFailureListenerTest` (503 neutre journalisé) |
| SEC-016 | Prise de contrôle d'une commune via le lien « contact » | aucun droit sans validation CWS ; seul un super-administrateur actif décide, motif obligatoire, deux demandes → un seul administrateur ; alerte e-mail minimale (tests) ; migration appliquée en local |
| SEC-020 | Jeton de contrôle en clair en base | colonne en clair supprimée ; empreinte + copie scellée ; QR existants conservés (preuve sur données locales) ; rotation et retour arrière testés ; 2 migrations appliquées en local |

### A2. Corrigées localement — validation en environnement cible restante
| ID | Anomalie | Ce qui reste à valider sur la cible |
|---|---|---|
| SEC-002 | En-têtes de sécurité absents (origine de gestion) | en-têtes réels derrière nginx, HSTS/TLS |
| SEC-003 | Aucune limitation du dépôt public | adresse cliente réelle derrière le proxy ; seuil de 15/heure à confirmer sur un trafic réel |
| SEC-004 | Sauvegardes non chiffrées | `age` sur le serveur, **restauration réelle dans MySQL non prouvée**, exercice hors serveur |
| SEC-005 | `check-deployment.sh` insuffisant | exécution sur un vrai `.env` de production |
| SEC-013 | Garde-fous inactifs sans `CONTROL_HOST` | domaine de contrôle réel, échec fermé |
| C-02 | Installation non reproductible hors ligne | installation depuis l'archive sur un serveur propre |
| C-03 | Tailles d'envoi PHP | pool PHP-FPM réel, pièce de 10 Mo |
| C-04 | Chemins incohérents | arborescence réelle (`/opt/civipass`) |
| C-09 | Copie hors serveur hors `run-job` | unités systemd réelles (verrou, battement, alerte) |
| SEC-001 | Correctif à valider sur HTTPS | serveur HTTPS cible |

### A3. Ouvertes ou nécessitant un arbitrage externe (techniques)
| ID | État |
|---|---|
| SEC-015 | **Arbitré** : 72 h conservées, hors connexion inchangé ; risque résiduel F6 **accepté pour le pilote**, inscrit au registre ; consigne de désactivation immédiate et limite de révocation documentées. Options B (validité glissante) et C (liaison à l'appareil) **non développées**, à réarbitrer. |
| C-07 | **Ouverte** : supervision non indépendante de l'application (même SMTP ; pas de surveillance du certificat ni du disque). Relève de l'infrastructure : à traiter comme prérequis d'exploitation (D) plutôt que par du code. |
| SEC-016 (suite) | Délai de traitement et procédure d'urgence à définir avant le pilote (aucun délai garanti). |

## B. Validations juridiques et textes (hors code)
- **RGPD** : durées de conservation (toutes provisoires), activation ou non de `RETENTION_PURGE_ENABLED`, suppression des comptes agents inactifs, écarts F02–F11 : [lot-11-3-dossier-rgpd.md](lot-11-3-dossier-rgpd.md) §2 et §7 (projets techniques, **pas** des validations juridiques).
- **Textes** : libellé juridique du refus (T4), consigne « Contrôle » (T5) : [lot-10-textes-a-valider.md](lot-10-textes-a-valider.md).
- **Procédure de légitimité des administrateurs** (SEC-016) : à valider par CWS.

## C. Recettes physiques (aucune faite)
SMTP réel ([plan](recette/plan-recette-smtp.md)) ; smartphones 09.7 ([plan](recette/plan-recette-09-7.md)) ; restauration réelle ; Nginx réel ; charge (C-13, P2).

## D. Prérequis d'exploitation
Serveur HTTPS de recette, DNS, SMTP avec SPF/DKIM/DMARC ; **séquestre hors serveur des trousseaux et clés** (TOTP, jetons QR, notifications, clés privées `age`) avec exercice de restauration ([runbooks/sequestre-trousseaux.md](runbooks/sequestre-trousseaux.md), GO / NO-GO 3.4) ; supervision indépendante (C-07) ; adresse d'alerte (`OPS_ALERT_EMAIL`, `ADMIN_VALIDATION_ALERT_EMAIL`) ; image Docker de développement à reconstruire pour embarquer `age` durablement ; [prerequis-serveur.md](exploitation/prerequis-serveur.md).

## Conclusion
**Stabilisation locale : terminée pour le code** — toute anomalie P0/P1 corrigeable en local l'est, aucune n'a de test en échec, aucune décision de code n'est en attente. Elle ne vaut **pas** validation : A2, B, C et D demeurent. **Réserve** : C-07 (supervision) est la seule anomalie P1 technique non traitée ; elle dépend de l'infrastructure et doit être cadrée avec les prérequis. Condition pour passer à la **recette d'installation** : constituer D (serveur HTTPS, SMTP, séquestre) puis dérouler A2 sur la cible avant tout audit externe et toute ouverture à une commune pilote.
