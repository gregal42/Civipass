# Civipass — Architecture V1

> **Révision LOT 02 (TECH-02.1) — traçabilité** : les passages sur la bibliothèque de zones et les instantanés (`Zone`, `EventZone` avec source/instantané, « snapshots ») sont **remplacés** par [`lot-02-schema.md`](lot-02-schema.md) (décisions DM-008, DM-051, DM-052, DM-029). Le texte d'origine ci-dessous est conservé tel quel pour mémoire ; il n'est plus la référence pour les zones, événements et participations.

## Objectifs
Application web SaaS multi-communes : organiser des événements de crise, collecter des demandes sans compte, instruire par commune, délivrer un QR stable, vérifier en ligne et via PWA hors ligne.

## Stack existante
PHP 8.4, Symfony 8.1, Doctrine ORM, MySQL 8.4, Twig, AssetMapper, Stimulus, UX Turbo, Sonata Admin, Messenger, Workflow, Docker et Makefile. Tests PHPUnit, PHPStan niveau 6, PHP-CS-Fixer. **Symfony 8.1 est une branche non-LTS : planifier son évolution avant mise en production.**

## Structure modulaire
- `Identity` : comptes, invitations, authentification.
- `Municipality` : communes, memberships, droits d'utilisation (abonnement, TRIAL, accès commercial CWS).
- `Event` : événement, type, participation, zones et validités temporelles (créneaux supprimés logiquement), référence publique globale. *(Révision LOT 02 : plus d'instantanés de zones.)*
- `Request` : demande citoyenne, contacts, véhicules, pièces, communes et zones demandées.
- `Instruction` : instruction et décisions par commune.
- `Permit` : laissez-passer unique par demande, jeton QR, autorisations par commune, zones/plaques copiées, droits effectifs calculés (LOT 05.1, [lot-05-schema.md](lot-05-schema.md)) ; révisions au LOT 05.5. Implémente le port `ApprovedPartListener` de `Request` ; `Request` ne dépend pas de `Permit`.
- `Control` : réservé (PWA hors ligne, LOT 06 à redéfinir) ; le scan public V1 est servi par le LOT 05.4.
- `Shared` : audit, notifications, stockage privé et abstractions communes.
- `Admin` : intégration Sonata réservée à CWS.

Dans chaque domaine, organiser pragmatiquement `Domain/`, `Application/`, `Infrastructure/`, `UI/`. Les contrôleurs HTTP restent fins ; logique métier dans services/cas d'usage ; persistence Doctrine en infrastructure. Les échanges entre modules passent par interfaces/services applicatifs explicites, pas par des accès directs aux tables d'autres modules.

## Contrôle (révisé LOT 05.0)
**Scan public connecté (V1, LOT 05.4)** : QR lu avec l'appareil photo → page de vérification en ligne, **sans compte, sans activation ni habilitation** ; affiche nom, prénom, plaques (si des véhicules sont associés), zones, dates et périodes, **état par zone calculé en temps réel**, référence `LP-` et événement ; jamais adresse, e-mail, téléphone, justificatifs ni informations de la demande. Nom et plaques sont des données personnelles exposées à toute personne qui dispose du QR : risque assumé et documenté (DM-115, [security.md](security.md)). Le calcul des droits est unique (`PermitRightsEvaluator`, DT-047). **PWA activée (LOT 06, à redéfinir)** : l'ancien mode « accès événementiel temporaire par appareil » n'est pas développé au LOT 05 ; `Control` ne porte aucune habilitation en V1.

## Hors ligne
Synchronisation cible toutes les 15 minutes si réseau disponible ; avertissement à partir de 12 heures sans synchronisation ; blocage au-delà de 24 heures. Indiquer explicitement « contrôle hors ligne / données du ... » ; ne jamais afficher une confirmation « temps réel » sans connexion. Révocations et clôtures ne peuvent être connues hors ligne qu'après synchronisation ; documenter ce risque et minimiser les données stockées. Pas de géolocalisation ni journal systématique des scans. Stockage local chiffré si techniquement pertinent, mais ne pas supposer qu'IndexedDB assure un chiffrement applicatif. Définir le modèle de menace et le mécanisme d'effacement avant implémentation.

## Événements et droits
Une commune administrativement active disposant d'un droit d'utilisation valide (abonnement actif, TRIAL valide ou accès commercial accordé par CWS) peut créer un événement ; la commune créatrice en est l'organisatrice. Des communes sans droit d'utilisation peuvent participer sur invitation. Chacune décide indépendamment. Une sortie de participation invalide ses autorisations ; clôture de l'événement invalide l'ensemble. Zone V1 = rattachée à une participation, sans géométrie, sans bibliothèque ni instantané ; validité temporelle par créneaux (voir `lot-02-schema.md`).

## Traitements et intégrité
Opérations critiques (décisions, prolongations, révocations) synchrones et transactionnelles ; Messenger pour emails/SMS/PDF et tâches différables. Éviter la perte de messages lors des commits : envisager outbox transactionnelle ou mécanisme équivalent. Stockage fichiers privé derrière interface, migration possible vers objet. Horodatages en UTC, affichage Europe/Paris. API versionnée `/api/v1` avec DTOs et validation serveur. Workflow Symfony pour transitions lorsqu'utile.

## Qualité
Exécuter `make quality` après modifications ; tests d'intégration sur périmètres intercommunaux, autorisations, QR et expiration. Prévoir surveillance, limitation des appels publics, gestion des secrets, sauvegardes et restauration. Pas de modification de schéma avant validation du MCD détaillé.
