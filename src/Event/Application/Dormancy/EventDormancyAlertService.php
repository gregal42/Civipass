<?php

declare(strict_types=1);

namespace App\Event\Application\Dormancy;

use App\Event\Application\EventPermission;
use App\Shared\Application\AuditEvent;
use App\Shared\Application\AuditTrail;
use App\Shared\Application\TransactionRunner;
use App\Shared\Domain\AuditAction;
use App\Shared\Domain\AuditActor;
use App\Shared\Domain\AuditSubjectType;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Alerte de dormance (LOT 05.6.5, décision D3) : prévient les `MUNICIPALITY_ADMIN` et `EVENT_MANAGER` actifs de la commune ORGANISATRICE d'un événement sans fin planifiée et inactif.
 * Ne clôture JAMAIS, ne modifie ni statut ni droit.
 *
 * Mécanisme d'envoi des courriels d'agents (comme l'avis de rétention et les invitations) : l'outbox existante est propre aux laissez-passer (clé `permit_id` obligatoire) et n'est
 * pas modifiée. Garanties : (1) PRISE ATOMIQUE par `UPDATE … WHERE dormancy_alerted_at <=> :vu` : de deux exécutions simultanées, une seule envoie ; (2) une prise n'est un ENVOI qu'une
 * fois la ligne d'audit « envoyée » écrite : sinon c'est un bail, repris après `EVENT_DORMANCY_LEASE_MINUTES` ; (3) échec d'envoi : la valeur précédente est restaurée (rejouable)
 * et l'échec est tracé ; (4) un plantage entre l'envoi et l'audit peut rejouer UN courriel après le bail (au moins une fois, jamais en concurrence).
 */
final class EventDormancyAlertService
{
    public function __construct(
        private readonly EventDormancyDetector $detector,
        private readonly Connection $connection,
        private readonly TransactionRunner $transaction,
        private readonly AuditTrail $audit,
        private readonly MailerInterface $mailer,
        private readonly UrlGeneratorInterface $urls,
        private readonly ClockInterface $clock,
        private readonly DormancySettings $settings,
        #[Autowire('%env(MAILER_FROM)%')] private readonly string $from,
        #[Autowire('%env(DEFAULT_URI)%')] private readonly string $baseUrl,
    ) {
    }

    public function run(bool $execute, int $limit = 100): DormancyRunReport
    {
        $report = new DormancyRunReport();
        $handled = 0;
        foreach ($this->detector->scan() as $event) {
            ++$report->detected;
            if (!$event->claimable()) {
                $report->skip($event->state);
                continue;
            }
            if ($handled >= $limit) {
                $report->skip('LIMIT');
                continue;
            }
            $recipients = $this->recipients($event->organizerId);
            if ([] === $recipients) {
                $report->skip('NO_RECIPIENT'); // aucune prise : rien n'est réservé
                continue;
            }
            ++$handled;
            if (!$execute) {
                ++$report->alerted;
                $report->reasons['WOULD_ALERT'] = ($report->reasons['WOULD_ALERT'] ?? 0) + 1;
                continue;
            }
            $this->alert($event, $recipients, $report);
        }

        return $report;
    }

    /** @param list<string> $recipients */
    private function alert(DormantEvent $event, array $recipients, DormancyRunReport $report): void
    {
        $id = Uuid::fromString($event->eventId);
        $now = $this->clock->now()->setTimezone(new \DateTimeZone('UTC')); // dates brutes SQL en UTC
        $stamp = $now->format('Y-m-d H:i:s');
        $seen = $event->alertedAt?->format('Y-m-d H:i:s');
        $claimed = $this->connection->executeStatement(
            'UPDATE event_event SET dormancy_alerted_at = :now WHERE id = :id AND status IN (\'ACTIVE\', \'SUSPENDED\') AND ends_at IS NULL'
            .' AND (dormancy_snoozed_until IS NULL OR dormancy_snoozed_until <= :now) AND dormancy_alerted_at <=> :seen',
            ['now' => $stamp, 'id' => $id->toBinary(), 'seen' => $seen],
        );
        if (1 !== $claimed) {
            $report->skip('LOST_RACE'); // une autre exécution l'a prise, ou l'événement a changé (fin, clôture, report)

            return;
        }
        try {
            $this->mailer->send($this->email($event, $recipients));
        } catch (\Throwable $e) {
            // Prise restaurée seulement si elle est encore la nôtre ; l'échec est tracé, l'événement reste rejouable.
            $this->connection->executeStatement('UPDATE event_event SET dormancy_alerted_at = :seen WHERE id = :id AND dormancy_alerted_at = :now', ['seen' => $seen, 'id' => $id->toBinary(), 'now' => $stamp]);
            $this->record(AuditAction::DormancyAlertFailed, $event, ['error' => $e::class]);
            ++$report->failed;

            return;
        }
        try {
            $this->record(AuditAction::DormancyAlertSent, $event, ['recipients' => \count($recipients), 'inactive_days' => $event->inactiveDays]);
        } catch (\Throwable) {
            // Courriel parti mais non confirmé : la prise reste un bail, reprise après son expiration (au moins une fois).
            ++$report->failed;
            $report->reasons['AUDIT_PENDING'] = ($report->reasons['AUDIT_PENDING'] ?? 0) + 1;

            return;
        }
        ++$report->alerted;
    }

    /** @param array<string, int|string> $values */
    private function record(AuditAction $action, DormantEvent $event, array $values): void
    {
        $this->transaction->run(function () use ($action, $event, $values): void {
            $this->audit->record(new AuditEvent($this->clock->now(), $action, AuditSubjectType::Event, Uuid::fromString($event->eventId), Uuid::fromString($event->organizerId), AuditActor::system(), null, $values));
        });
    }

    /** @return list<string> adresses des administrateurs et organisateurs ACTIFS de la commune organisatrice, jamais des autres communes */
    private function recipients(string $organizerId): array
    {
        // Rôles issus de la source de vérité unique (EventPermission::dormancyRoles), jamais recopiés ici.
        $roles = [];
        $params = ['m' => Uuid::fromString($organizerId)->toBinary()];
        foreach (EventPermission::dormancyRoles() as $i => $role) {
            $roles[] = 'JSON_CONTAINS(m.roles, :r'.$i.')';
            $params['r'.$i] = json_encode($role->value, \JSON_THROW_ON_ERROR);
        }

        return array_map(static fn (mixed $v): string => (string) $v, $this->connection->fetchFirstColumn(
            'SELECT DISTINCT u.email FROM identity_user u JOIN municipality_membership m ON m.user_id = u.id'
            .' WHERE m.municipality_id = :m AND m.active = 1 AND u.active = 1 AND ('.implode(' OR ', $roles).') ORDER BY u.email',
            $params,
        ));
    }

    /** @param list<string> $recipients */
    private function email(DormantEvent $event, array $recipients): Email
    {
        $email = (new Email())->from($this->from)->subject(sprintf('Civipass — événement sans fin planifiée et sans activité : %s', $event->reference));
        foreach ($recipients as $address) {
            $email->addTo($address);
        }
        // Lien bâti depuis DEFAULT_URI, jamais depuis l'en-tête Host.
        $link = rtrim($this->baseUrl, '/').$this->urls->generate('app_municipal_event', ['municipalityId' => $event->organizerId, 'eventId' => $event->eventId], UrlGeneratorInterface::ABSOLUTE_PATH);

        return $email->text(sprintf(
            "Bonjour,\n\nL'événement « %s » (%s) n'a pas de date de fin planifiée et aucune activité n'y a été constatée depuis %d jours.\n\n"
            ."Civipass ne clôture jamais un événement automatiquement et ne modifie aucun droit. Si l'événement est terminé, vous pouvez le clôturer ; s'il se poursuit, vous pouvez reporter ce rappel avec un motif depuis sa fiche :\n%s\n\n"
            ."Ce rappel est renouvelé tous les %d jours tant que la situation persiste. Cet e-mail ne contient aucune donnée personnelle.\n",
            $event->name,
            $event->reference,
            $event->inactiveDays,
            $link,
            $this->settings->reminderDays(),
        ));
    }
}
