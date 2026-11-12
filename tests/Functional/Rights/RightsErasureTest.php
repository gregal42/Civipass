<?php

declare(strict_types=1);

namespace App\Tests\Functional\Rights;

use App\Archive\Application\LegalHoldService;
use App\Identity\Domain\User;
use App\Retention\Application\DossierPurgeService;
use App\Retention\Application\PurgeOutcome;
use App\Rights\Application\ErasureExecutionDisabled;
use App\Rights\Application\RightsErasureService;
use App\Rights\Domain\RightsRequestKind;
use App\Shared\Domain\DomainRuleViolation;
use App\Shared\Domain\OperationNotAllowed;
use App\Tests\Support\RightsScenarioTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Effacement anticipé (LOT 05.6.6, arbitrage D1) : parcours d'instruction et de décision complet, EXÉCUTION désactivée par défaut ; une fois activée, jamais de contournement d'un
 * gel, de la clôture, d'une autre demande ouverte ni de la règle de partage (purges A puis B et B puis A) ; traçabilité conservée sans donnée personnelle.
 */
final class RightsErasureTest extends RightsScenarioTestCase
{
    private function enableExecution(): void
    {
        $_SERVER['RIGHTS_ERASURE_EXECUTION_ENABLED'] = '1'; // avant la première instanciation des paramètres de droits
    }

    private function erasures(): RightsErasureService
    {
        return static::getContainer()->get(RightsErasureService::class);
    }

    /** Décision d'effacement accordée avec la vérification explicite des obligations de conservation. */
    private function grantErasure(?string $commune = null, ?User $as = null): string
    {
        $id = $this->verified(RightsRequestKind::Erasure, $commune, $as);
        $this->svc()->decide($this->managed($as ?? $this->admin), $commune ?? $this->organizerId, $id, 'GRANTED', null, true);
        $this->em()->clear();

        return $id;
    }

    // ------------------------------------------------------------------ décision

    public function testAnErasureCannotBeGrantedWithoutTheExplicitConservationCheck(): void
    {
        $this->close();
        $id = $this->verified(RightsRequestKind::Erasure);

        $this->expectException(DomainRuleViolation::class);
        $this->expectExceptionMessage('obligations de conservation');
        try {
            $this->svc()->decide($this->managed($this->admin), $this->organizerId, $id, 'GRANTED', null, false);
        } finally {
            self::assertSame('IDENTITY_VERIFIED', $this->requestRow($id)['status']);
        }
    }

    public function testAnErasureCannotBeGrantedOnAnActiveEventOrUnderAnActiveHold(): void
    {
        $id = $this->verified(RightsRequestKind::Erasure);
        try {
            $this->svc()->decide($this->managed($this->admin), $this->organizerId, $id, 'GRANTED', null, true);
            self::fail('Effacement accordé sur un événement actif.');
        } catch (DomainRuleViolation $e) {
            self::assertStringContainsString('Événement non clos', $e->getMessage());
        }
        $this->close();
        static::getContainer()->get(LegalHoldService::class)->place($this->managed($this->admin), $this->organizerId, $this->dossierOf($this->organizerId), 'LITIGE', null);
        $this->em()->clear();
        try {
            $this->svc()->decide($this->managed($this->admin), $this->organizerId, $id, 'GRANTED', null, true);
            self::fail('Effacement accordé sous gel.');
        } catch (DomainRuleViolation $e) {
            self::assertStringContainsString('Gel de conservation', $e->getMessage());
        }
        self::assertSame('IDENTITY_VERIFIED', $this->requestRow($id)['status']);

        $this->svc()->decide($this->managed($this->admin), $this->organizerId, $id, 'REFUSED', 'LEGAL_HOLD', false);
        self::assertSame('LEGAL_HOLD', $this->requestRow($id)['refusal_reason'], 'le refus motivé reste possible');
        self::assertSame(2, $this->rows('request_permit_request_municipality'), 'rien n\'est supprimé');
    }

    // ------------------------------------------------------------------ exécution désactivée par défaut

    public function testTheExecutionIsDisabledByDefaultAndNothingIsDeleted(): void
    {
        $this->close();
        $id = $this->grantErasure();
        $before = $this->snapshot();
        unset($before['audit_log']);

        try {
            $this->erasures()->execute($this->managed($this->admin), $this->organizerId, $id);
            self::fail('Exécution malgré l\'interrupteur désactivé.');
        } catch (ErasureExecutionDisabled $e) {
            self::assertStringContainsString('désactivée', $e->getMessage());
        }

        $after = $this->snapshot();
        unset($after['audit_log']);
        self::assertSame($before, $after, 'aucune donnée supprimée');
        self::assertNull($this->requestRow($id)['executed_at']);
        self::assertSame(0, $this->rightsAuditCount('RIGHTS_ERASURE_EXECUTED'));
        self::assertSame(2, $this->rows('request_permit_request_municipality'));
    }

    public function testExecutionAlsoNeedsThePurgeSwitchOfTheRetentionLot(): void
    {
        $this->enableExecution();
        $_SERVER['RETENTION_PURGE_ENABLED'] = '0';
        $this->close();
        $id = $this->grantErasure();

        $this->expectException(ErasureExecutionDisabled::class);
        $this->erasures()->execute($this->managed($this->admin), $this->organizerId, $id);
    }

    public function testOnlyTheAdministratorOfTheCommuneMayExecute(): void
    {
        $this->enableExecution();
        $this->close();
        $id = $this->grantErasure();
        foreach ([[$this->manager, $this->organizerId], [$this->instructor, $this->organizerId], [$this->partnerAdmin, $this->partnerId], [$this->partnerAdmin, $this->organizerId], [$this->superAdmin, $this->organizerId], [$this->outsider, $this->outsiderCommuneId]] as [$user, $commune]) {
            try {
                $this->erasures()->execute($this->managed($user), $commune, $id);
                self::fail('Exécution par un utilisateur non habilité.');
            } catch (OperationNotAllowed) {
                $this->addToAssertionCount(1);
            }
        }
        self::assertSame(2, $this->rows('request_permit_request_municipality'));
    }

    // ------------------------------------------------------------------ exécution activée : jamais de contournement

    /** @return array<string, array{bool}> */
    public static function orders(): array
    {
        return ['A puis B' => [true], 'B puis A' => [false]];
    }

    #[DataProvider('orders')]
    public function testErasuresKeepTheSharedDataUntilTheLastDossierInBothOrders(bool $organizerFirst): void
    {
        $this->enableExecution();
        $this->close(); // clôturé, mais l'échéance de conservation n'est PAS atteinte : seul l'effacement accordé peut purger
        $a = $this->dossierOf($this->organizerId);
        self::assertSame(DossierPurgeService::RETENTION_NOT_REACHED, $this->purge($a)->reason, 'la purge ordinaire reste refusée avant l\'échéance');
        [$firstCommune, $firstAdmin, $secondCommune, $secondAdmin] = $organizerFirst
            ? [$this->organizerId, $this->admin, $this->partnerId, $this->partnerAdmin]
            : [$this->partnerId, $this->partnerAdmin, $this->organizerId, $this->admin];

        $first = $this->grantErasure($firstCommune, $firstAdmin);
        $result = $this->erasures()->execute($this->managed($firstAdmin), $firstCommune, $first);
        $this->em()->clear();

        self::assertSame(['purged' => 1, 'remaining' => 0], $result);
        self::assertSame(1, $this->rows('request_permit_request_municipality'), 'le dossier de l\'autre commune subsiste');
        self::assertSame(1, $this->rows('request_permit_request'), 'donnée partagée conservée : l\'autre commune en a besoin');
        self::assertSame(1, $this->rows('permit_permit'));
        self::assertGreaterThan(0, $this->rows('request_permit_request_attachment'));
        self::assertSame(1, $this->rows('request_permit_request_decision'));
        self::assertSame(0, $this->rows('rights_request_dossier'), 'le lien est supprimé avec le dossier');
        $row = $this->requestRow($first);
        self::assertNotNull($row['executed_at'], 'la demande subsiste, exécutée, sans donnée personnelle');
        self::assertSame(1, $this->rows('retention_purge_record', 'kind = \'DOSSIER\' AND batch_id = UNHEX(\''.str_replace('-', '', $first).'\')'), 'le registre de purge porte la demande');
        self::assertSame(1, $this->rightsAuditCount('RIGHTS_ERASURE_EXECUTED'));
        self::assertTrue(static::getContainer()->get(\App\Retention\Application\RegisterReconciliation::class)->check(false)->consistent(), 'registre hors base à jour');

        $second = $this->grantErasure($secondCommune, $secondAdmin);
        $this->erasures()->execute($this->managed($secondAdmin), $secondCommune, $second);
        $this->em()->clear();
        foreach (['request_permit_request', 'request_permit_request_municipality', 'permit_permit', 'request_permit_request_attachment', 'request_permit_request_decision', 'notification_outbox'] as $table) {
            self::assertSame(0, $this->rows($table), $table.' supprimé avec le dernier dossier');
        }
        self::assertSame(1, $this->rows('retention_purge_record', 'kind = \'SHARED\''));
        self::assertSame(2, $this->rows('rights_request'), 'les deux demandes subsistent');
        $dump = json_encode($this->em()->getConnection()->fetchAllAssociative('SELECT * FROM rights_request'), \JSON_THROW_ON_ERROR | \JSON_INVALID_UTF8_SUBSTITUTE);
        foreach (self::SENTINELS as $sentinel) {
            self::assertStringNotContainsStringIgnoringCase($sentinel, $dump);
        }
    }

    public function testAHoldPlacedAfterTheDecisionStopsTheExecutionAndNothingIsDeleted(): void
    {
        $this->enableExecution();
        $this->close();
        $id = $this->grantErasure();
        static::getContainer()->get(LegalHoldService::class)->place($this->managed($this->admin), $this->organizerId, $this->dossierOf($this->organizerId), 'LITIGE', null);
        $this->em()->clear();

        try {
            $this->erasures()->execute($this->managed($this->admin), $this->organizerId, $id);
            self::fail('Effacement exécuté sous gel.');
        } catch (DomainRuleViolation $e) {
            self::assertStringContainsString('Gel de conservation', $e->getMessage());
        }
        self::assertSame(2, $this->rows('request_permit_request_municipality'));
        self::assertNull($this->requestRow($id)['executed_at']);
        self::assertSame('DECIDED', $this->requestRow($id)['status']);
    }

    public function testAnotherOpenRightsRequestOnTheSameDossierBlocksTheErasureUntilItIsClosed(): void
    {
        $this->enableExecution();
        $this->close();
        $access = $this->verified(RightsRequestKind::Access);
        $id = $this->grantErasure();

        try {
            $this->erasures()->execute($this->managed($this->admin), $this->organizerId, $id);
            self::fail('Effacement d\'un dossier que la personne demande à consulter.');
        } catch (DomainRuleViolation $e) {
            self::assertStringContainsString('autre demande de droits', $e->getMessage());
        }
        self::assertSame(2, $this->rows('request_permit_request_municipality'));

        $this->svc()->decide($this->managed($this->admin), $this->organizerId, $access, 'REFUSED', 'OTHER_GROUNDS', false);
        $this->svc()->close($this->managed($this->admin), $this->organizerId, $access);
        $this->em()->clear();
        self::assertSame(['purged' => 1, 'remaining' => 0], $this->erasures()->execute($this->managed($this->admin), $this->organizerId, $id));
    }

    public function testExecutionIsIdempotentAndNeverFiresOnAnActiveEvent(): void
    {
        $this->enableExecution();
        $this->close();
        $id = $this->grantErasure();
        $this->erasures()->execute($this->managed($this->admin), $this->organizerId, $id);
        $this->em()->clear();
        $second = $this->erasures()->execute($this->managed($this->admin), $this->organizerId, $id);

        self::assertSame(['purged' => 0, 'remaining' => 0], $second);
        self::assertSame(1, $this->rightsAuditCount('RIGHTS_ERASURE_EXECUTED'));
        self::assertSame(1, $this->rows('retention_purge_record', 'kind = \'DOSSIER\''));
    }

    public function testTheEarlyPurgeNeverBypassesTheClosureOrTheOrdinaryRetentionOnItsOwn(): void
    {
        $a = $this->dossierOf($this->organizerId);
        $service = static::getContainer()->get(DossierPurgeService::class);

        $outcome = $service->purge($a, true, $this->managed($this->admin), null, early: true);
        self::assertSame(PurgeOutcome::NOT_ELIGIBLE, $outcome->status);
        self::assertSame(DossierPurgeService::NOT_CLOSED, $outcome->reason, 'événement actif : jamais');
        self::assertSame(2, $this->rows('request_permit_request_municipality'));

        $this->close();
        static::getContainer()->get(LegalHoldService::class)->place($this->managed($this->admin), $this->organizerId, $a, 'LITIGE', null);
        $this->em()->clear();
        $outcome = $service->purge($a, true, $this->managed($this->admin), null, early: true);
        self::assertSame(DossierPurgeService::LEGAL_HOLD, $outcome->reason, 'gel actif : jamais');
        self::assertSame(2, $this->rows('request_permit_request_municipality'));
    }

    // ------------------------------------------------------------------ purge ordinaire et demandes en cours

    public function testTheOrdinaryPurgeWaitsForAnOpenRequestThenDeletesTheLinksAndKeepsTheRegisterRow(): void
    {
        $this->dueForPurge();
        $a = $this->dossierOf($this->organizerId);
        $id = $this->verified(RightsRequestKind::Access);

        $blocked = $this->purge($a);
        self::assertSame(PurgeOutcome::NOT_ELIGIBLE, $blocked->status);
        self::assertSame(DossierPurgeService::RIGHTS_REQUEST_OPEN, $blocked->reason);
        self::assertSame(2, $this->rows('request_permit_request_municipality'));

        $this->svc()->decide($this->managed($this->admin), $this->organizerId, $id, 'REFUSED', 'OTHER_GROUNDS', false);
        $this->svc()->close($this->managed($this->admin), $this->organizerId, $id);
        $this->em()->clear();
        self::assertSame(PurgeOutcome::PURGED, $this->purge($a)->status);
        self::assertSame(0, $this->rows('rights_request_dossier'), 'lien supprimé avec le dossier');
        self::assertSame(1, $this->rows('rights_request'), 'la trace de la demande subsiste');
        self::assertSame(1, $this->requestRow($id)['dossier_count']);
    }
}
