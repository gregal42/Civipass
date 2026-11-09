<?php

declare(strict_types=1);

namespace App\Permit\Infrastructure;

use App\Permit\Domain\Permit;
use App\Permit\Domain\PermitRepository;
use App\Permit\Domain\VerificationToken;
use App\Request\Domain\PermitRequest;
use App\Request\Domain\PermitRequestDecision;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\ORM\UnitOfWork;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Uid\Uuid;

#[AsAlias(PermitRepository::class)]
final readonly class DoctrinePermitRepository implements PermitRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function add(Permit $permit): void
    {
        $this->entityManager->persist($permit);
    }

    public function find(Uuid $permitId): ?Permit
    {
        return $this->entityManager->find(Permit::class, $permitId);
    }

    public function lock(Uuid $permitId): void
    {
        $this->entityManager->getConnection()->executeQuery('SELECT id FROM permit_permit WHERE id = :id FOR UPDATE', ['id' => $permitId->toBinary()]);
    }

    public function findByRequest(Uuid $requestId, bool $locking = false): ?Permit
    {
        // Créé plus tôt dans la même transaction mais pas encore écrit : la requête ne le verrait pas.
        foreach ($this->entityManager->getUnitOfWork()->getIdentityMap()[Permit::class] ?? [] as $managed) {
            if ($managed instanceof Permit && $managed->request()->id()->equals($requestId)) {
                return $managed;
            }
        }
        $query = $this->entityManager->createQueryBuilder()
            ->select('p')
            ->from(Permit::class, 'p')
            ->where('IDENTITY(p.request) = :request')
            ->setParameter('request', $requestId, 'uuid')
            ->getQuery();
        if ($locking) {
            $query->setLockMode(LockMode::PESSIMISTIC_READ);
            $query->setHint(Query::HINT_REFRESH, true);
        }
        $permit = $query->getOneOrNullResult();

        return $permit instanceof Permit ? $permit : null;
    }

    public function findByVerificationToken(string $token): ?Permit
    {
        $found = $this->entityManager->createQueryBuilder()
            ->select('t')
            ->from(VerificationToken::class, 't')
            ->where('t.token = :token')
            ->setParameter('token', $token)
            ->getQuery()
            ->getOneOrNullResult();

        return $found instanceof VerificationToken ? $found->permit() : null;
    }

    public function isPersisted(PermitRequest $request): bool
    {
        return UnitOfWork::STATE_NEW !== $this->entityManager->getUnitOfWork()->getEntityState($request, UnitOfWork::STATE_NEW);
    }

    public function lockRequest(PermitRequest $request): void
    {
        if (!$this->isPersisted($request)) {
            return;
        }
        // Verrou seul : l'entité n'est pas relue (la ligne du dossier n'est lue que pour être verrouillée).
        $this->entityManager->getConnection()->fetchOne('SELECT id FROM request_permit_request WHERE id = :id FOR UPDATE', ['id' => $request->id()->toBinary()]);
    }

    public function findDecision(Uuid $decisionId): ?PermitRequestDecision
    {
        return $this->entityManager->find(PermitRequestDecision::class, $decisionId);
    }

    public function findApprovalsWithoutAuthorization(): array
    {
        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
            'SELECT d.id AS decision_id, p.event_id, m.participation_id, m.request_id FROM request_permit_request_decision d'
            .' JOIN request_permit_request_municipality m ON m.id = d.request_municipality_id'
            .' JOIN event_participation p ON p.id = m.participation_id'
            .' LEFT JOIN permit_authorization a ON a.decision_id = d.id'
            .' WHERE d.outcome = \'APPROVED\' AND a.id IS NULL ORDER BY d.decided_at, d.id',
        );

        return array_map(static fn (array $r): array => [
            'decisionId' => Uuid::fromBinary($r['decision_id']),
            'eventId' => Uuid::fromBinary($r['event_id']),
            'participationId' => Uuid::fromBinary($r['participation_id']),
            'requestId' => Uuid::fromBinary($r['request_id']),
        ], $rows);
    }
}
