<?php

declare(strict_types=1);

namespace App\Archive\Application;

use App\Archive\Domain\StatisticsCoordinationGrantRepository;
use App\Event\Application\StatisticsCoordinationLookup;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Uid\Uuid;

#[AsAlias(StatisticsCoordinationLookup::class)]
final readonly class ActiveStatisticsCoordination implements StatisticsCoordinationLookup
{
    public function __construct(private StatisticsCoordinationGrantRepository $grants)
    {
    }

    public function hasActiveGrant(Uuid $eventId, Uuid $userId): bool
    {
        return null !== $this->grants->findActive($eventId, $userId);
    }
}
