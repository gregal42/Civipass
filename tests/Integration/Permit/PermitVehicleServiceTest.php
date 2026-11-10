<?php

declare(strict_types=1);

namespace App\Tests\Integration\Permit;

use App\Event\Application\Support\EventLocker;
use App\Event\Application\Support\EventTransactions;
use App\Identity\Domain\User;
use App\Municipality\Domain\Municipality;
use App\Permit\Application\NotificationIntentRecorder;
use App\Permit\Application\PermitAccessDenied;
use App\Permit\Application\PermitAccessUnavailable;
use App\Permit\Application\PermitRevisionGuard;
use App\Permit\Application\PermitRightsOverviewReader;
use App\Permit\Application\PermitVehicleService;
use App\Permit\Application\Result\VehicleChangeRequest;
use App\Permit\Domain\Permit;
use App\Permit\Domain\PermitRepository;
use App\Permit\Domain\PermitRevisionRepository;
use App\Request\Application\Command\SubmitRequestCommand;
use App\Request\Application\ProposableZones;
use App\Request\Application\RequestSubmissionService;
use App\Request\Domain\ApprovalDuration;
use App\Request\Domain\DurationMode;
use App\Request\Domain\TravelMode;
use App\Shared\Application\AuditTrail;
use App\Shared\Domain\DomainRuleViolation;
use App\Shared\Domain\OperationNotAllowed;
use App\Tests\Support\FailingAuditTrail;
use App\Tests\Support\MunicipalDecisionTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Ajout, retrait, réactivation et remplacement des plaques d'un laissez-passer (LOT 05.5.4) avec les VRAIS services et MySQL : plaques communes à toutes les communes,
 * confirmation de la portée globale, opérations groupées atomiques validées sur l'état final, deux révisions liées pour un remplacement, une intention de notification par
 * opération, suspension de l'événement, identifiants forgés, participation non active, autorisation révoquée, mode pied/vélo, QR et demande citoyenne inchangés, ligne
 * neutre pour les autres communes, aucune plaque dans l'audit ni dans les états de révision. Horloge figée au 12 juin 2026 09:00 UTC ; décision de 72 h.
 */
#[Group('requires-request-schema')]
final class PermitVehicleServiceTest extends MunicipalDecisionTestCase
{
    private const COMMENT = 'COMMENTAIRE-INTERNE-SENTINELLE';
    private const PLATE = 'GH-482-KT';

    private function managed(User $user): User
    {
        return $this->em->find(User::class, $user->id()) ?? $user;
    }

    private function vehicles(?AuditTrail $audit = null): PermitVehicleService
    {
        $c = self::getContainer();

        return new PermitVehicleService(
            $c->get(EventTransactions::class),
            $c->get(EventLocker::class),
            $c->get(PermitRevisionGuard::class),
            $c->get(PermitRevisionRepository::class),
            $c->get(PermitRepository::class),
            $this->clock,
            $audit ?? $c->get(AuditTrail::class),
            $c->get(NotificationIntentRecorder::class),
        );
    }

    /** @param list<string> $plates */
    private function approvedByBoth(TravelMode $mode = TravelMode::Vehicle, array $plates = [self::PLATE]): string
    {
        $id = $this->service(RequestSubmissionService::class)->submitPublic(new SubmitRequestCommand(
            $this->event->id()->toRfc4122(),
            'Martin',
            'Camille',
            'SENTINEL-ADRESSE',
            'camille@exemple.fr',
            '0611223344',
            'private_person',
            'home',
            null,
            $mode,
            TravelMode::Vehicle === $mode ? $plates : [],
            [$this->organizerZone->id()->toRfc4122(), $this->partnerZone->id()->toRfc4122()],
            true,
        ), $this->key())->requestId;
        foreach ([[$this->instructor, $this->organizer], [$this->partnerInstructor, $this->partner]] as [$agent, $commune]) {
            $this->instruction()->approve($this->managed($agent), $commune->id()->toRfc4122(), $id, ApprovalDuration::fixed(DurationMode::H72));
        }

        return $id;
    }

    /**
     * @param list<string>                          $adds
     * @param list<string>                          $removes      plaques (saisie) à retirer
     * @param list<string>                          $reinstates   plaques (saisie) à réactiver
     * @param list<array{from: string, to: string}> $replacements `from` : plaque (saisie) remplacée
     */
    private function change(string $requestId, array $adds = [], array $removes = [], array $reinstates = [], array $replacements = [], ?string $addReason = null, ?string $removeReason = null, ?string $replaceReason = null, ?string $reinstateReason = null, ?string $comment = null, bool $confirm = true, ?User $agent = null, ?Municipality $commune = null, ?PermitVehicleService $service = null): \App\Permit\Application\Result\VehicleChangeResult
    {
        return ($service ?? $this->vehicles())->change(
            $this->managed($agent ?? $this->instructor),
            ($commune ?? $this->organizer)->id()->toRfc4122(),
            $requestId,
            new VehicleChangeRequest(
                $adds,
                array_map($this->idOf(...), $removes),
                array_map($this->idOf(...), $reinstates),
                array_map(fn (array $r): array => ['from' => $this->idOf($r['from']), 'to' => $r['to']], $replacements),
                $addReason,
                $removeReason,
                $replaceReason,
                $reinstateReason,
                $comment,
                $confirm,
            ),
        );
    }

    /** Identifiant de la ligne de plaque (actives ou retirées) pour une plaque saisie. */
    private function idOf(string $plate): string
    {
        $normalized = strtoupper((string) preg_replace('/[\s\-]+/', '', $plate));
        $id = $this->em->getConnection()->fetchOne('SELECT id FROM permit_vehicle WHERE registration = :r', ['r' => $normalized]);
        self::assertNotFalse($id, 'plaque inconnue du test : '.$plate);

        return \Symfony\Component\Uid\Uuid::fromBinary($id)->toRfc4122();
    }

    /** @return list<array{registration: string, removed: bool, position: int}> */
    private function plates(): array
    {
        $this->em->clear();

        return array_map(static fn (array $r): array => ['registration' => $r['registration'], 'removed' => null !== $r['removed_at'], 'position' => (int) $r['position']], $this->em->getConnection()->fetchAllAssociative('SELECT registration, removed_at, position FROM permit_vehicle ORDER BY position'));
    }

    /** @return list<string> */
    private function active(): array
    {
        return array_values(array_map(static fn (array $r): string => $r['registration'], array_filter($this->plates(), static fn (array $r): bool => !$r['removed'])));
    }

    private function token(): string
    {
        $this->em->clear();

        return ($this->em->getRepository(Permit::class)->findOneBy([]) ?? self::fail('Laissez-passer absent.'))->activeToken()->value();
    }

    /** @return array{revisions: int, vehicleLines: int, audits: int, outbox: int, plates: int} */
    private function counts(): array
    {
        $c = $this->em->getConnection();

        return [
            'revisions' => $this->rows('permit_revision'),
            'vehicleLines' => $this->rows('permit_revision_vehicle'),
            'audits' => (int) $c->fetchOne("SELECT COUNT(*) FROM audit_log WHERE action LIKE 'PERMIT_VEHICLE%'"),
            'outbox' => (int) $c->fetchOne("SELECT COUNT(*) FROM notification_outbox WHERE type IN ('VEHICLES_CHANGED', 'VEHICLES_REMOVED')"),
            'plates' => $this->rows('permit_vehicle'),
        ];
    }

    // ------------------------------------------------------------------ ajout, normalisation, doublons, plafond

    public function testAddingAPlateNormalizesItKeepsTheQrTheCitizenRequestAndTheOtherPermitDataUntouched(): void
    {
        $requestId = $this->approvedByBoth();
        $token = $this->token();
        $declared = $this->rows('request_permit_request_vehicle');
        $zones = $this->rows('permit_authorization_zone');

        $this->change($requestId, adds: [' ef-456-gh '], addReason: 'NEW_VEHICLE');

        self::assertSame(['GH482KT', 'EF456GH'], $this->active());
        self::assertSame($token, $this->token(), 'le QR ne change pas');
        self::assertSame($declared, $this->rows('request_permit_request_vehicle'), 'la plaque déclarée de la demande n\'est jamais modifiée');
        self::assertSame($zones, $this->rows('permit_authorization_zone'), 'aucun droit communal n\'est touché');
        self::assertSame(['revisions' => 1, 'vehicleLines' => 1, 'audits' => 1 + 1, 'outbox' => 1, 'plates' => 2], $this->counts(), 'un audit détaillé + une ligne neutre pour l\'autre commune');
        $siv = $this->em->getConnection()->fetchAssociative("SELECT format, position FROM permit_vehicle WHERE registration = 'EF456GH'");
        self::assertSame(['SIV', 1], [$siv['format'], (int) $siv['position']], 'les plaques déclarées occupent la position 0 : la suivante prend la position suivante');
    }

    public function testAFniPlateIsAcceptedAndAForeignOrMalformedPlateIsRefusedWithoutAnyWrite(): void
    {
        $requestId = $this->approvedByBoth();
        $before = $this->counts();

        $this->change($requestId, adds: ['1234 AB 56']);
        self::assertSame('FNI', $this->em->getConnection()->fetchOne("SELECT format FROM permit_vehicle WHERE registration = '1234AB56'"));
        $afterFni = $this->counts();

        foreach (['B-AB 1234', 'AB-12-CD', '', 'AB123CD; DROP TABLE'] as $bad) {
            try {
                $this->change($requestId, adds: [$bad]);
                self::fail('Plaque invalide acceptée : '.$bad);
            } catch (DomainRuleViolation $e) {
                self::assertSame($afterFni, $this->counts(), 'aucune écriture');
            }
        }
        self::assertNotSame($before, $afterFni);
    }

    public function testADuplicateOnTheSamePermitIsRefusedEvenWithOtherSeparatorsButThePlateIsAllowedOnAnotherPermit(): void
    {
        $requestId = $this->approvedByBoth();
        $before = $this->counts();

        foreach ([['gh 482 kt'], ['EF-456-GH', 'ef456gh']] as $adds) {
            try {
                $this->change($requestId, adds: $adds);
                self::fail('Doublon accepté.');
            } catch (DomainRuleViolation $e) {
                self::assertMatchesRegularExpression('/figure déjà|qu\'une fois/', $e->getMessage());
                self::assertSame($before, $this->counts());
            }
        }
        // même plaque sur un AUTRE laissez-passer : légitime (D6)
        $other = $this->service(RequestSubmissionService::class)->submitPublic(new SubmitRequestCommand($this->event->id()->toRfc4122(), 'Durand', 'Paul', null, 'paul@exemple.fr', null, 'private_person', 'home', null, TravelMode::Vehicle, [self::PLATE], [$this->organizerZone->id()->toRfc4122()], true), $this->key())->requestId;
        $this->instruction()->approve($this->managed($this->instructor), $this->organizer->id()->toRfc4122(), $other, ApprovalDuration::fixed(DurationMode::H72));
        self::assertSame(2, (int) $this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM permit_vehicle WHERE registration = 'GH482KT'"), 'même plaque sur deux laissez-passer');
    }

    public function testTheTenPlateLimitIsEnforcedOnTheFinalStateOfTheWholeOperation(): void
    {
        $requestId = $this->approvedByBoth(plates: ['AA111AA', 'AA222AA', 'AA333AA', 'AA444AA', 'AA555AA', 'AA666AA', 'AA777AA', 'AA888AA', 'AA999AA', 'BB111BB']);
        $before = $this->counts();

        try {
            $this->change($requestId, adds: ['CC111CC']);
            self::fail('Onzième plaque acceptée.');
        } catch (DomainRuleViolation $e) {
            self::assertStringContainsString('plus de 10', $e->getMessage());
            self::assertSame($before, $this->counts());
        }
        // un remplacement et un « retrait + ajout » restent à dix
        $this->change($requestId, replacements: [['from' => 'AA111AA', 'to' => 'CC111CC']], replaceReason: 'VEHICLE_CHANGED');
        $this->change($requestId, adds: ['DD111DD'], removes: ['AA222AA'], removeReason: 'VEHICLE_NO_LONGER_USED');
        self::assertCount(10, $this->active());
    }

    // ------------------------------------------------------------------ retrait, dernière plaque, remplacement

    public function testRemovalIsLogicalPriorityAndKeepsTheRowAndTheRevisionHistory(): void
    {
        $requestId = $this->approvedByBoth(plates: [self::PLATE, 'EF-456-GH']);

        $this->change($requestId, removes: ['EF-456-GH'], removeReason: 'VEHICLE_NO_LONGER_USED');

        self::assertSame(['GH482KT'], $this->active());
        self::assertCount(2, $this->plates(), 'la ligne n\'est jamais supprimée');
        $removed = $this->em->getConnection()->fetchAssociative("SELECT occurred_at, available_at FROM notification_outbox WHERE type = 'VEHICLES_REMOVED'");
        self::assertIsArray($removed);
        self::assertSame($removed['occurred_at'], $removed['available_at'], 'retrait : prioritaire, jamais regroupé');
        $line = $this->em->getConnection()->fetchAssociative('SELECT registration, action FROM permit_revision_vehicle');
        self::assertSame(['EF456GH', 'REMOVED'], [$line['registration'], $line['action']]);
    }

    public function testTheLastActivePlateCannotBeRemovedButCanBeReplacedAtomically(): void
    {
        $requestId = $this->approvedByBoth();
        $before = $this->counts();

        try {
            $this->change($requestId, removes: [self::PLATE], removeReason: 'VEHICLE_NO_LONGER_USED');
            self::fail('Retrait de la dernière plaque accepté.');
        } catch (DomainRuleViolation $e) {
            self::assertStringContainsString('au moins une plaque', $e->getMessage());
            self::assertSame($before, $this->counts());
        }
        $this->change($requestId, replacements: [['from' => self::PLATE, 'to' => 'ZZ-999-ZZ']], replaceReason: 'VEHICLE_CHANGED');

        self::assertSame(['ZZ999ZZ'], $this->active());
        $types = $this->em->getConnection()->fetchFirstColumn('SELECT type FROM permit_revision ORDER BY type');
        self::assertSame(['VEHICLE_REPLACED_IN', 'VEHICLE_REPLACED_OUT'], $types, 'deux révisions, un type qui permet de reconstituer le remplacement');
        $batches = $this->em->getConnection()->fetchFirstColumn('SELECT DISTINCT LOWER(HEX(batch_id)) FROM permit_revision');
        self::assertCount(1, $batches, 'liées par le même batch_id');
        $pairs = $this->em->getConnection()->fetchFirstColumn('SELECT JSON_EXTRACT(after_state, "$.pair") FROM permit_revision');
        self::assertSame(['0', '0'], array_map('strval', $pairs));
        self::assertSame(['REMOVED', 'ADDED'], $this->em->getConnection()->fetchFirstColumn("SELECT l.action FROM permit_revision_vehicle l JOIN permit_revision r ON r.id = l.revision_id ORDER BY FIELD(r.type, 'VEHICLE_REPLACED_OUT', 'VEHICLE_REPLACED_IN')"));
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM notification_outbox WHERE type = 'VEHICLES_REMOVED'"), 'une seule intention, prioritaire');
        self::assertSame(1, $this->counts()['outbox']);
    }

    public function testSuccessiveAddRemoveReinstateAndReplaceReuseTheRowAndKeepEveryRevision(): void
    {
        $requestId = $this->approvedByBoth();

        $this->change($requestId, adds: ['EF-456-GH'], addReason: 'NEW_VEHICLE');
        $this->change($requestId, removes: ['EF-456-GH'], removeReason: 'ENTRY_ERROR');
        $this->change($requestId, reinstates: ['EF-456-GH'], reinstateReason: 'REMOVED_IN_ERROR');
        $this->change($requestId, replacements: [['from' => 'EF-456-GH', 'to' => 'IJ-789-KL']], replaceReason: 'VEHICLE_CHANGED');

        self::assertSame(['GH482KT', 'IJ789KL'], $this->active());
        self::assertSame(3, $this->rows('permit_vehicle'), 'EF456GH : une seule ligne réutilisée');
        $types = $this->em->getConnection()->fetchFirstColumn('SELECT type FROM permit_revision ORDER BY occurred_at, id');
        self::assertEqualsCanonicalizing(['VEHICLE_ADDED', 'VEHICLE_REMOVED', 'VEHICLE_REINSTATED', 'VEHICLE_REPLACED_OUT', 'VEHICLE_REPLACED_IN'], $types);
        self::assertSame(5, $this->rows('permit_revision_vehicle'));
        self::assertSame(4, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(DISTINCT batch_id) FROM permit_revision'), 'un lot par opération');
    }

    public function testARemovedPlateIsReinstatedNeverAddedAgain(): void
    {
        $requestId = $this->approvedByBoth(plates: [self::PLATE, 'EF-456-GH']);
        $this->change($requestId, removes: ['EF-456-GH'], removeReason: 'ENTRY_ERROR');
        $before = $this->counts();

        try {
            $this->change($requestId, adds: ['EF-456-GH']);
            self::fail('Ajout d\'une plaque retirée accepté.');
        } catch (DomainRuleViolation $e) {
            self::assertStringContainsString('réactivez-la', $e->getMessage());
            self::assertSame($before, $this->counts());
        }
        $this->change($requestId, reinstates: ['EF-456-GH'], reinstateReason: 'VEHICLE_BACK_IN_USE');
        self::assertSame(['GH482KT', 'EF456GH'], $this->active());
        self::assertSame(2, $this->rows('permit_vehicle'));
    }

    public function testAGroupedOperationIsAtomicWithOneRevisionPerPlateACommonBatchAndOneNotification(): void
    {
        $requestId = $this->approvedByBoth(plates: [self::PLATE, 'EF-456-GH']);
        $before = $this->counts();

        $this->change($requestId, adds: ['IJ-789-KL', 'MN-012-OP'], removes: ['EF-456-GH'], addReason: 'NEW_VEHICLE', removeReason: 'VEHICLE_NO_LONGER_USED');

        $after = $this->counts();
        self::assertSame(3, $after['revisions'] - $before['revisions'], 'une révision par plaque');
        self::assertSame(1, $after['outbox'] - $before['outbox'], 'une seule intention de notification pour l\'opération');
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(DISTINCT batch_id) FROM permit_revision'));
        self::assertSame(['VEHICLES_REMOVED'], $this->em->getConnection()->fetchFirstColumn("SELECT type FROM notification_outbox WHERE type LIKE 'VEHICLES_%'"), 'opération avec retrait : prioritaire');
        self::assertSame(['GH482KT', 'IJ789KL', 'MN012OP'], $this->active());
    }

    public function testAGroupedOperationWithOneInvalidPlateHasNoPartialEffect(): void
    {
        $requestId = $this->approvedByBoth(plates: [self::PLATE, 'EF-456-GH']);
        $before = $this->counts();

        try {
            $this->change($requestId, adds: ['IJ-789-KL', 'GH-482-KT'], removes: ['EF-456-GH'], removeReason: 'ENTRY_ERROR');
            self::fail('Opération invalide acceptée.');
        } catch (DomainRuleViolation) {
            self::assertSame($before, $this->counts());
            self::assertSame(['GH482KT', 'EF456GH'], $this->active());
        }
    }

    public function testAFailureInTheMiddleOfAGroupedOperationRollsEverythingBack(): void
    {
        $requestId = $this->approvedByBoth();
        $before = $this->counts();
        $failing = new FailingAuditTrail(self::getContainer()->get(AuditTrail::class), 2);

        try {
            $this->change($requestId, adds: ['EF-456-GH', 'IJ-789-KL'], service: $this->vehicles($failing));
            self::fail('Le journal d\'audit en échec doit faire échouer toute l\'opération.');
        } catch (\Throwable $e) {
            self::assertNotInstanceOf(\PHPUnit\Framework\AssertionFailedError::class, $e);
        }

        self::assertSame($before, $this->counts(), 'ni révision, ni audit, ni notification, ni ligne de plaque');
        self::assertSame(['GH482KT'], $this->active());
    }

    // ------------------------------------------------------------------ motifs, commentaire, portée

    public function testReasonsAreMandatoryForRemovalReplacementAndReinstatementAndOptionalForAddition(): void
    {
        $requestId = $this->approvedByBoth(plates: [self::PLATE, 'EF-456-GH']);
        $this->change($requestId, adds: ['IJ-789-KL']); // sans motif : permis
        $before = $this->counts();

        $cases = [
            [['removes' => ['EF-456-GH']], 'motif du retrait'],
            [['replacements' => [['from' => 'EF-456-GH', 'to' => 'MN-012-OP']]], 'motif du remplacement'],
            [['removes' => ['EF-456-GH'], 'removeReason' => 'INCONNU'], 'motif du retrait'],
            [['adds' => ['MN-012-OP'], 'addReason' => 'INCONNU'], 'Motif d\'ajout'],
            [['removes' => ['EF-456-GH'], 'removeReason' => 'OTHER'], 'commentaire est obligatoire'],
        ];
        foreach ($cases as [$arguments, $message]) {
            try {
                $this->change($requestId, ...$arguments);
                self::fail('Refus attendu : '.$message);
            } catch (DomainRuleViolation $e) {
                self::assertStringContainsString($message, $e->getMessage());
                self::assertSame($before, $this->counts());
            }
        }
        $this->change($requestId, removes: ['EF-456-GH'], removeReason: 'OTHER', comment: self::COMMENT);
        $this->change($requestId, reinstates: ['EF-456-GH'], reinstateReason: 'OTHER', comment: self::COMMENT);
        try {
            $this->change($requestId, removes: ['EF-456-GH']);
            self::fail('Motif obligatoire.');
        } catch (DomainRuleViolation $e) {
            self::assertStringContainsString('motif du retrait', $e->getMessage());
        }
    }

    /** @return iterable<string, array{string}> */
    public static function commentsWithAPlate(): iterable
    {
        yield 'SIV avec tirets' => ['véhicule GH-482-KT vendu'];
        yield 'SIV collée' => ['gh482kt'];
        yield 'SIV espaces' => ['ancienne plaque ef 456 gh'];
        yield 'FNI' => ['remplace la 1234 AB 56'];
    }

    #[DataProvider('commentsWithAPlate')]
    public function testACommentContainingAPlateIsRefusedWhateverItsFormat(string $comment): void
    {
        $requestId = $this->approvedByBoth(plates: [self::PLATE, 'EF-456-GH']);
        $before = $this->counts();

        $this->expectException(DomainRuleViolation::class);
        $this->expectExceptionMessage('aucune plaque');
        try {
            $this->change($requestId, adds: ['IJ-789-KL'], addReason: 'OTHER', comment: $comment);
        } finally {
            self::assertSame($before, $this->counts());
        }
    }

    public function testTheCommentLengthIsLimitedAndAnOrdinaryCommentIsKeptOnlyInTheRevision(): void
    {
        $requestId = $this->approvedByBoth();

        try {
            $this->change($requestId, adds: ['EF-456-GH'], addReason: 'OTHER', comment: str_repeat('x', 201));
            self::fail('Commentaire trop long accepté.');
        } catch (DomainRuleViolation $e) {
            self::assertStringContainsString('200', $e->getMessage());
        }
        $this->change($requestId, adds: ['EF-456-GH'], addReason: 'OTHER', comment: self::COMMENT);
        self::assertSame(self::COMMENT, $this->em->getConnection()->fetchOne('SELECT reason_text FROM permit_revision'));
    }

    public function testTheGlobalScopeMustBeConfirmedAndNothingIsWrittenOtherwise(): void
    {
        $requestId = $this->approvedByBoth();
        $before = $this->counts();

        $this->expectException(DomainRuleViolation::class);
        $this->expectExceptionMessage('Confirmez');
        try {
            $this->change($requestId, adds: ['EF-456-GH'], confirm: false);
        } finally {
            self::assertSame($before, $this->counts());
        }
    }

    // ------------------------------------------------------------------ mode de déplacement, identifiants forgés

    public function testAFootBikePermitRefusesEveryPlateOperationWithTheUniformRefusal(): void
    {
        $requestId = $this->approvedByBoth(TravelMode::FootBike);
        $before = $this->counts();

        $this->expectException(OperationNotAllowed::class);
        try {
            $this->change($requestId, adds: ['EF-456-GH']);
        } finally {
            self::assertSame($before, $this->counts());
            self::assertSame([], $this->plates());
        }
    }

    /** @return iterable<string, array{string}> */
    public static function forgedIds(): iterable
    {
        yield 'mal formé' => ['pas-un-uuid'];
        yield 'inexistant' => ['0190a0a0-0000-7000-8000-000000000000'];
        yield 'vide' => [''];
    }

    #[DataProvider('forgedIds')]
    public function testAForgedPlateIdentifierGetsTheUniformRefusalAndNothingIsWritten(string $id): void
    {
        $requestId = $this->approvedByBoth(plates: [self::PLATE, 'EF-456-GH']);
        $before = $this->counts();

        foreach ([new VehicleChangeRequest(removes: [$id], removeReason: 'ENTRY_ERROR', confirmedGlobalScope: true), new VehicleChangeRequest(reinstates: [$id], reinstateReason: 'OTHER', comment: 'x', confirmedGlobalScope: true), new VehicleChangeRequest(replacements: [['from' => $id, 'to' => 'MN-012-OP']], replaceReason: 'ENTRY_ERROR', confirmedGlobalScope: true)] as $request) {
            try {
                $this->vehicles()->change($this->managed($this->instructor), $this->organizer->id()->toRfc4122(), $requestId, $request);
                self::fail('Identifiant forgé accepté.');
            } catch (OperationNotAllowed) {
                self::assertSame($before, $this->counts());
            }
        }
    }

    public function testAPlateOfAnotherPermitIsRefusedAsForged(): void
    {
        $first = $this->approvedByBoth(plates: [self::PLATE, 'EF-456-GH']);
        $second = $this->service(RequestSubmissionService::class)->submitPublic(new SubmitRequestCommand($this->event->id()->toRfc4122(), 'Durand', 'Paul', null, 'paul@exemple.fr', null, 'private_person', 'home', null, TravelMode::Vehicle, ['QQ-555-QQ', 'RR-666-RR'], [$this->organizerZone->id()->toRfc4122()], true), $this->key())->requestId;
        $this->instruction()->approve($this->managed($this->instructor), $this->organizer->id()->toRfc4122(), $second, ApprovalDuration::fixed(DurationMode::H72));
        $foreign = $this->idOf('QQ-555-QQ');
        $before = $this->counts();

        $this->expectException(OperationNotAllowed::class);
        try {
            $this->vehicles()->change($this->managed($this->instructor), $this->organizer->id()->toRfc4122(), $first, new VehicleChangeRequest(removes: [$foreign], removeReason: 'ENTRY_ERROR', confirmedGlobalScope: true));
        } finally {
            self::assertSame($before, $this->counts());
        }
    }

    // ------------------------------------------------------------------ suspension, participation, révocation, rôles

    public function testASuspendedEventAllowsOnlyRemovalAndRefusesAWholeGroupContainingAForbiddenAction(): void
    {
        $requestId = $this->approvedByBoth(plates: [self::PLATE, 'EF-456-GH', 'IJ-789-KL']);
        $this->change($requestId, removes: ['IJ-789-KL'], removeReason: 'ENTRY_ERROR');
        $this->sql("UPDATE event_event SET status = 'SUSPENDED'");
        $before = $this->counts();

        $forbidden = [
            ['adds' => ['MN-012-OP']],
            ['reinstates' => ['IJ-789-KL'], 'reinstateReason' => 'OTHER', 'comment' => 'x'],
            ['replacements' => [['from' => 'EF-456-GH', 'to' => 'MN-012-OP']], 'replaceReason' => 'ENTRY_ERROR'],
            ['adds' => ['MN-012-OP'], 'removes' => ['EF-456-GH'], 'removeReason' => 'ENTRY_ERROR'],
        ];
        foreach ($forbidden as $arguments) {
            try {
                $this->change($requestId, ...$arguments);
                self::fail('Refus attendu pendant la suspension.');
            } catch (DomainRuleViolation $e) {
                self::assertStringContainsString('suspendu', $e->getMessage());
                self::assertSame($before, $this->counts(), 'refus total, aucun effet partiel');
            }
        }
        $this->change($requestId, removes: ['EF-456-GH'], removeReason: 'ENTRY_ERROR');
        self::assertSame(['GH482KT'], $this->active());
        try {
            $this->change($requestId, removes: [self::PLATE], removeReason: 'ENTRY_ERROR');
            self::fail('La dernière plaque ne se retire pas, même pendant la suspension.');
        } catch (DomainRuleViolation $e) {
            self::assertStringContainsString('au moins une plaque', $e->getMessage());
        }
    }

    public function testAClosedEventRefusesEverything(): void
    {
        $requestId = $this->approvedByBoth(plates: [self::PLATE, 'EF-456-GH']);
        $this->sql("UPDATE event_event SET status = 'CLOSED'");
        $before = $this->counts();

        $this->expectException(DomainRuleViolation::class);
        try {
            $this->change($requestId, removes: ['EF-456-GH'], removeReason: 'ENTRY_ERROR');
        } finally {
            self::assertSame($before, $this->counts());
        }
    }

    /** @return iterable<string, array{string}> */
    public static function inactiveParticipations(): iterable
    {
        yield 'suspendue' => ['SUSPENDED'];
        yield 'clôturée' => ['CLOSED'];
    }

    #[DataProvider('inactiveParticipations')]
    public function testASuspendedOrClosedParticipationRefusesEveryChange(string $status): void
    {
        $requestId = $this->approvedByBoth();
        $this->sql('UPDATE event_participation SET status = :s WHERE municipality_id = :m', ['s' => $status, 'm' => $this->organizer->id()->toBinary()]);
        $before = $this->counts();

        $this->expectException(PermitAccessUnavailable::class);
        try {
            $this->change($requestId, adds: ['EF-456-GH']);
        } finally {
            self::assertSame($before, $this->counts());
        }
    }

    public function testTheOtherCommunesActiveParticipationStillAllowsItToChangeTheCommonPlates(): void
    {
        $requestId = $this->approvedByBoth();
        $this->sql("UPDATE event_participation SET status = 'SUSPENDED' WHERE municipality_id = :m", ['m' => $this->organizer->id()->toBinary()]);

        $this->change($requestId, adds: ['EF-456-GH'], agent: $this->partnerInstructor, commune: $this->partner);

        self::assertSame(['GH482KT', 'EF456GH'], $this->active(), 'la participation de l\'autre commune est active : elle peut modifier les plaques communes');
    }

    public function testARevokedAuthorizationRefusesEveryPlateChangeWhileAnotherCommuneStillCan(): void
    {
        $requestId = $this->approvedByBoth(plates: [self::PLATE, 'EF-456-GH']);
        $this->sql('UPDATE permit_authorization SET revoked_at = :t WHERE municipality_id = :m', ['t' => '2026-06-12 08:00:00', 'm' => $this->organizer->id()->toBinary()]);
        $before = $this->counts();

        try {
            $this->change($requestId, removes: ['EF-456-GH'], removeReason: 'ENTRY_ERROR');
            self::fail('Refus attendu.');
        } catch (DomainRuleViolation $e) {
            self::assertStringContainsString('révoquée', $e->getMessage());
            self::assertSame($before, $this->counts());
        }
        $this->change($requestId, removes: ['EF-456-GH'], removeReason: 'ENTRY_ERROR', agent: $this->partnerInstructor, commune: $this->partner);
        self::assertSame(['GH482KT'], $this->active());
        $neutral = (int) $this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM audit_log WHERE action = 'PERMIT_VEHICLES_CHANGED_BY_OTHER' AND municipality_id = :m", ['m' => $this->organizer->id()->toBinary()]);
        self::assertSame(1, $neutral, 'une commune titulaire révoquée reçoit aussi la ligne neutre');
    }

    public function testRolesAndPerimeterAreEnforced(): void
    {
        $requestId = $this->approvedByBoth();
        $before = $this->counts();

        // EVENT_MANAGER : aucune modification
        try {
            $this->change($requestId, adds: ['EF-456-GH'], agent: $this->managerOnly);
            self::fail('Rôle insuffisant accepté.');
        } catch (PermitAccessDenied|OperationNotAllowed) {
            self::assertSame($before, $this->counts());
        }
        // agent d'une commune sans autorisation sur ce laissez-passer : refus uniforme
        $third = $this->service(RequestSubmissionService::class)->submitPublic(new SubmitRequestCommand($this->event->id()->toRfc4122(), 'Durand', 'Paul', null, 'paul@exemple.fr', null, 'private_person', 'home', null, TravelMode::Vehicle, ['QQ-555-QQ'], [$this->partnerZone->id()->toRfc4122()], true), $this->key())->requestId;
        $this->instruction()->approve($this->managed($this->partnerInstructor), $this->partner->id()->toRfc4122(), $third, ApprovalDuration::fixed(DurationMode::H72));
        $count = $this->counts();
        $this->expectException(OperationNotAllowed::class);
        try {
            $this->change($third, adds: ['EF-456-GH'], agent: $this->instructor, commune: $this->organizer);
        } finally {
            self::assertSame($count, $this->counts());
        }
    }

    // ------------------------------------------------------------------ audit, historique, fuite intercommunale

    public function testNeitherAuditNorRevisionStatesNorTheNotificationHoldAPlateOrTheComment(): void
    {
        $requestId = $this->approvedByBoth();

        $this->change($requestId, adds: ['EF-456-GH'], addReason: 'OTHER', comment: self::COMMENT);
        $this->change($requestId, replacements: [['from' => 'EF-456-GH', 'to' => 'IJ-789-KL']], replaceReason: 'OTHER', comment: self::COMMENT);

        $c = $this->em->getConnection();
        $dump = json_encode([
            $c->fetchAllAssociative("SELECT new_value, previous_value FROM audit_log WHERE action LIKE 'PERMIT_VEHICLE%'"),
            $c->fetchAllAssociative('SELECT before_state, after_state FROM permit_revision'),
            $c->fetchAllAssociative('SELECT type, LOWER(HEX(revision_id)) AS revision, LOWER(HEX(authorization_id)) AS authorization FROM notification_outbox'),
        ], \JSON_THROW_ON_ERROR);
        foreach ([self::COMMENT, 'EF456GH', 'EF-456-GH', 'IJ789KL', 'IJ-789-KL', 'GH482KT', 'GH-482-KT', 'camille@exemple.fr', 'Camille', $this->token()] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $dump, 'ni plaque, ni commentaire, ni donnée personnelle : '.$forbidden);
        }
        $audit = json_decode((string) $c->fetchOne("SELECT new_value FROM audit_log WHERE action = 'PERMIT_VEHICLE_ADDED'"), true);
        self::assertSame('OTHER', $audit['reason']);
        self::assertNull($c->fetchOne('SELECT authorization_id FROM permit_revision LIMIT 1'), 'les plaques appartiennent au laissez-passer, pas à une autorisation');
        self::assertNull($c->fetchOne("SELECT authorization_id FROM notification_outbox WHERE type LIKE 'VEHICLES_%' LIMIT 1"));
    }

    public function testTheOtherCommuneSeesOnlyANeutralLineWithoutPlateReasonCommentOrAgent(): void
    {
        $requestId = $this->approvedByBoth();
        $this->change($requestId, adds: ['EF-456-GH'], addReason: 'OTHER', comment: self::COMMENT);
        $this->change($requestId, replacements: [['from' => 'EF-456-GH', 'to' => 'IJ-789-KL']], replaceReason: 'ENTRY_ERROR');
        $c = self::getContainer();
        $reader = new PermitRightsOverviewReader($c->get(PermitRevisionGuard::class), $c->get(\App\Permit\Application\PermitRightsEvaluator::class), $c->get(PermitRevisionRepository::class), $this->clock, $c->get(ProposableZones::class));

        $own = $reader->overview($this->managed($this->instructor), $this->organizer->id()->toRfc4122(), $requestId);
        $other = $reader->overview($this->managed($this->partnerInstructor), $this->partner->id()->toRfc4122(), $requestId);

        self::assertSame(['Plaque remplacée (nouvelle plaque)', 'Plaque remplacée (ancienne plaque)', 'Plaque ajoutée'], array_map(static fn ($l): string => $l->label, $own->history));
        self::assertSame('IJ-789-KL', $own->history[0]->vehicle['plate'] ?? null, 'détail pour la commune qui a agi');
        self::assertSame(['Véhicules modifiés', 'Véhicules modifiés', 'Véhicules modifiés'], array_map(static fn ($l): string => $l->label, $other->history), 'ligne neutre : type, date, commune');
        foreach ($other->history as $line) {
            self::assertFalse($line->own);
            self::assertNull($line->vehicle);
            self::assertNull($line->reasonLabel);
            self::assertNull($line->comment);
            self::assertNull($line->actorName);
            self::assertSame($this->organizer->name(), $line->municipalityName);
        }
        $history = serialize($other->history);
        foreach ([self::COMMENT, 'OTHER', 'ENTRY_ERROR', 'Erreur de saisie', 'EF456GH', 'EF-456-GH', 'IJ789KL', 'IJ-789-KL'] as $leak) {
            self::assertStringNotContainsString($leak, $history, 'aucune fuite intercommunale dans l\'historique : '.$leak);
        }
        // l'autre commune titulaire voit l'état COMMUN courant (les plaques sont communes), jamais l'historique détaillé
        self::assertNotNull($other->vehicles);
        self::assertSame(['GH-482-KT', 'IJ-789-KL', 'EF-456-GH'], array_map(static fn ($r): string => $r->plate, $other->vehicles->rows));
        self::assertSame([$this->organizer->name()], $other->vehicles->otherMunicipalities);
    }

    public function testTheOverviewOffersTheRightPlateActionsAndAnnouncesTheOtherCommunes(): void
    {
        $requestId = $this->approvedByBoth();
        $c = self::getContainer();
        $reader = new PermitRightsOverviewReader($c->get(PermitRevisionGuard::class), $c->get(\App\Permit\Application\PermitRightsEvaluator::class), $c->get(PermitRevisionRepository::class), $this->clock, $c->get(ProposableZones::class));
        $overview = static fn (self $t) => $reader->overview($t->managed($t->instructor), $t->organizer->id()->toRfc4122(), $requestId);

        $panel = $overview($this)->vehicles ?? self::fail();
        self::assertTrue($panel->vehicleMode);
        self::assertTrue($panel->canChange);
        self::assertTrue($panel->canAdd);
        self::assertSame([$this->partner->name()], $panel->otherMunicipalities, 'la portée globale est annoncée');
        self::assertSame(10, $panel->max);

        $this->sql("UPDATE event_event SET status = 'SUSPENDED'");
        $suspended = $overview($this)->vehicles ?? self::fail();
        self::assertTrue($suspended->canChange, 'retrait possible');
        self::assertFalse($suspended->canAdd);
        self::assertStringContainsString('pas actif', $suspended->addBlockedReason);
        $this->sql("UPDATE event_event SET status = 'ACTIVE'");

        $this->sql('UPDATE permit_authorization SET revoked_at = :t WHERE municipality_id = :m', ['t' => '2026-06-12 08:00:00', 'm' => $this->partner->id()->toBinary()]);
        self::assertSame([], $overview($this)->vehicles?->otherMunicipalities, 'une commune révoquée n\'est plus annoncée comme concernée');
    }

    public function testACommuneWhoseAuthorizationIsRevokedSeesNoPlateAndCannotChangeAny(): void
    {
        $requestId = $this->approvedByBoth();
        $this->sql('UPDATE permit_authorization SET revoked_at = :t WHERE municipality_id = :m', ['t' => '2026-06-12 08:00:00', 'm' => $this->organizer->id()->toBinary()]);
        $c = self::getContainer();
        $reader = new PermitRightsOverviewReader($c->get(PermitRevisionGuard::class), $c->get(\App\Permit\Application\PermitRightsEvaluator::class), $c->get(PermitRevisionRepository::class), $this->clock, $c->get(ProposableZones::class));

        $panel = $reader->overview($this->managed($this->instructor), $this->organizer->id()->toRfc4122(), $requestId)->vehicles ?? self::fail();

        self::assertSame([], $panel->rows);
        self::assertFalse($panel->canChange);
        self::assertStringContainsString('révoquée', $panel->blockedReason);
    }

    public function testAFootBikePermitHasNoPlatePanel(): void
    {
        $requestId = $this->approvedByBoth(TravelMode::FootBike);
        $c = self::getContainer();
        $reader = new PermitRightsOverviewReader($c->get(PermitRevisionGuard::class), $c->get(\App\Permit\Application\PermitRightsEvaluator::class), $c->get(PermitRevisionRepository::class), $this->clock, $c->get(ProposableZones::class));

        $panel = $reader->overview($this->managed($this->instructor), $this->organizer->id()->toRfc4122(), $requestId)->vehicles ?? self::fail();

        self::assertFalse($panel->vehicleMode);
        self::assertFalse($panel->canChange);
        self::assertSame([], $panel->rows);
    }
}
