<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * DM-124 — cumul des rôles par défaut d'un administrateur communal : les administrateurs existants (rattachements actifs ou non) et les invitations
 * non consommées qui portent le rôle d'administrateur reçoivent aussi les rôles de gestionnaire d'événements et d'instructeur. Données seules, aucun
 * changement de schéma. Les rôles restent modifiables ensuite par l'administrateur (écran Équipe).
 */
final class Version20261114204500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'DM-124 : les administrateurs communaux existants cumulent les trois rôles.';
    }

    public function up(Schema $schema): void
    {
        // Ordre de stockage de chaque table : rattachements en ordre alphabétique, invitations en ordre canonique de l'énumération.
        $membership = "JSON_ARRAY('EVENT_MANAGER', 'INSTRUCTOR', 'MUNICIPALITY_ADMIN')";
        $invitation = "JSON_ARRAY('MUNICIPALITY_ADMIN', 'EVENT_MANAGER', 'INSTRUCTOR')";
        $this->addSql("UPDATE municipality_membership SET roles = $membership WHERE JSON_CONTAINS(roles, JSON_QUOTE('MUNICIPALITY_ADMIN')) AND roles <> $membership");
        $this->addSql("UPDATE municipality_admin_invitation SET roles = $invitation WHERE consumed_at IS NULL AND revoked_at IS NULL AND JSON_CONTAINS(roles, JSON_QUOTE('MUNICIPALITY_ADMIN')) AND roles <> $invitation");
    }

    public function down(Schema $schema): void
    {
        // Irréversible sans perte d'information : les rôles d'origine ne sont pas conservés. Le retour arrière est la sauvegarde prise avant l'exécution.
        $this->warnIf(true, 'Aucun retour arrière automatique (données) : restaurer la sauvegarde.');
    }
}
