<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Uid\Uuid;

/**
 * LOT 03, incrément 1 : module Request (docs/lot-03-schema.md).
 *
 * - 8 nouvelles tables : request_motif, request_beneficiary_category, request_permit_request, _municipality, _zone,
 *   _vehicle, _attachment, request_reference_counter (clés étrangères RESTRICT, aucune suppression physique).
 * - Aucune table existante n'est modifiée.
 * - Données de référence : motifs et catégories validés (insertion idempotente par code : jamais d'écrasement ni de
 *   doublon). Le compteur de références n'est pas initialisé : la ligne d'une année naît au premier dépôt de l'année.
 */
final class Version20261105120533 extends AbstractMigration
{
    /** Liste validée (DM-097, DT-039), figée dans cette migration ; RequestReferentialInitializer rejoue la même initialisation. */
    private const MOTIFS = [
        ['home', 'Accès au domicile', false],
        ['workplace', 'Accès au lieu de travail', false],
        ['professional', 'Intervention professionnelle', false],
        ['relative_assistance', 'Assistance à un proche', false],
        ['priority', 'Intervention prioritaire', false],
        ['land_or_holding', 'Accès à une exploitation ou un terrain', false],
        ['other', 'Autre', true],
    ];

    /** Liste validée (DM-098, DT-039), figée dans cette migration. */
    private const CATEGORIES = [
        ['private_person', 'Particuliers'],
        ['professional', 'Professionnels'],
        ['priority_responder', 'Intervenants prioritaires'],
        ['public_agent', 'Agents publics'],
        ['volunteer', 'Bénévoles'],
    ];

    public function getDescription(): string
    {
        return 'LOT 03 : module Request (8 tables), motifs et catégories de bénéficiaires initiaux.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE request_beneficiary_category (id BINARY(16) NOT NULL, code VARCHAR(40) NOT NULL COLLATE `utf8mb4_bin`, label VARCHAR(150) NOT NULL, position SMALLINT DEFAULT 0 NOT NULL, active TINYINT DEFAULT 1 NOT NULL, UNIQUE INDEX uniq_request_beneficiary_category_code (code), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE request_motif (id BINARY(16) NOT NULL, code VARCHAR(40) NOT NULL COLLATE `utf8mb4_bin`, label VARCHAR(150) NOT NULL, position SMALLINT DEFAULT 0 NOT NULL, active TINYINT DEFAULT 1 NOT NULL, needs_detail TINYINT DEFAULT 0 NOT NULL, UNIQUE INDEX uniq_request_motif_code (code), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE request_permit_request (id BINARY(16) NOT NULL, public_reference VARCHAR(20) NOT NULL COLLATE `utf8mb4_bin`, origin VARCHAR(10) NOT NULL, last_name VARCHAR(120) NOT NULL, first_name VARCHAR(120) NOT NULL, address VARCHAR(255) DEFAULT NULL, email VARCHAR(180) DEFAULT NULL, phone VARCHAR(16) DEFAULT NULL, category_label VARCHAR(150) NOT NULL, motif_label VARCHAR(150) NOT NULL, motif_detail VARCHAR(500) DEFAULT NULL, travel_mode VARCHAR(20) NOT NULL, privacy_attested_at DATETIME NOT NULL, privacy_notice_version VARCHAR(40) DEFAULT NULL, submitted_at DATETIME NOT NULL, category_id BINARY(16) NOT NULL, motif_id BINARY(16) NOT NULL, entered_by_user_id BINARY(16) DEFAULT NULL, entered_by_municipality_id BINARY(16) DEFAULT NULL, event_id BINARY(16) NOT NULL, INDEX idx_request_permit_request_event_submitted (event_id, submitted_at), UNIQUE INDEX uniq_request_permit_request_reference (public_reference), INDEX IDX_8A2FD61E12469DE2 (category_id), INDEX IDX_8A2FD61ED0EEB819 (motif_id), INDEX IDX_8A2FD61ECBDCE1E6 (entered_by_user_id), INDEX IDX_8A2FD61EB88C1226 (entered_by_municipality_id), INDEX IDX_8A2FD61E71F7E88B (event_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE request_permit_request_attachment (id BINARY(16) NOT NULL, original_name VARCHAR(255) NOT NULL, mime_type VARCHAR(120) NOT NULL, size_bytes INT NOT NULL, storage_key VARCHAR(100) NOT NULL COLLATE `utf8mb4_bin`, uploaded_at DATETIME NOT NULL, request_id BINARY(16) NOT NULL, UNIQUE INDEX uniq_request_attachment_storage_key (storage_key), INDEX IDX_2B28ED60427EB8A5 (request_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE request_permit_request_municipality (id BINARY(16) NOT NULL, status VARCHAR(20) NOT NULL, created_at DATETIME NOT NULL, municipality_id BINARY(16) NOT NULL, request_id BINARY(16) NOT NULL, participation_id BINARY(16) NOT NULL, INDEX idx_request_municipality_municipality_status (municipality_id, status), UNIQUE INDEX uniq_request_municipality_request_participation (request_id, participation_id), INDEX IDX_858DDC3EAE6F181C (municipality_id), INDEX IDX_858DDC3E427EB8A5 (request_id), INDEX IDX_858DDC3E6ACE3B73 (participation_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE request_permit_request_vehicle (id BINARY(16) NOT NULL, registration VARCHAR(12) NOT NULL COLLATE `utf8mb4_bin`, format VARCHAR(3) NOT NULL, position SMALLINT NOT NULL, request_id BINARY(16) NOT NULL, UNIQUE INDEX uniq_request_vehicle_request_registration (request_id, registration), INDEX IDX_C3460F4D427EB8A5 (request_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE request_permit_request_zone (id BINARY(16) NOT NULL, zone_label VARCHAR(150) NOT NULL, request_municipality_id BINARY(16) NOT NULL, zone_id BINARY(16) NOT NULL, UNIQUE INDEX uniq_request_zone_part_zone (request_municipality_id, zone_id), INDEX IDX_D35C0EA69D375344 (request_municipality_id), INDEX IDX_D35C0EA69F2C3FAB (zone_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE request_reference_counter (year SMALLINT UNSIGNED NOT NULL, last_number INT UNSIGNED DEFAULT 0 NOT NULL, PRIMARY KEY (year)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE request_permit_request ADD CONSTRAINT FK_8A2FD61E12469DE2 FOREIGN KEY (category_id) REFERENCES request_beneficiary_category (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE request_permit_request ADD CONSTRAINT FK_8A2FD61ED0EEB819 FOREIGN KEY (motif_id) REFERENCES request_motif (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE request_permit_request ADD CONSTRAINT FK_8A2FD61ECBDCE1E6 FOREIGN KEY (entered_by_user_id) REFERENCES identity_user (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE request_permit_request ADD CONSTRAINT FK_8A2FD61EB88C1226 FOREIGN KEY (entered_by_municipality_id) REFERENCES municipality (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE request_permit_request ADD CONSTRAINT FK_8A2FD61E71F7E88B FOREIGN KEY (event_id) REFERENCES event_event (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE request_permit_request_attachment ADD CONSTRAINT FK_2B28ED60427EB8A5 FOREIGN KEY (request_id) REFERENCES request_permit_request (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE request_permit_request_municipality ADD CONSTRAINT FK_858DDC3EAE6F181C FOREIGN KEY (municipality_id) REFERENCES municipality (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE request_permit_request_municipality ADD CONSTRAINT FK_858DDC3E427EB8A5 FOREIGN KEY (request_id) REFERENCES request_permit_request (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE request_permit_request_municipality ADD CONSTRAINT FK_858DDC3E6ACE3B73 FOREIGN KEY (participation_id) REFERENCES event_participation (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE request_permit_request_vehicle ADD CONSTRAINT FK_C3460F4D427EB8A5 FOREIGN KEY (request_id) REFERENCES request_permit_request (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE request_permit_request_zone ADD CONSTRAINT FK_D35C0EA69D375344 FOREIGN KEY (request_municipality_id) REFERENCES request_permit_request_municipality (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE request_permit_request_zone ADD CONSTRAINT FK_D35C0EA69F2C3FAB FOREIGN KEY (zone_id) REFERENCES event_zone (id) ON DELETE RESTRICT');

        foreach (self::MOTIFS as $index => [$code, $label, $needsDetail]) {
            $this->addSql(
                'INSERT INTO request_motif (id, code, label, position, active, needs_detail) SELECT :id, :code, :label, :position, 1, :needsDetail FROM DUAL'
                .' WHERE NOT EXISTS (SELECT 1 FROM request_motif WHERE code = :existingCode)',
                ['id' => Uuid::v7()->toBinary(), 'code' => $code, 'label' => $label, 'position' => ($index + 1) * 10, 'needsDetail' => (int) $needsDetail, 'existingCode' => $code],
            );
        }
        foreach (self::CATEGORIES as $index => [$code, $label]) {
            $this->addSql(
                'INSERT INTO request_beneficiary_category (id, code, label, position, active) SELECT :id, :code, :label, :position, 1 FROM DUAL'
                .' WHERE NOT EXISTS (SELECT 1 FROM request_beneficiary_category WHERE code = :existingCode)',
                ['id' => Uuid::v7()->toBinary(), 'code' => $code, 'label' => $label, 'position' => ($index + 1) * 10, 'existingCode' => $code],
            );
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE request_permit_request DROP FOREIGN KEY FK_8A2FD61E12469DE2');
        $this->addSql('ALTER TABLE request_permit_request DROP FOREIGN KEY FK_8A2FD61ED0EEB819');
        $this->addSql('ALTER TABLE request_permit_request DROP FOREIGN KEY FK_8A2FD61ECBDCE1E6');
        $this->addSql('ALTER TABLE request_permit_request DROP FOREIGN KEY FK_8A2FD61EB88C1226');
        $this->addSql('ALTER TABLE request_permit_request DROP FOREIGN KEY FK_8A2FD61E71F7E88B');
        $this->addSql('ALTER TABLE request_permit_request_attachment DROP FOREIGN KEY FK_2B28ED60427EB8A5');
        $this->addSql('ALTER TABLE request_permit_request_municipality DROP FOREIGN KEY FK_858DDC3EAE6F181C');
        $this->addSql('ALTER TABLE request_permit_request_municipality DROP FOREIGN KEY FK_858DDC3E427EB8A5');
        $this->addSql('ALTER TABLE request_permit_request_municipality DROP FOREIGN KEY FK_858DDC3E6ACE3B73');
        $this->addSql('ALTER TABLE request_permit_request_vehicle DROP FOREIGN KEY FK_C3460F4D427EB8A5');
        $this->addSql('ALTER TABLE request_permit_request_zone DROP FOREIGN KEY FK_D35C0EA69D375344');
        $this->addSql('ALTER TABLE request_permit_request_zone DROP FOREIGN KEY FK_D35C0EA69F2C3FAB');
        $this->addSql('DROP TABLE request_beneficiary_category');
        $this->addSql('DROP TABLE request_motif');
        $this->addSql('DROP TABLE request_permit_request');
        $this->addSql('DROP TABLE request_permit_request_attachment');
        $this->addSql('DROP TABLE request_permit_request_municipality');
        $this->addSql('DROP TABLE request_permit_request_vehicle');
        $this->addSql('DROP TABLE request_permit_request_zone');
        $this->addSql('DROP TABLE request_reference_counter');
    }
}
