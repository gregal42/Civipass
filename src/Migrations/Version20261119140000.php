<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * SEC-020, ÉTAPE 1/2 (LOT 11, correctif B) : jeton de contrôle sans stockage en clair. Ajoute `token_sha256` (empreinte, calculée ici par MySQL pour les
 * jetons EXISTANTS, unique : c'est la clé de recherche) et `token_encrypted` (copie scellée, remplie ensuite par `app:permit:protect-tokens`), et rend
 * `token` (clair) facultatif pour que le nouveau code n'y écrive plus. AUCUN jeton ne change : les QR déjà émis continuent de fonctionner.
 *
 * Retour arrière sans perte tant que l'étape 2 n'est pas jouée : `down()` retire les deux colonnes (le clair est toujours là).
 */
final class Version20261119140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'SEC-020 (1/2) : permit_verification_token.token_sha256 (unique) et token_encrypted ; token en clair devient facultatif.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE permit_verification_token ADD token_sha256 CHAR(64) DEFAULT NULL COLLATE `utf8mb4_bin`, ADD token_encrypted VARCHAR(255) DEFAULT NULL, CHANGE token token CHAR(32) DEFAULT NULL COLLATE `utf8mb4_bin`');
        $this->addSql('UPDATE permit_verification_token SET token_sha256 = SHA2(LOWER(token), 256) WHERE token IS NOT NULL');
        $this->addSql('ALTER TABLE permit_verification_token CHANGE token_sha256 token_sha256 CHAR(64) NOT NULL COLLATE `utf8mb4_bin`');
        $this->addSql('CREATE UNIQUE INDEX uniq_permit_token_sha256 ON permit_verification_token (token_sha256)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_permit_token_sha256 ON permit_verification_token');
        $this->addSql('ALTER TABLE permit_verification_token DROP token_sha256, DROP token_encrypted, CHANGE token token CHAR(32) NOT NULL COLLATE `utf8mb4_bin`');
    }
}
