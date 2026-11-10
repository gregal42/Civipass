<?php

declare(strict_types=1);

namespace App\Permit\Infrastructure;

use App\Permit\Domain\Permit;
use App\Permit\Domain\PermitAuthorization;
use App\Permit\Domain\PermitRepository;
use App\Permit\Domain\PermitVehicle;
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

    public function refreshAuthorization(PermitAuthorization $authorization): void
    {
        $this->entityManager->refresh($authorization, LockMode::PESSIMISTIC_WRITE);
    }

    public function lockVehicles(Permit $permit): array
    {
        $ids = $this->entityManager->getConnection()->fetchFirstColumn('SELECT id FROM permit_vehicle WHERE permit_id = :id ORDER BY position, id FOR UPDATE', ['id' => $permit->id()->toBinary()]);
        $vehicles = [];
        foreach ($ids as $binary) {
            $id = Uuid::fromBinary($binary);
            $managed = $this->entityManager->getUnitOfWork()->tryGetById($id->toRfc4122(), PermitVehicle::class);
            $vehicle = false !== $managed ? $managed : null;
            if ($vehicle instanceof PermitVehicle) {
                $this->entityManager->refresh($vehicle, LockMode::PESSIMISTIC_WRITE);
            } else {
                $vehicle = $this->entityManager->find(PermitVehicle::class, $id, LockMode::PESSIMISTIC_WRITE);
            }
            if ($vehicle instanceof PermitVehicle) {
                $vehicles[] = $vehicle;
            }
        }

        return $vehicles;
    }

    public function lockActiveToken(Permit $permit): ?VerificationToken
    {
        $binary = $this->entityManager->getConnection()->fetchOne('SELECT id FROM permit_verification_token WHERE permit_id = :id AND active_permit_id IS NOT NULL FOR UPDATE', ['id' => $permit->id()->toBinary()]);
        if (false === $binary) {
            return null;
        }
        $id = Uuid::fromBinary($binary);
        $managed = $this->entityManager->getUnitOfWork()->tryGetById($id->toRfc4122(), VerificationToken::class);
        if ($managed instanceof VerificationToken) {
            $this->entityManager->refresh($managed, LockMode::PESSIMISTIC_WRITE);

            return $managed;
        }

        return $this->entityManager->find(VerificationToken::class, $id, LockMode::PESSIMISTIC_WRITE);
    }

    public function tokenExists(string $token): bool
    {
        return false !== $this->entityManager->getConnection()->fetchOne('SELECT 1 FROM permit_verification_token WHERE token = :t', ['t' => $token]);
    }

    public function flush(): void
    {
        $this->entityManager->flush();
    }

    public function closedPeriodCount(Uuid $authorizationId): int
    {
        return (int) $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM permit_authorization_period WHERE authorization_id = :id', ['id' => $authorizationId->toBinary()]);
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

    public function findVerificationToken(#[\SensitiveParameter] string $token): ?VerificationToken
    {
        $found = $this->entityManager->createQueryBuilder()
            ->select('t', 'p', 'e', 'r')
            ->from(VerificationToken::class, 't')
            ->join('t.permit', 'p')
            ->join('p.event', 'e')
            ->join('p.request', 'r')
            ->where('t.token = :token')
            ->setParameter('token', $token)
            ->getQuery()
            ->getOneOrNullResult();
        if (!$found instanceof VerificationToken) {
            return null;
        }
        $this->preloadForVerification($found->permit());

        return $found;
    }

    /** Charge en deux requêtes (au lieu d'une par lien) autorisations, communes, participations, zones puis plaques ; les entités arrivent initialisées dans l'unité de travail. */
    private function preloadForVerification(Permit $permit): void
    {
        $this->entityManager->createQueryBuilder()
            ->select('p', 'a', 'm', 'part', 'z')
            ->from(Permit::class, 'p')
            ->leftJoin('p.authorizations', 'a')
            ->leftJoin('a.municipality', 'm')
            ->leftJoin('a.participation', 'part')
            ->leftJoin('a.zones', 'z')
            ->where('p.id = :id')
            ->setParameter('id', $permit->id(), 'uuid')
            ->getQuery()
            ->getResult();
        $this->entityManager->createQueryBuilder()
            ->select('p', 'v')
            ->from(Permit::class, 'p')
            ->leftJoin('p.vehicles', 'v')
            ->where('p.id = :id')
            ->setParameter('id', $permit->id(), 'uuid')
            ->getQuery()
            ->getResult();
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
