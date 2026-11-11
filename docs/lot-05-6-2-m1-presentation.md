# LOT 05.6.2 — Migration M1 : `event_event.closure_statistics` (11 novembre 2026)

**Statut : M1 VALIDÉE et EXÉCUTÉE le 11 novembre 2026 sur `civipass_test` puis sur `civipass` (développement), chacune après accord distinct. Aucune base de production n'existe ni n'est touchée.** Fichier : `src/Migrations/Version20261111190603.php`. Le mapping de l'entité `Event` est livré (propriété privée, sans accesseur ni écriture : voir le lot des statistiques). Référence : [plan d'implémentation](lot-05-6-2-plan-implementation.md) §6 et §7.

## 1. SQL exact (généré par Doctrine)

Sortie de `doctrine:schema:update --dump-sql` (lecture seule, après ajout temporaire du mapping, entité restaurée aussitôt) :

```sql
ALTER TABLE event_event ADD closure_statistics JSON DEFAULT NULL;
```

C'est exactement le SQL accepté comme base de travail : **aucune différence** avec le plan. `down()` : `ALTER TABLE event_event DROP closure_statistics`.

## 2. Classe de migration (`Version20261111190603`, générée par `doctrine:migrations:generate`)

```php
<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * LOT 05.6.2 (DM-120 D4, M1) : `event_event.closure_statistics`, compteurs internes de clôture (JSON nullable, écriture unique).
 *
 * Données existantes : aucune ligne n'est lue, modifiée ni déplacée ; NULL signifie « non calculées ».
 * Retour arrière : voir `down()` : refusé dès qu'une statistique existe (donnée non reconstituable après une purge) ; on restaure alors une sauvegarde.
 */
final class Version2026111XXXXXXX extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'LOT 05.6.2 : compteurs internes de clôture (event_event.closure_statistics).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE event_event ADD closure_statistics JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $recorded = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM event_event WHERE closure_statistics IS NOT NULL');
        $this->abortIf($recorded > 0, sprintf('%d événement(s) portent des compteurs de clôture : le retour arrière les détruirait. Restaurez une sauvegarde de la base à la place.', $recorded));
        $this->addSql('ALTER TABLE event_event DROP closure_statistics');
    }
}
```

## 3. Modification de l'entité `Event` (mapping livré ; accesseur et écriture contrôlée : lot des statistiques)

```diff
     #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
     private ?\DateTimeImmutable $closedAt = null;
+
+    /** @var array<string, mixed>|null */
+    #[ORM\Column(type: Types::JSON, nullable: true)]
+    private ?array $closureStatistics = null;
```

L'accesseur, la méthode d'écriture unique et la validation stricte du schéma JSON (plan §6.2) appartiennent à l'implémentation des statistiques, **non commencée**.

## 4. Contraintes et index

`JSON` nullable, sans valeur par défaut autre que `NULL`, **sans index** (jamais filtré), sans clé étrangère, sans `CHECK` (la validité du contenu est portée par l'entité). Aucune autre table modifiée.

## 5. Événements déjà clôturés

Aucune ligne n'est modifiée : `closure_statistics` reste `NULL` (« non calculées »). Aucune donnée de production n'existe (bases de développement et de test seulement). Aucune commande de rattrapage (Q8). L'interface affichera « Bilan non disponible pour cet événement ».

## 6. Vérifications faites sans exécuter M1

Sur une **table jetable** `zz_m1_scratch_event` (copie de la structure de `event_event` par `CREATE TABLE … LIKE`, créée puis supprimée dans `civipass_test` ; `event_event` et le suivi des migrations n'ont pas été touchés ; l'utilisateur de la base ne peut pas créer de base jetable) :

| Vérification | Résultat |
|---|---|
| `ALTER TABLE … ADD closure_statistics JSON DEFAULT NULL, ALGORITHM=INSTANT` | accepté (opération **en ligne, sans reconstruction** de la table) ; colonnes 18 → 19 |
| Type de la colonne créée | `json`, `IS_NULLABLE = YES`, valeur par défaut `NULL` |
| `ALTER TABLE … DROP closure_statistics` | accepté ; colonnes 19 → 18 (schéma initial retrouvé) |
| Table jetable | supprimée ; aucune table `zz_m1%` ne subsiste |

**À exécuter après accord**, avant la migration réelle (base jetable impossible : droit `CREATE DATABASE` refusé) : sauvegarde de la base visée, puis `doctrine:migrations:migrate --dry-run`, puis exécution sur `civipass_test` **uniquement**, `doctrine:schema:validate`, `make test-db`, `bin/qa full`, **puis** nouvel accord pour `civipass` (développement).

## 7. Retour arrière et sauvegardes

- **Sauvegarde préalable** de la base concernée (commande à fournir au moment de l'accord).
- `down()` **refusé** si au moins un événement porte des compteurs (donnée non reconstituable après une purge) : restauration d'une sauvegarde à la place. Sans compteur : `DROP COLUMN` immédiat.
- Une sauvegarde **antérieure** à M1 ne contient pas la colonne : sa restauration est compatible avec le code livré tant que le mapping de l'entité n'est pas déployé ; **après** déploiement du mapping, une restauration d'avant M1 exige de rejouer M1.
- **Ordre de déploiement** : migration **avant** le code qui lit ou écrit la colonne (le mapping de `Event` sélectionne la colonne dans toutes les requêtes de l'entité : sans colonne, l'application échoue).
- Effet sur les tests : après M1 sur `civipass_test`, la suite entière exige la colonne ; avant M1, l'entité modifiée ne doit pas être livrée.

## 8. Risques

| Risque | Parade |
|---|---|
| Mapping livré avant la colonne (toutes les lectures d'`Event` échouent) | Ordre imposé : migration d'abord ; entité modifiée seulement dans le lot des statistiques |
| Base `civipass` oubliée | Contrôle `doctrine:migrations:status` et `doctrine:schema:validate` sur chaque base |
| `down()` destructeur | Refus tant que des compteurs existent |
| Bloc exact stocké plus longtemps que nécessaire (réserve DPO) | Suppression à la purge du dernier dossier : dépendance du 05.6.4, consignée |

## 9. Exécutions réelles (11 novembre 2026)

| Base | Contrôles avant | SQL exécuté | Contrôles après |
|---|---|---|---|
| `civipass_test` | 18 migrations, 18 colonnes, aucun processus concurrent ; sauvegarde `mysqldump` (38 tables) | `ALTER TABLE event_event ADD closure_statistics JSON DEFAULT NULL` (1 requête) | colonne `json` nullable sans défaut ; 19 migrations, 0 en attente ; `doctrine:schema:validate` OK ; `bin/qa full` vert |
| `civipass` (développement) | 18 migrations, 24 événements, aucun processus concurrent ; sauvegarde complète `mysqldump` (38 tables, 32 `INSERT`) **restaurée dans une base temporaire puis supprimée** : tables, `event_event` (24), `audit_log` (286), `identity_user` (22) et migrations (18) identiques | idem (1 requête) | colonne `json` nullable sans défaut ; 24 événements intacts, tous à `NULL` ; 19 migrations, 0 en attente ; `doctrine:schema:validate` OK |

Aucune autre migration n'a été exécutée. Les sauvegardes sont dans le répertoire temporaire de la session (hors dépôt) ; une sauvegarde est à refaire avant tout usage ultérieur.

## 10. Suite

Les statistiques (calcul à la clôture, écriture unique, publication à la lecture, permissions, panneau) sont autorisées et développées dans le lot suivant ; elles ne sont pas commitées avant l'accord sur leur rapport final.
