# SFD — proposition de révision V1.1 (règles du LOT 02) — révision 3 (R1–R9, A10–A14, P1–P6)

> **Statut : proposition non validée.** Complète le [SFD V1.0 détaillé](../references/SFD/SFD-V1.0-detaille.docx) (non modifié). Là où cette proposition diffère du SFD, elle s'appuie sur une décision validée citée entre crochets. Points non tranchés : [CONTRADICTIONS.md](CONTRADICTIONS.md).

## 1. Événement

- Une seule commune organisatrice (DM-001) ; elle seule modifie les informations générales. Type d'événement issu d'un référentiel évolutif non limité aux crises, neuf types initiaux validés (`fire`, `flood`, `storm`, `accident`, `security`, `sport`, `cultural`, `works`, `other`, DM-053). Le fuseau est l'identifiant IANA du territoire (DM-062, remplace DM-054).
- **Statuts** : `DRAFT` → `ACTIVE` ↔ `SUSPENDED` → `CLOSED` ; `DRAFT` → `CLOSED` autorisé (DM-077).
- À la création : statut `DRAFT`, participation organisatrice `ACTIVE`, zone « Commune entière — [nom] » proposée non confirmée (DM-053, DM-058). **Aucun accès opérationnel tant que l'événement n'est pas actif.**

## 2. Participations

- Une participation par commune et par événement (DM-004). Statuts : `INVITED`, `ACTIVE`, `SUSPENDED`, `CLOSED`. Une invitation ne se décline pas (DM-037).
- Acceptation → `ACTIVE`. Suspension (motif obligatoire) et réactivation possibles tant que l'événement n'est pas clôturé (DM-056). Une participation active dans un événement suspendu ne rend pas ses zones opérationnelles.
- **Clôture globale** : réalisée par la commune organisatrice (SFD §6.2, DM-056). Préalable : les participations `ACTIVE` ou `SUSPENDED` des autres communes sont clôturées. Les invitations non acceptées ne bloquent pas et passent `CLOSED`. La participation organisatrice se clôture avec l'événement, dans la même transaction, avec audit. La commune organisatrice ne clôture pas les participations actives des autres.
- Une commune `INVITED` ne modifie pas ses zones ; après acceptation, son administrateur confirme et configure (DM-058).
- Une participation `SUSPENDED` conserve l'édition de ses zones (créer, modifier, configurer, confirmer, activer, suspendre) mais **aucune zone n'est opérationnelle** (DM-066).
- **Clôture forcée CWS** (DM-065, développement reporté au lot Administration CWS) : exceptionnelle, depuis Sonata, super-administrateur **titulaire de `CWS_FORCE_CLOSE_EVENT`**, justification obligatoire, confirmation, liste des participations ouvertes, clôture transactionnelle de l'événement et des participations restantes, invalidation immédiate des accès, audit de l'opérateur réel. Distincte des permissions communales ; sans impersonation. Remplace la lecture du CDC §4.1.

## 3. Rôles et permissions (DM-057)

| Action | Administrateur communal | Gestionnaire d'événement | Agent instructeur |
|---|---|---|---|
| Créer un événement | Oui | Oui | Non |
| Modifier / gérer un événement de sa commune | Oui | Oui (« gérer ») | Non |
| Activer, suspendre, clôturer l'événement | Oui | Oui (R1) | Non |
| Inviter des communes | Oui | Oui | Non |
| Accepter une invitation | Oui | **Non** (R2, sauf rôle administrateur cumulé) | Non |
| Suspendre, réactiver, clôturer sa participation | Oui | Oui | Non |
| Créer, modifier, confirmer, activer, suspendre, supprimer logiquement des zones | Oui | **Non** (sauf s'il est aussi administrateur) | Non |
| Configurer la validité, opérations groupées | Oui | Non | Non |

Les rôles sont cumulables et **toujours contextualisés par commune** : aucune action sur les zones d'une autre commune. Le terme « gestionnaire communal » de SCR-016 à SCR-018 est remplacé par « administrateur communal ». Le super-administrateur CWS n'a pas de droit communal implicite ; l'assistance passe par l'impersonation (DM-061, lot dédié).

## 3 bis. Impersonation CWS (exigences, lot ultérieur)

Démarrage depuis Sonata, droits du compte impersoné sans élévation, sortie explicite, MFA et réauthentification récente, bandeau permanent, audit de l'opérateur réel et du compte impersoné, confirmation renforcée des opérations irréversibles, aucun accès aux mots de passe. Hors périmètre du LOT 02.

## 4. Zones

- Appartiennent à une participation ; chaque commune administre uniquement les siennes ; les autres sont en lecture seule (DM-006, DM-007). Pas de bibliothèque, pas de cartographie (DM-008, DM-029, DM-030).
- **Confirmation** (DM-051) : zone manuelle = confirmée à la création ; pour « Commune entière » manuelle, confirmation explicite dans le formulaire ; « Commune entière » proposée automatiquement = non confirmée. Une zone non confirmée n'ouvre aucun droit. Statuts de zone : `Active`, `Suspendue` (inchangés).
- **Suppression** : toujours logique ; la zone disparaît des listes opérationnelles ; historique consultable selon les droits d'audit ; pas de réactivation implicite (DM-052).

## 5. Validité temporelle

- Configuration explicite : non configurée / **Pendant toute la durée de l'événement** / **Périodes spécifiques** (DM-011 à DM-013).
- Créneaux : continu ; ponctuel (une seule date, début strictement avant fin) ; récurrent (dates de début et de fin obligatoires, jours de semaine, passage de minuit autorisé) (DM-014 à DM-017).
- Chevauchements autorisés, validité = union (DM-018). Zone sans configuration : aucun droit (DM-019).
- Horaires saisis en heure de Paris ; **heure inexistante refusée** avec explication ; **heure répétée signalée**, l'utilisateur choisit l'occurrence ; vaut aussi pour les récurrences (DM-055). Aucune correction silencieuse.

## 6. Activation de l'événement (DM-059)

L'événement `DRAFT` est activable s'il possède au moins une zone confirmée, active, non supprimée, avec validité configurée, appartenant à une participation `ACTIVE` (R7) ; une zone d'une participation suspendue ne compte pas. Une période future suffit ; une zone non configurée ne compte pas.

## 7. Opérations groupées (DM-022 à DM-028)

Configuration de plusieurs zones ou de toutes les zones de sa participation ; activation ou suspension groupées ; confirmation avant remplacement de configurations existantes ; configurations indépendantes ensuite, sans héritage ; jamais sur les zones d'une autre commune. *Fonctionnalité absente du SFD V1.0 : à y inscrire.*

## 8. Parcours (DM-060)

**Simple** : créer l'événement → confirmer « Commune entière » → « Pendant toute la durée de l'événement » → activer. Aucun paramétrage horaire.
**Avancé, à la demande** : plusieurs zones, créneaux continus / ponctuels / récurrents, application à plusieurs zones, activation ou suspension groupées. Même modèle métier ; pas d'architecture distincte.

## 5 bis. Fuseaux et outre-mer (DM-062)
`Event.timezone` : **un seul** identifiant IANA par événement, proposé automatiquement d'après la commune organisatrice (référentiel territorial, DT-026, y compris les fuseaux de la Polynésie française), visible avant activation et **verrouillé après** ; instants en UTC ; saisie et affichage dans le fuseau de l'événement ; toutes les zones et participations de l'événement dans ce fuseau ; heure inexistante refusée et heure ambiguë choisie, y compris pour les récurrences (DM-063, DM-064).

## 5 ter. Créneau continu (DM-069)
Date et heure de début, date et heure de fin obligatoires ; fin strictement postérieure au début ; distinct du mode « Pendant toute la durée de l'événement ».

## 5 quater. Droits d'utilisation et création (DM-070, DM-076)
Création d'un événement : commune administrativement active **et droit d'utilisation valide** — abonnement actif, période TRIAL valide ou **accès commercial accordé par CWS** (par commune, expiration facultative, révocable, traçable ; sans abonnement payant) —, contrôle serveur dans un service métier centralisé (DM-070, DM-073, DM-076). Un abonnement payant n'est pas obligatoire. L'expiration ou la révocation d'un droit n'interdit que les nouvelles créations ; les événements ouverts continuent et les communes gardent leurs capacités de gestion. La suspension administrative pour motif de sécurité reste distincte (DM-034). Une commune non abonnée accepte une invitation, participe, gère ses zones et délivre des laissez-passer dans sa participation.

## 5 quinquies. Événement actif et référence publique (DM-071, DM-072)
Un événement `ACTIVE` reste modifiable : nom, description, **consignes et informations opérationnelles** (nouveau champ), **date de fin**, invitations, zones, validités, participations. Verrouillés : organisatrice, type, fuseau, référence publique, **date de début**. Les zones « Pendant toute la durée de l'événement » suivent les changements de durée ; les créneaux spécifiques ne sont pas prolongés automatiquement ; toute réduction de durée est immédiate ; toutes les modifications sont auditées (DM-071).
Référence publique `EVT-YYYY-NNNNNN` : compteur **global permanent** (pas de remise à zéro annuelle), année de création, six chiffres minimum extensibles, unique, immuable, générée dans la transaction de création (DM-072).

## 5 sexies. Créneaux : suppression logique (DM-075)
Les créneaux ne sont jamais supprimés physiquement : lors d'une reconfiguration, les anciens créneaux sont conservés marqués supprimés, les nouveaux sont créés ; les créneaux supprimés sont exclus des calculs d'accès ; l'historique est conservé. Distinct de la suspension d'une zone.

## 5 septies. Habilitations CWS (DM-074)
`superAdmin` reste l'administration générale de Sonata. Deux permissions distinctes, non attribuées automatiquement : `CWS_IMPERSONATE` et `CWS_FORCE_CLOSE_EVENT`. Impersonation et clôture forcée : lot Administration CWS dédié.

## 5 octies. Zone « Commune entière » automatique (DM-068)
Supprimable (suppression logique), jamais recréée automatiquement, recréable manuellement.

## 5 nonies. Clôture d'un événement en préparation (DM-077)
Un événement `DRAFT` peut être clôturé sans avoir été activé : clôture définitive, historique conservé, participation organisatrice et invitations clôturées dans la même transaction, audit obligatoire, aucun statut supplémentaire.

## 9. Corrections documentaires proposées

- SFD SCR-016 à SCR-018 : « gestionnaire communal » → « administrateur communal ».
- SFD SCR-018 : « fin strictement après début » → préciser « instant de fin résolu » pour les créneaux nocturnes ; heures égales interdites.
- CDC §4.1 : clôture normale par l'organisatrice (SFD §6.2, A6.3) ; clôture exceptionnelle forcée par CWS depuis Sonata (R5, DM-065).
- SFD §2 : états de zone « active/suspendue » ; supprimer la mention « inactive avant confirmation » (SCR-012) au profit de `confirmedAt`.
- Numérotation des recettes REC-01..12 / REC-001..015 à unifier.
