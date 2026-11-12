<?php

declare(strict_types=1);

namespace App\Tests\Functional\Rights;

use App\Rights\Domain\RightsRequestKind;
use App\Shared\Domain\DomainRuleViolation;
use App\Shared\Domain\OperationNotAllowed;
use App\Tests\Support\RightsScenarioTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Uid\Uuid;

/**
 * Instruction des demandes de droits (LOT 05.6.6) : habilitations, isolation des communes, attestation préalable de l'identité, décisions motivées, délais et prorogation,
 * absence de toute donnée du demandeur, traçabilité.
 */
final class RightsRequestTest extends RightsScenarioTestCase
{
    public function testOnlyTheMunicipalAdministratorOfTheCommuneMayRegisterWithForgedIdentifiersRefusedUniformly(): void
    {
        foreach ([
            [$this->manager, $this->organizerId], [$this->instructor, $this->organizerId], [$this->partnerManager, $this->partnerId],
            [$this->partnerAdmin, $this->organizerId], [$this->outsider, $this->organizerId], [$this->superAdmin, $this->organizerId], [$this->admin, $this->partnerId],
            [$this->admin, Uuid::v7()->toRfc4122()], [$this->admin, 'pas-un-uuid'],
        ] as [$user, $commune]) {
            try {
                $this->svc()->register($this->managed($user), $commune, 'ACCESS', [$this->publicReference()]);
                self::fail('Enregistrement par un utilisateur non habilité.');
            } catch (OperationNotAllowed) {
                $this->addToAssertionCount(1);
            }
        }
        self::assertSame(0, $this->rows('rights_request'));
        self::assertSame(0, $this->rows('rights_request_dossier'));
    }

    public function testADossierIsDesignatedByReferenceAndBoundToTheCommuneOfTheAdministrator(): void
    {
        $organizerRequest = $this->register(RightsRequestKind::Access);
        $partnerRequest = $this->register(RightsRequestKind::Access, $this->partnerId, $this->partnerAdmin);

        $links = $this->em()->getConnection()->fetchAllAssociative('SELECT HEX(l.rights_request_id) r, HEX(rm.municipality_id) m FROM rights_request_dossier l JOIN request_permit_request_municipality rm ON rm.id = l.request_municipality_id');
        self::assertCount(2, $links);
        $byRequest = array_column($links, 'm', 'r');
        self::assertSame(strtoupper(str_replace('-', '', $this->organizerId)), $byRequest[strtoupper(str_replace('-', '', $organizerRequest))], 'le lien vise le dossier de SA commune');
        self::assertSame(strtoupper(str_replace('-', '', $this->partnerId)), $byRequest[strtoupper(str_replace('-', '', $partnerRequest))]);

        foreach ([[], ['DR-INCONNUE'], [$this->publicReference(), 'XX-0000'], ['']] as $refs) {
            try {
                $this->svc()->register($this->managed($this->outsider), $this->outsiderCommuneId, 'ACCESS', $refs);
                self::fail('Référence acceptée.');
            } catch (DomainRuleViolation) {
                $this->addToAssertionCount(1);
            }
        }
        try {
            $this->svc()->register($this->managed($this->admin), $this->organizerId, 'ACCESS', [$this->publicReference()]);
            $this->addToAssertionCount(1);
            $this->svc()->register($this->managed($this->admin), $this->organizerId, 'EFFACER_TOUT', [$this->publicReference()]);
            self::fail('Nature invalide.');
        } catch (DomainRuleViolation) {
            $this->addToAssertionCount(1);
        }
    }

    public function testTheRegisterNeverStoresAnythingAboutTheRequester(): void
    {
        $this->verified(RightsRequestKind::Access);
        $dump = json_encode($this->em()->getConnection()->fetchAllAssociative('SELECT * FROM rights_request'), \JSON_THROW_ON_ERROR | \JSON_INVALID_UTF8_SUBSTITUTE);
        foreach (self::SENTINELS as $sentinel) {
            self::assertStringNotContainsStringIgnoringCase($sentinel, $dump);
        }
        $audit = json_encode($this->em()->getConnection()->fetchAllAssociative('SELECT new_value FROM audit_log WHERE subject_type = \'RIGHTS_REQUEST\''), \JSON_THROW_ON_ERROR);
        foreach (self::SENTINELS as $sentinel) {
            self::assertStringNotContainsStringIgnoringCase($sentinel, $audit);
        }
        self::assertStringNotContainsString('@', $audit);
    }

    public function testNothingIsPossibleBeforeTheIdentityIsAttested(): void
    {
        $id = $this->register(RightsRequestKind::Access);
        $admin = $this->managed($this->admin);

        $this->expectException(DomainRuleViolation::class);
        try {
            $this->svc()->decide($admin, $this->organizerId, $id, 'GRANTED', null, false);
        } finally {
            self::assertSame('RECEIVED', $this->requestRow($id)['status']);
            self::assertSame(0, $this->rightsAuditCount('RIGHTS_REQUEST_DECIDED'));
        }
    }

    public function testTheAttestationRecordsWhoAndHowAndCannotBeRepeated(): void
    {
        $id = $this->verified(RightsRequestKind::Access);
        $row = $this->requestRow($id);

        self::assertSame('IDENTITY_VERIFIED', $row['status']);
        self::assertSame('DOCUMENT_CHECKED', $row['identity_method']);
        self::assertSame($this->admin->id()->toBinary(), $row['identity_verified_by_user_id']);
        self::assertSame(1, $this->rightsAuditCount('RIGHTS_IDENTITY_ATTESTED'));
        $this->expectException(DomainRuleViolation::class);
        $this->svc()->attestIdentity($this->managed($this->admin), $this->organizerId, $id, 'DOCUMENT_CHECKED');
    }

    public function testDecisionsNeedAReasonWhenRefusedAndRefuseThePartialOnAccessAndErasure(): void
    {
        $admin = $this->managed($this->admin);
        $id = $this->verified(RightsRequestKind::Access);
        foreach ([['REFUSED', null], ['REFUSED', 'INVENTE'], ['PARTIAL', null], ['MAYBE', null]] as [$decision, $reason]) {
            try {
                $this->svc()->decide($admin, $this->organizerId, $id, $decision, $reason, false);
                self::fail('Décision invalide acceptée.');
            } catch (DomainRuleViolation) {
                $this->addToAssertionCount(1);
            }
        }
        self::assertSame('IDENTITY_VERIFIED', $this->requestRow($id)['status']);

        $this->svc()->decide($admin, $this->organizerId, $id, 'REFUSED', 'OTHER_GROUNDS', false);
        $row = $this->requestRow($id);
        self::assertSame('DECIDED', $row['status']);
        self::assertSame('OTHER_GROUNDS', $row['refusal_reason']);
        self::assertNull($row['export_available_until'], 'un refus n\'ouvre aucun export');
        try {
            $this->svc()->decide($admin, $this->organizerId, $id, 'GRANTED', null, false);
            self::fail('Redécision acceptée.');
        } catch (DomainRuleViolation) {
            $this->addToAssertionCount(1);
        }
        $this->svc()->close($admin, $this->organizerId, $id);
        self::assertSame('CLOSED', $this->requestRow($id)['status']);
        self::assertSame(1, $this->rightsAuditCount('RIGHTS_REQUEST_CLOSED'));
    }

    public function testObjectionAndLimitationAreDecidedWithoutAnyAutomaticEffect(): void
    {
        $id = $this->verified(RightsRequestKind::ObjectionLimitation);
        $before = $this->snapshot();
        unset($before['audit_log']);

        $this->svc()->decide($this->managed($this->admin), $this->organizerId, $id, 'PARTIAL', null, false);

        $after = $this->snapshot();
        unset($after['audit_log']);
        self::assertSame($before, $after, 'aucun gel, aucune suppression, aucune modification de dossier');
        self::assertSame(0, $this->rows('archive_legal_hold'));
    }

    public function testTheDueDateExtensionOverdueAndTheReportFollowTheParameters(): void
    {
        $id = $this->register(RightsRequestKind::Access);
        $row = $this->requestRow($id);
        $received = new \DateTimeImmutable((string) $row['received_at'], new \DateTimeZone('UTC'));
        self::assertSame($received->modify('+30 days')->format('Y-m-d'), (new \DateTimeImmutable((string) $row['due_at']))->format('Y-m-d'));

        $this->sql('UPDATE rights_request SET due_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 2 DAY)');
        $detail = static::getContainer()->get(\App\Rights\Application\RightsRequestReader::class)->detail($this->managed($this->admin), $this->organizerId, $id);
        self::assertTrue($detail['overdue']);
        $command = new CommandTester((new \Symfony\Bundle\FrameworkBundle\Console\Application(static::$kernel))->find('app:rights:overdue-report'));
        self::assertSame(0, $command->execute([]));
        self::assertStringContainsString($row['public_reference'], $command->getDisplay());
        foreach (self::SENTINELS as $sentinel) {
            self::assertStringNotContainsStringIgnoringCase($sentinel, $command->getDisplay());
        }

        $this->svc()->extend($this->managed($this->admin), $this->organizerId, $id, 'COMPLEXITY');
        $extended = $this->requestRow($id);
        self::assertSame((new \DateTimeImmutable((string) $extended['due_at']))->modify('+60 days')->format('Y-m-d'), (new \DateTimeImmutable((string) $extended['extended_until']))->format('Y-m-d'));
        $detail = static::getContainer()->get(\App\Rights\Application\RightsRequestReader::class)->detail($this->managed($this->admin), $this->organizerId, $id);
        self::assertFalse($detail['overdue'], 'prorogée : plus en retard');
        $this->expectException(DomainRuleViolation::class);
        $this->svc()->extend($this->managed($this->admin), $this->organizerId, $id, 'COMPLEXITY');
    }

    public function testNoOtherCommuneEverReadsOrActsOnARequest(): void
    {
        $id = $this->verified(RightsRequestKind::Access);
        $reader = static::getContainer()->get(\App\Rights\Application\RightsRequestReader::class);
        foreach ([[$this->partnerAdmin, $this->partnerId], [$this->partnerAdmin, $this->organizerId], [$this->outsider, $this->outsiderCommuneId], [$this->manager, $this->organizerId], [$this->superAdmin, $this->organizerId]] as [$user, $commune]) {
            foreach ([
                fn () => $reader->detail($this->managed($user), $commune, $id),
                fn () => $this->svc()->decide($this->managed($user), $commune, $id, 'REFUSED', 'OTHER_GROUNDS', false),
                fn () => $this->svc()->close($this->managed($user), $commune, $id),
                fn () => $this->svc()->extend($this->managed($user), $commune, $id, 'COMPLEXITY'),
            ] as $call) {
                try {
                    $call();
                    self::fail('Accès transversal à une demande de droits.');
                } catch (OperationNotAllowed) {
                    $this->addToAssertionCount(1);
                }
            }
        }
        self::assertSame('IDENTITY_VERIFIED', $this->requestRow($id)['status']);
        self::assertSame([], $reader->list($this->managed($this->partnerAdmin), $this->partnerId), 'la liste de l\'autre commune ne contient rien');
    }
}
