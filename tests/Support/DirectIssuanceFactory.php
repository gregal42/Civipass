<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Event\Application\Support\EventLocker;
use App\Event\Application\Support\EventTransactions;
use App\Event\Domain\EventParticipationRepository;
use App\Event\Domain\EventZoneRepository;
use App\Event\Domain\EventZoneValidityRepository;
use App\Municipality\Application\MunicipalityContext;
use App\Municipality\Domain\PhoneNumberNormalizer;
use App\Request\Application\ApprovedPartListener;
use App\Request\Application\MunicipalDecisionApplier;
use App\Request\Application\ProposableZones;
use App\Request\Application\RequestSubmissionService;
use App\Request\Application\UploadedDocumentInspector;
use App\Request\Domain\PermitRequestRepository;
use App\Request\Domain\RequestReferenceGenerator;
use App\Request\Domain\RequestReferentialRepository;
use App\Shared\Application\AuditTrail;
use App\Shared\Application\FileStorageInterface;
use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;

/**
 * Assemble le service de dépôt avec une horloge et un audit choisis, pour tester la délivrance directe de façon déterministe (le conteneur
 * retire les services non utilisés et impose l'horloge réelle). Les VRAIS dépôts, verrous et transactions sont utilisés ; partagé par les
 * tests d'intégration et par le processus des tests de concurrence.
 */
final class DirectIssuanceFactory
{
    public static function create(ContainerInterface $c, ClockInterface $clock, ?AuditTrail $audit = null, ?ApprovedPartListener $listener = null): RequestSubmissionService
    {
        $audit ??= $c->get(AuditTrail::class);

        return new RequestSubmissionService(
            $c->get(EventTransactions::class),
            $c->get(EventLocker::class),
            $clock,
            $c->get(MunicipalityContext::class),
            $c->get(EventParticipationRepository::class),
            $c->get(EventZoneRepository::class),
            $c->get(RequestReferentialRepository::class),
            $c->get(PermitRequestRepository::class),
            $c->get(RequestReferenceGenerator::class),
            $c->get(PhoneNumberNormalizer::class),
            $audit,
            $c->get(FileStorageInterface::class),
            $c->get(UploadedDocumentInspector::class),
            $c->get(ProposableZones::class),
            new MunicipalDecisionApplier($audit, $c->get(EventZoneValidityRepository::class), $listener ?? PermitIssuerFactory::create($c, $audit)),
            $c->get(\App\Event\Application\EventOperationalGuard::class),
        );
    }
}
