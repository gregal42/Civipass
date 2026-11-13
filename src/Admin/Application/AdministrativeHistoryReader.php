<?php

declare(strict_types=1);

namespace App\Admin\Application;

use App\Municipality\Domain\Municipality;
use App\Shared\Domain\AuditAction;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Historique ADMINISTRATIF d'une commune pour CWS : liste blanche d'actions (ouverture, statut, contact, accès commerciaux,
 * invitations d'administrateur, rattachements). Jamais d'événement, de demande ni de laissez-passer ; aucune valeur d'audit n'est
 * lue (seuls l'instant, l'action et l'auteur).
 */
final readonly class AdministrativeHistoryReader
{
    public const LABELS = [
        'MUNICIPALITY_OPENED_BY_CWS' => 'Commune ouverte',
        'MUNICIPALITY_REGISTERED_FROM_REFERENTIAL' => 'Commune inscrite par une invitation',
        'MUNICIPALITY_STATUS_CHANGED' => 'Statut modifié',
        'MUNICIPALITY_CONTACT_CHANGED' => 'Contact modifié',
        'COMMERCIAL_ACCESS_GRANTED' => 'Accès commercial attribué',
        'COMMERCIAL_ACCESS_RENEWED' => 'Accès commercial renouvelé',
        'COMMERCIAL_ACCESS_REVOKED' => 'Accès commercial révoqué',
        'ADMIN_INVITATION_ISSUED' => 'Invitation d’administrateur émise',
        'ADMIN_INVITATION_RESENT' => 'Invitation d’administrateur renvoyée',
        'ADMIN_INVITATION_REVOKED' => 'Invitation d’administrateur révoquée',
        'ADMIN_INVITATION_ACCEPTED' => 'Invitation d’administrateur acceptée',
        'ADMIN_INVITATION_EMAIL_SENT' => 'E-mail d’invitation envoyé',
        'ADMIN_INVITATION_EMAIL_FAILED' => 'Échec d’envoi de l’e-mail d’invitation',
        'MEMBERSHIP_CREATED' => 'Rattachement créé',
        'MEMBERSHIP_ACTIVATED' => 'Rattachement réactivé',
        'MEMBERSHIP_ROLES_CHANGED' => 'Rôles modifiés',
        'MEMBERSHIP_DEACTIVATED' => 'Rattachement désactivé',
    ];

    public function __construct(private Connection $connection)
    {
    }

    /**
     * @return list<array{at: \DateTimeImmutable, label: string, actor: string}>
     */
    public function forMunicipality(Municipality $municipality, int $limit = 50): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT a.occurred_at AS at, a.action AS action, u.first_name AS fn, u.last_name AS ln'
            .' FROM audit_log a LEFT JOIN identity_user u ON u.id = a.actor_id'
            .' WHERE a.municipality_id = :m AND a.action IN (:actions) ORDER BY a.occurred_at DESC, a.id DESC LIMIT '.max(1, min(200, $limit)),
            ['m' => $municipality->id()->toBinary(), 'actions' => array_keys(self::LABELS)],
            ['actions' => ArrayParameterType::STRING],
        );

        return array_map(static fn (array $r): array => [
            'at' => new \DateTimeImmutable((string) $r['at'], new \DateTimeZone('UTC')),
            'label' => self::LABELS[(string) $r['action']] ?? (string) $r['action'],
            'actor' => null === $r['fn'] ? 'Système / console' : trim($r['fn'].' '.$r['ln']),
        ], $rows);
    }

    public static function assertLabelsCoverKnownActions(): void
    {
        foreach (array_keys(self::LABELS) as $value) {
            AuditAction::from($value);
        }
    }
}
