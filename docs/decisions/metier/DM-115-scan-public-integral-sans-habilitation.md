# DM-115 — Scan public intégral : nom, plaques, zones et validité, sans compte ni habilitation

- **Identifiant** : DM-115
- **Thème** : LOT 05 — Contrôle
- **Statut** : Validée
- **Validation** : Validé par le commanditaire (arbitrages du LOT 05.0 et rectificatif Q5/Q6, 9 novembre 2026)
- **Décisions remplacées** : Aucune. **Amendée le 10 novembre 2026** (événement clos : voir « Amendement du 10 novembre 2026 » ci-dessous).

## Contexte

`security.md`, `architecture.md` et la feuille de route prévoyaient un scan public **sans nom** et, en parallèle, une PWA à activation d'appareils. Rectificatif du commanditaire sur Q5 et Q6.

## Décision

1. **Le scan est entièrement public** : toute personne qui scanne le QR avec l'appareil photo de son téléphone accède, **sans compte, sans connexion, sans habilitation ni activation**, à une page web Civipass.
2. **Informations affichées, et seulement celles-ci** : nom et prénom du bénéficiaire ; plaque(s) d'immatriculation **si des véhicules sont associés** ; zones autorisées ; dates et périodes de validité des droits ; **état de validité calculé en temps réel, par zone** ; référence du laissez-passer ; événement.
3. **Jamais affichés** : adresse postale, e-mail, téléphone, justificatifs, motif ni autres informations de la demande.
4. Une **zone inactive** ou une **autorisation révoquée** est clairement identifiable ; un laissez-passer n'est jamais présenté comme entièrement valide si seules certaines zones le sont.
5. **Plus de contrôle « minimal » / « détaillé »** : `ControllerActivation` et `ControllerDevice` **ne sont pas développés** pour le contrôle V1 ; aucun compte, rôle, PWA ni session d'activation n'est imposé au contrôleur. La PWA hors ligne (LOT 06) est une évolution distincte, à redéfinir.
6. **Mesures obligatoires** : jeton opaque non prédictible (DM-113) ; aucun accès par référence `DEM-` ou `LP-` ; `noindex`, cache désactivé (`no-store`), `Referrer-Policy: no-referrer` ; limitation de débit ; aucun jeton dans les journaux (DT-048) ; aucun journal systématique des scans en V1 (compteurs agrégés compatibles avec la confidentialité).
7. **Risque résiduel assumé et documenté (RGPD).** Nom, prénom et plaques sont des données personnelles exposées **volontairement à toute personne qui dispose du QR** (photographie, partage, transmission). Le QR n'est pas une preuve d'identité : le contrôleur rapproche le nom et les plaques affichés de la personne et du véhicule. La base légale, l'information des personnes (texte de confidentialité du dépôt) et la durée de conservation doivent être validées avant la production (DM-117).

## Conséquences fonctionnelles ou techniques

- Le contrôle public est implémenté au **LOT 05.4** ; le LOT 05.1 conserve un modèle capable de le servir (jeton, droits par zone) sans route publique.
- Les agents connectés restent soumis à l'isolation communale (DM-116).

## Documents concernés

- [lot-05-schema.md](../../lot-05-schema.md)
- [security.md](../../security.md)

## Écarts documentaires signalés

- `security.md`, `architecture.md`, `data-model.md` et la feuille de route prévoyaient d'autres comportements : mis à jour (CONTRADICTIONS, section I).

## Amendement du 10 novembre 2026 : événement définitivement clos

**Décision du commanditaire (définitive)** : lorsqu'un événement est **définitivement clos**, la vérification publique `/v/{jeton}` n'affiche **qu'un statut neutre « Événement terminé »** (HTTP 200). Elle n'expose plus ni le nom ni le prénom du bénéficiaire, ni les plaques, ni les zones autorisées, ni la référence du laissez-passer, ni le nom de l'événement, ni les communes. La règle est appliquée **côté serveur, dans le lecteur métier** (`PublicVerificationReader`, avant toute lecture des droits) : aucune représentation publique (HTML, JSON demandé, suffixe, barre finale, négociation de contenu) ne peut donc la contourner ; il n'existe aucune représentation JSON.

**Inchangé** : jeton retiré (QR remplacé) = HTTP 410 sans donnée, y compris après la clôture ; jeton inconnu ou mal formé = 404 neutre ; événement **suspendu** = statut « Droits suspendus », aucun droit présenté comme utilisable.

**Hors périmètre** : les règles de conservation administrative (durées, anonymisation) restent au **LOT 05.6**. Le calcul interne des droits (page du bénéficiaire, PDF) est inchangé. Voir [lot-05-5-5-cadrage.md](../../lot-05-5-5-cadrage.md) §15 et §16.
