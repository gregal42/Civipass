<?php

declare(strict_types=1);

namespace App\Retention\Application;

use App\Archive\Application\ArchiveSettings;
use App\Retention\Domain\PurgeRecord;
use App\Retention\Domain\PurgeRecordKind;
use App\Shared\Application\TransactionRunner;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Uid\Uuid;

/**
 * Préavis de purge aux `MUNICIPALITY_ADMIN` d'une commune pour un événement (LOT 05.6.4, DM-120) : un e-mail SANS donnée personnelle (référence et nom de l'événement,
 * nombre de dossiers, date de purge possible, rappel du gel), puis une ligne `NOTICE` au registre. Un préavis est dû quand `clôture + durée de conservation − préavis` est
 * atteint et qu'aucun préavis n'existe pour (événement, commune). Un échec d'envoi n'écrit RIEN (rejouable) ; une commune sans administrateur actif ne reçoit rien et
 * n'est JAMAIS purgée (pas de préavis, pas de purge). Les dossiers gelés ne comptent pas. Simulation par défaut côté commande.
 */
final class RetentionNoticeService
{
    public function __construct(
        private readonly Connection $connection,
        private readonly EntityManagerInterface $entityManager,
        private readonly TransactionRunner $transaction,
        private readonly MailerInterface $mailer,
        private readonly ClockInterface $clock,
        private readonly RetentionSettings $settings,
        private readonly ArchiveSettings $archive,
        private readonly PurgeRegisterFile $register,
        #[Autowire('%env(MAILER_FROM)%')] private readonly string $from,
    ) {
    }

    public function run(bool $execute, int $limit = 100): RetentionRunReport
    {
        $report = new RetentionRunReport();
        $now = $this->clock->now();
        $rows = $this->connection->fetchAllAssociative(
            'SELECT e.id AS event_id, e.public_reference, e.name, e.closed_at, rm.municipality_id, COUNT(*) AS n'
            .' FROM request_permit_request_municipality rm JOIN request_permit_request r ON r.id = rm.request_id JOIN event_event e ON e.id = r.event_id'
            .' WHERE e.status = \'CLOSED\' AND e.closed_at IS NOT NULL'
            .' AND NOT EXISTS (SELECT 1 FROM archive_legal_hold h WHERE h.request_municipality_id = rm.id AND h.active_marker = 1)'
            .' AND NOT EXISTS (SELECT 1 FROM retention_purge_record n WHERE n.kind = \'NOTICE\' AND n.event_id = e.id AND n.municipality_id = rm.municipality_id)'
            .' GROUP BY e.id, e.public_reference, e.name, e.closed_at, rm.municipality_id ORDER BY e.closed_at, e.id, rm.municipality_id',
        );
        foreach ($rows as $row) {
            $closedAt = new \DateTimeImmutable((string) $row['closed_at'], new \DateTimeZone('UTC'));
            $eligibleAt = $this->archive->purgeEligibleAt($closedAt);
            if ($eligibleAt->modify(sprintf('-%d days', $this->settings->noticeDays())) > $now) {
                continue; // pas encore dû
            }
            if ($report->candidates >= $limit) {
                break;
            }
            ++$report->candidates;
            $municipalityId = Uuid::fromBinary((string) $row['municipality_id']);
            $recipients = array_map(static fn (mixed $v): string => (string) $v, $this->connection->fetchFirstColumn(
                'SELECT u.email FROM identity_user u JOIN municipality_membership m ON m.user_id = u.id WHERE m.municipality_id = :m AND m.active = 1 AND u.active = 1 AND JSON_CONTAINS(m.roles, \'"MUNICIPALITY_ADMIN"\') ORDER BY u.email',
                ['m' => $municipalityId->toBinary()],
            ));
            if ([] === $recipients) {
                $report->reason('NO_ADMINISTRATOR'); // aucun préavis possible : le dossier ne sera pas purgé tant qu'un administrateur n'est pas joignable
                ++$report->notEligible;
                continue;
            }
            if (!$execute) {
                ++$report->done;
                $report->reason('WOULD_NOTIFY');
                continue;
            }
            $dueAt = max($eligibleAt, $now->modify(sprintf('+%d days', $this->settings->noticeDays())));
            try {
                $this->mailer->send($this->email($recipients, (string) $row['public_reference'], (string) $row['name'], (int) $row['n'], $dueAt));
            } catch (\Throwable $e) {
                ++$report->failed;
                $report->failures[] = $e::class;
                continue;
            }
            /** @var PurgeRecord $record */
            $record = $this->transaction->run(function () use ($row, $municipalityId, $now, $dueAt, $recipients): PurgeRecord {
                $record = new PurgeRecord(PurgeRecordKind::Notice, Uuid::fromBinary((string) $row['event_id']), (string) $row['public_reference'], $municipalityId, null, null, $now, $dueAt, null, ['dossiers' => (int) $row['n']], 0, \count($recipients), null);
                $this->entityManager->persist($record);

                return $record;
            });
            ++$report->done;
            try {
                $this->register->append($record);
            } catch (\Throwable) {
                ++$report->registerFileFailures; // divergence réparable par `app:retention:register-check --repair`
            }
        }

        return $report;
    }

    /** @param list<string> $recipients */
    private function email(array $recipients, string $reference, string $name, int $dossiers, \DateTimeImmutable $dueAt): Email
    {
        $email = (new Email())->from($this->from)->subject(sprintf('Civipass — purge prévue des dossiers archivés de l\'événement %s', $reference));
        foreach ($recipients as $address) {
            $email->addTo($address);
        }

        return $email->text(sprintf(
            "Bonjour,\n\nLes %d dossier(s) de votre commune pour l'événement « %s » (%s) pourront être définitivement supprimés à partir du %s, sauf gel de conservation.\n\n"
            ."Avant cette date, vous pouvez consulter ces archives et, si une conservation reste nécessaire, geler les dossiers concernés dans l'espace « Archives ».\n"
            ."Cette durée est indicative et soumise à validation ; cet e-mail ne contient aucune donnée personnelle.\n",
            $dossiers,
            $name,
            $reference,
            $dueAt->setTimezone(new \DateTimeZone('Europe/Paris'))->format('d/m/Y'),
        ));
    }
}
