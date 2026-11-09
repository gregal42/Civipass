<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Event\Application\Support\EventLocker;
use App\Event\Application\Support\EventTransactions;
use App\Municipality\Application\MunicipalityContext;
use App\Municipality\Domain\PhoneNumberNormalizer;
use App\Request\Application\RequestIdentityCorrectionService;
use App\Request\Domain\PermitRequestRepository;
use App\Shared\Application\AuditTrail;
use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;

/** Assemble le service de correction d'identité avec une horloge et un audit choisis (le conteneur retire les services inutilisés) ; partagé avec le processus de concurrence. */
final class IdentityCorrectionFactory
{
    public static function create(ContainerInterface $c, ClockInterface $clock, ?AuditTrail $audit = null): RequestIdentityCorrectionService
    {
        return new RequestIdentityCorrectionService(
            $c->get(EventTransactions::class),
            $c->get(EventLocker::class),
            $clock,
            $c->get(MunicipalityContext::class),
            $c->get(PermitRequestRepository::class),
            $c->get(PhoneNumberNormalizer::class),
            $audit ?? $c->get(AuditTrail::class),
        );
    }
}
