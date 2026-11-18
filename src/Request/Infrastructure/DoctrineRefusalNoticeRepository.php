<?php

declare(strict_types=1);

namespace App\Request\Infrastructure;

use App\Request\Application\Read\RefusalNoticeFacts;
use App\Request\Application\Read\RefusalToCommunicate;
use App\Request\Domain\RefusalNotice;
use App\Request\Domain\RefusalNoticeRepository;
use App\Request\Domain\RefusalNoticeStatus;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Uid\Uuid;

#[AsAlias(RefusalNoticeRepository::class)]
final readonly class DoctrineRefusalNoticeRepository implements RefusalNoticeRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function add(RefusalNotice $notice): void
    {
        $this->entityManager->persist($notice);
    }

    public function find(Uuid $id): ?RefusalNotice
    {
        return $this->entityManager->find(RefusalNotice::class, $id);
    }

    public function findByDecision(Uuid $decisionId): ?RefusalNotice
    {
        return $this->entityManager->getRepository(RefusalNotice::class)->findOneBy(['decision' => $decisionId]);
    }

    public function findDue(\DateTimeImmutable $now, int $limit): array
    {
        $rows = $this->db()->fetchFirstColumn(
            "SELECT id FROM request_refusal_notice WHERE status = 'PENDING' AND available_at <= :now AND (leased_until IS NULL OR leased_until < :now) ORDER BY available_at, id LIMIT ".max(1, $limit),
            ['now' => self::utc($now)],
        );

        return array_map(static fn (mixed $id): Uuid => Uuid::fromBinary((string) $id), $rows);
    }

    public function claim(Uuid $id, \DateTimeImmutable $now, \DateTimeImmutable $leaseUntil): ?array
    {
        $claimed = 1 === (int) $this->db()->executeStatement(
            "UPDATE request_refusal_notice SET leased_until = :lease, attempts = attempts + 1, first_attempt_at = COALESCE(first_attempt_at, :now) WHERE id = :id AND status = 'PENDING' AND available_at <= :now AND (leased_until IS NULL OR leased_until < :now)",
            ['lease' => self::utc($leaseUntil), 'now' => self::utc($now), 'id' => $id->toBinary()],
        );
        if (!$claimed) {
            return null;
        }
        $row = $this->db()->fetchAssociative('SELECT attempts, first_attempt_at FROM request_refusal_notice WHERE id = :id', ['id' => $id->toBinary()]);

        return \is_array($row) ? ['attempts' => (int) $row['attempts'], 'firstAttemptAt' => new \DateTimeImmutable((string) $row['first_attempt_at'], new \DateTimeZone('UTC'))] : null;
    }

    public function finish(Uuid $id, \DateTimeImmutable $leaseUntil, RefusalNoticeStatus $status, \DateTimeImmutable $now, ?string $errorCode): bool
    {
        return 1 === (int) $this->db()->executeStatement(
            "UPDATE request_refusal_notice SET status = :status, processed_at = :now, leased_until = NULL, last_error_code = :code WHERE id = :id AND status = 'PENDING' AND leased_until = :lease",
            ['status' => $status->value, 'now' => self::utc($now), 'code' => $errorCode, 'id' => $id->toBinary(), 'lease' => self::utc($leaseUntil)],
        );
    }

    public function retryAt(Uuid $id, \DateTimeImmutable $leaseUntil, \DateTimeImmutable $next, string $errorCode): bool
    {
        return 1 === (int) $this->db()->executeStatement(
            "UPDATE request_refusal_notice SET available_at = :next, leased_until = NULL, last_error_code = :code WHERE id = :id AND status = 'PENDING' AND leased_until = :lease",
            ['next' => self::utc($next), 'code' => $errorCode, 'id' => $id->toBinary(), 'lease' => self::utc($leaseUntil)],
        );
    }

    public function facts(Uuid $id): ?RefusalNoticeFacts
    {
        $row = $this->db()->fetchAssociative(
            'SELECT r.email AS recipient, r.public_reference AS reference, e.name AS event_name, m.name AS municipality_name, m.contact_email AS contact_email, m.contact_phone AS contact_phone,'
            .' d.refusal_reason_code AS reason_code, d.refusal_reason_label AS reason_label'
            .' FROM request_refusal_notice n JOIN request_permit_request_decision d ON d.id = n.decision_id'
            .' JOIN request_permit_request_municipality rm ON rm.id = d.request_municipality_id JOIN request_permit_request r ON r.id = rm.request_id'
            .' JOIN event_event e ON e.id = r.event_id JOIN municipality m ON m.id = n.municipality_id WHERE n.id = :id',
            ['id' => $id->toBinary()],
        );
        if (!\is_array($row)) {
            return null;
        }

        return new RefusalNoticeFacts(
            trim((string) ($row['recipient'] ?? '')),
            (string) $row['reference'],
            (string) $row['event_name'],
            (string) $row['municipality_name'],
            null === $row['contact_email'] ? null : (string) $row['contact_email'],
            null === $row['contact_phone'] ? null : (string) $row['contact_phone'],
            null === $row['reason_code'] ? null : (string) $row['reason_code'],
            null === $row['reason_label'] ? null : (string) $row['reason_label'],
        );
    }

    public function countToCommunicate(Uuid $municipalityId): int
    {
        return (int) $this->db()->fetchOne(
            "SELECT COUNT(*) FROM request_refusal_notice n JOIN request_permit_request_decision d ON d.id = n.decision_id JOIN request_permit_request_municipality rm ON rm.id = d.request_municipality_id"
            ." JOIN request_permit_request r ON r.id = rm.request_id JOIN event_event e ON e.id = r.event_id"
            ." WHERE n.municipality_id = :m AND n.status IN ('NO_RECIPIENT', 'ABANDONED') AND e.status <> 'CLOSED'",
            ['m' => $municipalityId->toBinary()],
        );
    }

    public function toCommunicate(Uuid $municipalityId, int $limit): array
    {
        $rows = $this->db()->fetchAllAssociative(
            'SELECT n.id AS notice_id, r.id AS request_id, r.public_reference AS reference, r.last_name, r.first_name, r.phone, e.name AS event_name, d.decided_at, n.status, n.last_error_code'
            .' FROM request_refusal_notice n JOIN request_permit_request_decision d ON d.id = n.decision_id JOIN request_permit_request_municipality rm ON rm.id = d.request_municipality_id'
            .' JOIN request_permit_request r ON r.id = rm.request_id JOIN event_event e ON e.id = r.event_id'
            ." WHERE n.municipality_id = :m AND n.status IN ('NO_RECIPIENT', 'ABANDONED') AND e.status <> 'CLOSED' ORDER BY d.decided_at DESC, n.id DESC LIMIT ".max(1, $limit),
            ['m' => $municipalityId->toBinary()],
        );

        return array_map(static fn (array $r): RefusalToCommunicate => new RefusalToCommunicate(
            Uuid::fromBinary((string) $r['notice_id'])->toRfc4122(),
            Uuid::fromBinary((string) $r['request_id'])->toRfc4122(),
            (string) $r['reference'],
            (string) $r['last_name'],
            (string) $r['first_name'],
            null === $r['phone'] ? null : (string) $r['phone'],
            (string) $r['event_name'],
            new \DateTimeImmutable((string) $r['decided_at'], new \DateTimeZone('UTC')),
            RefusalNoticeStatus::from((string) $r['status']),
            null === $r['last_error_code'] ? null : (string) $r['last_error_code'],
        ), $rows);
    }

    private function db(): Connection
    {
        return $this->entityManager->getConnection();
    }

    private static function utc(\DateTimeImmutable $date): string
    {
        return $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
