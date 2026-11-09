<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Permit\Application\NotificationIntentRecorder;
use App\Permit\Application\PermitIssuer;
use App\Permit\Domain\PermitReferenceGenerator;
use App\Permit\Domain\PermitRepository;
use App\Shared\Application\AuditTrail;
use Psr\Container\ContainerInterface;

/**
 * Assemble le VRAI créateur de laissez-passer avec un audit choisi (le conteneur retire les services inutilisés ; les doublures d'audit des
 * tests de concurrence et d'échec doivent aussi atteindre le module Permit). Partagé avec les processus de test.
 */
final class PermitIssuerFactory
{
    public static function create(ContainerInterface $c, ?AuditTrail $audit = null): PermitIssuer
    {
        return new PermitIssuer($c->get(PermitRepository::class), $c->get(PermitReferenceGenerator::class), $audit ?? $c->get(AuditTrail::class), $c->get(NotificationIntentRecorder::class));
    }
}
