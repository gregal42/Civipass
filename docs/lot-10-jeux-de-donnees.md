# LOT 10 — Environnement local de recette et jeux de données fictifs

Statut : environnement préparé le 18 novembre 2026 (local uniquement). Plan : [lot-10-plan-recette.md](lot-10-plan-recette.md).

## Environnement

| Élément | Valeur |
|---|---|
| Base | `civipass_recette` (MySQL local du projet), migrée par les migrations existantes (schéma synchronisé) ; aucune donnée métier |
| Amorçage | migrations (référentiels de demandes, types d'événement), `app:communes:sync --file` (4 communes publiques du référentiel, sans appel réseau), `app:super-admin:create` (`cws-root@recette.invalid`) |
| Serveur | conteneur local éphémère `saas_civipass_recette`, image du projet, serveur PHP intégré, `http://localhost:8098` (lié à 127.0.0.1) ; même schéma que `bin/e2e-control` |
| Configuration | fichier `var/lot10/recette.env` (**ignoré par Git**, jamais committé) : base de recette, MailCatcher du projet, `DEFAULT_URI`, trois interrupteurs à 1 **dans ce seul processus** |
| Console | `var/lot10/console.sh <commande>` : toujours la base de recette |
| E-mails | MailCatcher du projet (réseau Docker local) ; aucun envoi externe |
| Défauts du dépôt | inchangés : `.env` et `services.yaml` à 0 pour les trois interrupteurs |
| Mots de passe | dans `var/lot10/credentials.txt` (ignoré, jamais dans Git) |

Arrêt/restauration en fin de recette : `docker rm -f saas_civipass_recette` ; suppression du fichier d'environnement ; la base `civipass_recette` peut être supprimée sur demande. Les valeurs initiales des interrupteurs n'ont jamais été modifiées dans le dépôt.

## Communes (référentiel)

A = Aubenas (07019, organisatrice) · B = Privas (07186, invitée) · C = Annonay (07010, non invitée) · D = Lamastre (07130, réserve).

## Comptes fictifs (à créer par l'application, domaine `@recette.invalid`)

`cws-root` (existant) · `cws-support` (habilitation `forced_closure` par console) · `a-admin`, `a-instr1`, `a-instr2`, `a-lect` · `b-admin`, `b-instr` · `c-admin`.

## Jeux de saisie fictifs

| Dossier | Nom / prénom | Contact | Véhicule | Rôle dans le scénario |
|---|---|---|---|---|
| Citoyen 1 | Martin / Alice | alice@recette.invalid, 06 00 00 00 01 | aucun | approbation A |
| Citoyen 2 | Bernard / Hugo | hugo@recette.invalid, 06 00 00 00 02 | AA-101-AA | approbation A+B, puis plaque modifiée |
| Citoyen 3 | Dubois / Léa | lea@recette.invalid, 06 00 00 00 03 | AA-102-AA, AA-103-AA | prolongation/raccourcissement |
| Citoyen 4 | Martin / Alice (même identité que 1) | 06 00 00 00 01 | aucun | doublon |
| Citoyen 5 | Petit / Karim | sans e-mail, 06 00 00 00 05 | aucun | refus sans adresse (information manuelle) |
| Citoyen 6 | Roux / Sonia | sonia@recette.invalid, 06 00 00 00 06 | AA-106-AA | refus notifié, panne SMTP simulée |
| Professionnel | Entreprise Test SAS | pro@recette.invalid | BB-200-BB | dépôt professionnel |

Aucune donnée réelle. Chaînes d'attaque (XSS, longueurs) : `<script>alert(1)</script>`, 300 caractères, plaque `ZZZ` (invalide).
