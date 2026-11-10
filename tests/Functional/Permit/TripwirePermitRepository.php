<?php

declare(strict_types=1);

namespace App\Tests\Functional\Permit;

use App\Permit\Domain\Permit;
use App\Permit\Domain\PermitRepository;
use App\Permit\Domain\VerificationToken;
use App\Request\Domain\PermitRequest;
use App\Request\Domain\PermitRequestDecision;
use Symfony\Component\Uid\Uuid;

/**
 * Dépôt piégé des tests de la vérification publique : toute lecture échoue (avec l'exception donnée, ou en faisant échouer le test), ce qui
 * prouve qu'une route désactivée n'atteint jamais la base et que le contrôleur ne rattrape pas les pannes.
 */
final class TripwirePermitRepository implements PermitRepository
{
    public function __construct(private readonly ?\Throwable $failure = null)
    {
    }

    private function touched(): never
    {
        throw $this->failure ?? new \LogicException('Le dépôt des laissez-passer ne doit pas être lu.');
    }

    public function add(Permit $permit): void
    {
        $this->touched();
    }

    public function findByRequest(Uuid $requestId, bool $locking = false): ?Permit
    {
        $this->touched();
    }

    public function lockVehicles(Permit $permit): array
    {
        $this->touched();
    }

    public function lockActiveToken(Permit $permit): ?VerificationToken
    {
        $this->touched();
    }

    public function tokenExists(string $token): bool
    {
        $this->touched();
    }

    public function flush(): void
    {
        $this->touched();
    }

    public function find(Uuid $permitId): ?Permit
    {
        $this->touched();
    }

    public function lock(Uuid $permitId): void
    {
        $this->touched();
    }

    public function refreshAuthorization(\App\Permit\Domain\PermitAuthorization $authorization): void
    {
        $this->touched();
    }

    public function closedPeriodCount(Uuid $authorizationId): int
    {
        $this->touched();
    }

    public function findByVerificationToken(#[\SensitiveParameter] string $token): ?Permit
    {
        $this->touched();
    }

    public function findVerificationToken(#[\SensitiveParameter] string $token): ?VerificationToken
    {
        $this->touched();
    }

    public function isPersisted(PermitRequest $request): bool
    {
        $this->touched();
    }

    public function lockRequest(PermitRequest $request): void
    {
        $this->touched();
    }

    public function findDecision(Uuid $decisionId): ?PermitRequestDecision
    {
        $this->touched();
    }

    public function findApprovalsWithoutAuthorization(): array
    {
        $this->touched();
    }
}
