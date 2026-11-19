<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * SEC-020, ÉTAPE 2/2 : suppression du jeton EN CLAIR. Ne s'exécute que si TOUS les jetons ont une copie scellée (`app:permit:protect-tokens` joué et
 * vérifié) : sinon la migration s'ARRÊTE sans rien modifier, car la copie en clair serait la seule trace du QR.
 *
 * Retour arrière : `down()` recrée la colonne `token` VIDE (facultative). Pour récupérer les clairs : `app:permit:protect-tokens --restore-clear` (rouvre
 * les copies scellées), à jouer AVANT de revenir à l'ancien code. En dernier recours : la sauvegarde chiffrée prise avant la migration (obligatoire).
 */
final class Version20261119140100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'SEC-020 (2/2) : suppression du jeton en clair (permit_verification_token.token) après vérification des copies scellées.';
    }

    public function preUp(Schema $schema): void
    {
        $missing = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM permit_verification_token WHERE token_encrypted IS NULL OR token_encrypted = \'\'');
        $this->abortIf($missing > 0, sprintf('%d jeton(s) sans copie scellée : jouer app:permit:protect-tokens avant cette migration (SEC-020). Rien n\'a été modifié.', $missing));
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_permit_token_value ON permit_verification_token');
        $this->addSql('ALTER TABLE permit_verification_token DROP token, CHANGE token_encrypted token_encrypted VARCHAR(255) NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE permit_verification_token ADD token CHAR(32) DEFAULT NULL COLLATE `utf8mb4_bin`, CHANGE token_encrypted token_encrypted VARCHAR(255) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_permit_token_value ON permit_verification_token (token)');
    }
}
