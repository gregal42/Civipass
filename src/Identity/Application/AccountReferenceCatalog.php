<?php

declare(strict_types=1);

namespace App\Identity\Application;

use Doctrine\DBAL\Connection;

/**
 * Catalogue des références vers `identity_user` (LOT 05.6.5, D1). Découvert dans le SCHÉMA (jamais une liste figée dans la requête) : toute nouvelle clé étrangère vers un compte
 * est vue ; tant qu'elle n'est pas classée ici, elle est traitée comme CONSERVÉE (prudence) et signalée, et un test échoue pour forcer son classement.
 *
 * `OWNED` : lignes qui n'appartiennent qu'au compte (supprimables avec lui). `RETAINED` : historique conservé (décisions, saisies, consultations, gels, désignations,
 * habilitations, révisions, invitations, créations, appartenances, accès commerciaux) : un compte ainsi référencé ne peut qu'être ANONYMISÉ, jamais supprimé sans casser la
 * conservation. Ces catégories sont des INDICATEURS TECHNIQUES : l'absence de référence ne vaut jamais autorisation juridique de supprimer.
 */
final class AccountReferenceCatalog
{
    /** @var list<string> */
    /** Le second facteur CWS (SEC-006) n'appartient qu'au compte (supprimable avec lui) ; ses codes de secours dépendent du second facteur, pas du compte. */
    public const OWNED = ['identity_password_reset_request.user_id', 'identity_two_factor.user_id'];

    /** @var list<string> */
    public const RETAINED = [
        'archive_consultation.user_id',
        'archive_legal_hold.last_reviewed_by_user_id',
        'archive_legal_hold.lifted_by_user_id',
        'archive_legal_hold.placed_by_user_id',
        'archive_manager_designation.designated_by_user_id',
        'archive_manager_designation.revoked_by_user_id',
        'archive_manager_designation.user_id',
        'archive_statistics_coordination.granted_by_user_id',
        'archive_statistics_coordination.revoked_by_user_id',
        'archive_statistics_coordination.user_id',
        'event_event.created_by',
        'event_participation.invited_by',
        'event_zone.created_by',
        'municipality_commercial_access.granted_by',
        'municipality_commercial_access.revoked_by',
        'municipality_membership.user_id',
        'onboarding_invitation_link.created_by',
        // LOT 07.1 : auteur, accepteur et révocateur d'une invitation d'administrateur (traçabilité conservée comme les autres liens d'invitation).
        'municipality_admin_invitation.consumed_by',
        'municipality_admin_invitation.created_by',
        'municipality_admin_invitation.revoked_by',
        // LOT 09 : auteur et révocateur d'un code d'activation, approbateur et désactivateur d'un appareil de contrôle (traçabilité conservée, comme les invitations).
        'control_activation.created_by',
        'control_activation.revoked_by',
        'control_device.approved_by',
        'control_device.deactivated_by',
        // LOT 08.6 : bénéficiaire, attributeur et révocateur d'une habilitation CWS (historique conservé : un compte avec attribution ne peut pas être supprimé).
        // LOT 08.5 : agent ayant informé un demandeur refusé par un autre moyen (traçabilité conservée).
        'request_refusal_notice.manual_by',
        'identity_cws_permission.user_id',
        'identity_cws_permission.granted_by',
        'identity_cws_permission.revoked_by',
        'archive_rectification_mention.created_by_user_id',
        'permit_revision.actor_user_id',
        'request_permit_request.entered_by_user_id',
        'rights_request.conservation_checked_by_user_id',
        'rights_request.decided_by_user_id',
        'rights_request.identity_verified_by_user_id',
        'rights_request.received_by_user_id',
        'request_permit_request_decision.decided_by_user_id',
    ];

    public function __construct(private readonly Connection $connection)
    {
    }

    /** @return list<string> `table.colonne` de toutes les clés étrangères vers `identity_user`, triées */
    public function discover(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT table_name AS t, column_name AS c FROM information_schema.key_column_usage WHERE table_schema = DATABASE() AND referenced_table_name = \'identity_user\' ORDER BY table_name, column_name',
        );

        return array_map(static fn (array $row): string => $row['t'].'.'.$row['c'], $rows);
    }

    /** @return list<string> références découvertes mais NON classées */
    public function unclassified(): array
    {
        return array_values(array_diff($this->discover(), self::OWNED, self::RETAINED));
    }

    public static function isOwned(string $reference): bool
    {
        return \in_array($reference, self::OWNED, true);
    }
}
