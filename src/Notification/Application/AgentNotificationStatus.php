<?php

declare(strict_types=1);

namespace App\Notification\Application;

use App\Notification\Domain\ChangeCategory;
use App\Notification\Domain\NotificationOutbox;
use App\Notification\Domain\NotificationOutboxRepository;
use App\Notification\Domain\NotificationType;
use App\Notification\Domain\OutboxErrorCode;
use App\Notification\Domain\OutboxStatus;
use App\Permit\Application\NotificationStatusProvider;
use App\Permit\Application\Read\NotificationLine;
use App\Permit\Application\Read\NotificationOverview;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Uid\Uuid;

/**
 * Suivi des notifications pour l'agent (LOT 05.3.4) : libellés en français uniquement. Jamais un secret, une empreinte, un identifiant technique,
 * un code interne, une adresse ni une tentative. Les messages de mise à disposition concernent le laissez-passer entier ; une extension n'est
 * montrée qu'à la commune dont elle porte l'autorisation (les autres communes ne sont ni nommées ni comptées).
 *
 * « Envoyé au serveur de messagerie » : le serveur a ACCEPTÉ le message ; la réception par le bénéficiaire n'est pas garantie.
 */
#[AsAlias(NotificationStatusProvider::class)]
final readonly class AgentNotificationStatus implements NotificationStatusProvider
{
    public function __construct(private NotificationOutboxRepository $outbox)
    {
    }

    public function overviewFor(Uuid $permitId, array $ownAuthorizationIds): NotificationOverview
    {
        $own = array_map(static fn (Uuid $id): string => $id->toRfc4122(), $ownAuthorizationIds);
        $rows = $this->outbox->findForPermit($permitId);
        $firstPending = false;
        $lastFirst = null;
        foreach ($rows as $row) {
            if (ChangeCategory::FirstIssuance === $row->category()) {
                $lastFirst ??= $row; // du plus récent au plus ancien
                $firstPending = $firstPending || \in_array($row->status(), [OutboxStatus::Pending, OutboxStatus::Reserved], true);
            }
        }
        $lines = [];
        foreach ($rows as $row) {
            if (ChangeCategory::FirstIssuance !== $row->category() && !\in_array($row->authorizationId()?->toRfc4122(), $own, true)) {
                continue; // jamais l'extension d'une autre commune
            }
            $lines[] = $this->line($row, $firstPending);
        }
        $delivered = false;
        foreach ($rows as $row) {
            $delivered = $delivered || (ChangeCategory::FirstIssuance === $row->category() && OutboxStatus::Sent === $row->status());
        }

        return new NotificationOverview($lines, !$delivered && !$firstPending);
    }

    private function line(NotificationOutbox $row, bool $firstPending): NotificationLine
    {
        $first = ChangeCategory::FirstIssuance === $row->category();
        $title = $first ? ($row->isManualRequest() ? 'Nouvel envoi de la mise à disposition (demandé par un agent)' : 'Mise à disposition du laissez-passer') : match ($row->type()) {
            NotificationType::PermitExtended => 'Prolongation d’une autorisation en cours',
            NotificationType::PermitResumed => 'Reprise d’une autorisation après expiration (réactivation)',
            NotificationType::PermitShortened => 'Raccourcissement d’une autorisation',
            NotificationType::AuthorizationRevoked => 'Révocation d’une autorisation',
            NotificationType::ZonesChanged => 'Ajout ou réintégration de zones',
            NotificationType::ZonesRemoved => 'Retrait de zones',
            NotificationType::VehiclesChanged => 'Ajout ou réactivation de plaques',
            NotificationType::VehiclesRemoved => 'Retrait ou remplacement de plaques',
            NotificationType::QrReplaced => 'Remplacement du QR code',
            default => 'Extension des droits (commune supplémentaire)',
        };
        $code = $row->lastErrorCode();

        return match ($row->status()) {
            OutboxStatus::Pending, OutboxStatus::Reserved => new NotificationLine($title, NotificationLine::PENDING, 'En attente d’envoi', match (true) {
                ChangeCategory::Ordinary === $row->category() && $firstPending => 'Part après le message de mise à disposition.',
                OutboxErrorCode::SmtpTemporary->value === $code => 'Le dernier essai a échoué : une nouvelle tentative est prévue automatiquement.',
                OutboxErrorCode::CryptoUnavailable->value === $code => 'L’envoi reprendra automatiquement dès que possible.',
                default => 'Le message sera envoyé sous peu.',
            }, null),
            OutboxStatus::Sent => new NotificationLine($title, NotificationLine::SENT, 'Envoyé au serveur de messagerie', 'Le serveur a accepté le message ; sa réception par le bénéficiaire n’est pas garantie.', $row->processedAt()),
            OutboxStatus::NoRecipient => new NotificationLine($title, NotificationLine::NONE, 'Aucun e-mail disponible', 'Aucun message n’a été envoyé : remise manuelle possible.', $row->processedAt()),
            OutboxStatus::Abandoned => new NotificationLine($title, NotificationLine::FAILED, 'Échec d’envoi', self::failure($code).' Remise manuelle possible.', $row->processedAt()),
            OutboxStatus::Suppressed => new NotificationLine($title, NotificationLine::SKIPPED, 'Non envoyé', match ($code) {
                OutboxErrorCode::AlreadyIncluded->value => 'Ces droits figuraient déjà dans le message de mise à disposition.',
                OutboxErrorCode::SupersededByRevocation->value => 'Cette autorisation a été révoquée depuis : ce message est devenu sans objet et la révocation est annoncée à la place.',
                OutboxErrorCode::FirstNotDelivered->value => 'Le message de mise à disposition n’a pas été remis : annoncer une extension serait trompeur. Remise manuelle possible.',
                default => 'Ces droits ont changé avant l’envoi : le message aurait été inexact.',
            }, $row->processedAt()),
        };
    }

    private static function failure(?string $code): string
    {
        return match ($code) {
            OutboxErrorCode::SmtpPermanent->value => 'Le serveur de messagerie a refusé le message.',
            OutboxErrorCode::AddressInvalid->value => 'L’adresse e-mail n’est pas valide.',
            OutboxErrorCode::SmtpExhausted->value, OutboxErrorCode::SmtpWindowExpired->value => 'Les nouvelles tentatives d’envoi ont échoué.',
            OutboxErrorCode::NotRelevant->value, OutboxErrorCode::LinkRevoked->value => 'Le laissez-passer ou son lien n’était plus utilisable au moment de l’envoi.',
            default => 'L’envoi automatique n’a pas abouti.',
        };
    }
}
