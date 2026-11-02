<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure;

use App\Shared\Application\AuditEvent;
use App\Shared\Application\AuditTrail;
use App\Shared\Domain\AuditLog;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(AuditTrail::class)]
final readonly class DoctrineAuditTrail implements AuditTrail
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function record(AuditEvent $event): void
    {
        $this->entityManager->persist(new AuditLog(
            $event->occurredAt,
            $event->action,
            $event->subjectType,
            $event->subjectId,
            $event->municipalityId,
            $event->actor->id,
            $event->actor->origin,
            $event->previousValue,
            $event->newValue,
        ));
    }
}
