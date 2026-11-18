# Runbook — information des demandeurs refusés (LOT 08.5)

**Statut** : prêt pour la recette locale. **L'envoi réel est désactivé** (`REFUSAL_NOTICE_ENABLED=0`) tant que le **libellé juridique** du message n'est pas validé (DM-136). Aucune donnée personnelle dans la table de suivi, l'audit ni les journaux.

## 1. Principe
Chaque **refus** crée, dans la même transaction que la décision, une ligne de suivi. Avec une adresse e-mail : message minimal (référence, événement, commune, motif du référentiel sauf « doublon » et « autre », contact de la mairie ; jamais le commentaire, un nom, une plaque ni un lien). Sans adresse : état `NO_RECIPIENT`, visible des agents. Aucun envoi rétroactif pour les refus d'avant la mise en service.

## 2. Exploitation
- Une exécution périodique de `bin/console app:notifications:dispatch` (toutes les minutes, **installation finale**) traite aussi les refus ; sûre en présence de plusieurs processus (bail de 10 minutes).
- Réessais : 1, 5, 15, 60 puis 240 min ; abandon après 6 essais ou 24 h (`SMTP_EXHAUSTED`, `SMTP_WINDOW_EXPIRED`) ou tout de suite pour un refus définitif du serveur (`SMTP_PERMANENT`, `ADDRESS_INVALID`).
- **Mettre l'envoi en service** : valider le libellé juridique, vérifier le SMTP, puis passer `REFUSAL_NOTICE_ENABLED=1` **sur décision écrite du responsable du projet**. Tant que l'interrupteur vaut 0, les lignes restent `PENDING`.
- `SENT` signifie « remis au serveur de messagerie », **jamais** reçu ni lu.

## 3. Côté agents
- Fiche du dossier refusé : état de l'information (en attente, envoyée, aucune adresse, échec définitif, informé par un autre moyen).
- Liste **« Refus à communiquer »** (instructeurs et administrateurs de la commune) : demandeurs sans adresse ou dont l'envoi a définitivement échoué ; informer par téléphone, guichet ou courrier, puis **marquer informé** (canal, auteur, date).
- Le compteur apparaît sur l'accueil.

## 4. Incidents
Échecs répétés : vérifier SMTP et journaux (codes seulement) ; les lignes `ABANDONED` apparaissent automatiquement dans « Refus à communiquer ». Aucune relance automatique après abandon.
