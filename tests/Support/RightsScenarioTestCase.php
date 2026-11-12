<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Rights\Application\RightsRequestService;
use App\Rights\Domain\RightsRequestKind;

/**
 * Scénario partagé des tests de droits des personnes (LOT 05.6.6) : le scénario de rétention (une demande, deux dossiers communaux approuvés, deux communes, administrateurs,
 * organisateurs, instructeurs, CWS), avec l'exécution des effacements activée À LA DEMANDE d'un test seulement.
 */
abstract class RightsScenarioTestCase extends RetentionScenarioTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        unset($_SERVER['RIGHTS_ERASURE_EXECUTION_ENABLED']);
    }

    protected function tearDown(): void
    {
        unset($_SERVER['RIGHTS_ERASURE_EXECUTION_ENABLED']);
        parent::tearDown();
    }

    protected function svc(): RightsRequestService
    {
        return static::getContainer()->get(RightsRequestService::class);
    }

    protected function publicReference(): string
    {
        return (string) $this->em()->getConnection()->fetchOne('SELECT public_reference FROM request_permit_request');
    }

    /** Enregistre une demande pour l'administrateur de la commune organisatrice (ou `$as`) ; retourne son identifiant. */
    protected function register(RightsRequestKind $kind, ?string $commune = null, ?\App\Identity\Domain\User $as = null): string
    {
        $request = $this->svc()->register($this->managed($as ?? $this->admin), $commune ?? $this->organizerId, $kind->value, [$this->publicReference()]);
        $this->em()->clear();

        return $request->id()->toRfc4122();
    }

    protected function verified(RightsRequestKind $kind, ?string $commune = null, ?\App\Identity\Domain\User $as = null): string
    {
        $id = $this->register($kind, $commune, $as);
        $this->svc()->attestIdentity($this->managed($as ?? $this->admin), $commune ?? $this->organizerId, $id, 'DOCUMENT_CHECKED');
        $this->em()->clear();

        return $id;
    }

    /** @return array<string, mixed> */
    protected function requestRow(string $id): array
    {
        return $this->em()->getConnection()->fetchAssociative('SELECT * FROM rights_request WHERE id = UNHEX(:i)', ['i' => str_replace('-', '', $id)]) ?: [];
    }

    protected function rightsAuditCount(string $action): int
    {
        return $this->rows('audit_log', 'action = \''.$action.'\'');
    }
}
