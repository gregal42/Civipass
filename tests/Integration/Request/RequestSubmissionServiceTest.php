<?php

declare(strict_types=1);

namespace App\Tests\Integration\Request;

use App\Event\Application\Support\EventLocker;
use App\Event\Application\Support\EventTransactions;
use App\Event\Domain\Event;
use App\Event\Domain\EventParticipationRepository;
use App\Event\Domain\EventZoneRepository;
use App\Municipality\Application\MunicipalityContext;
use App\Municipality\Domain\PhoneNumberNormalizer;
use App\Request\Application\ProposableZones;
use App\Request\Application\UploadedDocumentInspector;
use App\Request\Domain\PermitRequestRepository;
use App\Request\Domain\RequestReferenceGenerator;
use App\Request\Domain\RequestReferentialRepository;
use Psr\Clock\ClockInterface;
use App\Event\Domain\EventParticipation;
use App\Event\Domain\EventZone;
use App\Event\Domain\EventZoneValidity;
use App\Event\Domain\Validity\ValiditySlot;
use App\Identity\Domain\User;
use App\Municipality\Domain\Municipality;
use App\Municipality\Domain\MunicipalityRole as Role;
use App\Request\Application\Command\AttachmentReference;
use App\Request\Domain\ApprovalDuration;
use App\Request\Application\Command\SubmitRequestCommand;
use App\Request\Application\RequestReferentialInitializer;
use App\Request\Application\RequestSubmissionService;
use App\Request\Application\TemporaryUploadService;
use App\Shared\Application\AuditTrail;
use App\Shared\Application\FileStorageInterface;
use App\Request\Domain\TravelMode;
use App\Shared\Domain\DomainRuleViolation;
use App\Shared\Domain\OperationNotAllowed;
use App\Tests\Support\EventFixtures as F;
use App\Tests\Support\MembershipBuilder;
use App\Tests\Support\MunicipalityBuilder;
use App\Tests\Support\FailingAuditTrail;
use App\Tests\Support\RequestDepositTestCase;
use App\Tests\Support\SampleFiles as S;
use App\Tests\Support\TimeFactory as T;
use App\Tests\Support\UserBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Uid\Uuid;

#[Group('requires-request-schema')]
final class RequestSubmissionServiceTest extends RequestDepositTestCase
{
    private Event $event;
    private Municipality $organizer;
    private Municipality $partner;
    private EventParticipation $organizerParticipation;
    private EventParticipation $partnerParticipation;
    private EventZone $organizerZone;
    private EventZone $partnerZone;
    private User $instructor;
    private User $partnerInstructor;
    private User $adminOnly;
    private int $keys = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service(RequestReferentialInitializer::class)->initialize();
        $this->organizer = MunicipalityBuilder::aMunicipality()->build();
        $this->partner = MunicipalityBuilder::aMunicipality()->withInseeCode('33527')->build();
        $creator = UserBuilder::aUser()->build();
        $this->instructor = UserBuilder::aUser()->build();
        $this->partnerInstructor = UserBuilder::aUser()->build();
        $this->adminOnly = UserBuilder::aUser()->build();
        $type = F::type();
        $this->event = F::draft($this->organizer, $creator, $type);
        $this->event->activate(F::now());
        $this->organizerParticipation = F::organizerParticipation($this->event);
        $this->partnerParticipation = F::invited($this->event, $this->partner, $creator);
        $this->partnerParticipation->accept(F::now());
        $this->organizerZone = F::readyZone($this->organizerParticipation);
        $this->partnerZone = F::readyZone($this->partnerParticipation);
        $this->save(
            $this->organizer,
            $this->partner,
            $creator,
            $this->instructor,
            $this->partnerInstructor,
            $this->adminOnly,
            $type,
            $this->event,
            $this->organizerParticipation,
            $this->partnerParticipation,
            $this->organizerZone->createdBy(),
            $this->organizerZone,
            $this->partnerZone->createdBy(),
            $this->partnerZone,
            MembershipBuilder::aMembership($this->instructor, $this->organizer)->withRoles(Role::Instructor)->build(),
            MembershipBuilder::aMembership($this->partnerInstructor, $this->partner)->withRoles(Role::Instructor)->build(),
            MembershipBuilder::aMembership($this->adminOnly, $this->organizer)->withRoles(Role::MunicipalityAdmin)->build(),
        );
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove(self::getContainer()->getParameter('kernel.project_dir').'/var/storage_test');
        parent::tearDown();
    }

    private function deposit(): RequestSubmissionService
    {
        return $this->service(RequestSubmissionService::class);
    }

    private function key(): string
    {
        return sprintf('test-attempt-%016d', ++$this->keys);
    }

    /**
     * @param array<string, mixed> $overrides arguments nommés de SubmitRequestCommand
     */
    private function command(array $overrides = []): SubmitRequestCommand
    {
        return new SubmitRequestCommand(...array_merge([
            'eventId' => $this->event->id()->toRfc4122(),
            'lastName' => 'Martin',
            'firstName' => 'Camille',
            'address' => null,
            'email' => 'camille@exemple.fr',
            'phone' => null,
            'categoryCode' => 'private_person',
            'motifCode' => 'home',
            'motifDetail' => null,
            'travelMode' => TravelMode::FootBike,
            'plates' => [],
            'zoneIds' => [$this->organizerZone->id()->toRfc4122()],
            'privacyAttested' => true,
        ], $overrides));
    }

    private function rows(string $table): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM '.$table);
    }

    private function sql(string $statement, ?string $id = null): void
    {
        $this->em->getConnection()->executeStatement($statement, null === $id ? [] : ['id' => str_replace('-', '', $id)]);
        $this->em->clear();
    }

    /**
     * @return list<array{string, array<string, mixed>}>
     */
    private function audits(): array
    {
        $rows = $this->em->getConnection()->fetchAllAssociative("SELECT LOWER(HEX(municipality_id)) AS m, origin, actor_id, new_value FROM audit_log WHERE action = 'REQUEST_SUBMITTED' ORDER BY m");

        return array_map(static fn (array $r): array => [$r['m'].'|'.$r['origin'].'|'.(null === $r['actor_id'] ? '-' : 'user'), json_decode((string) $r['new_value'], true)], $rows);
    }

    public function testPublicDepositCreatesTheWholeRequestAtomically(): void
    {
        $result = $this->deposit()->submitPublic($this->command(['travelMode' => TravelMode::Vehicle, 'plates' => ['ab-123-cd']]), $this->key());

        self::assertFalse($result->replayed);
        self::assertMatchesRegularExpression('/^DEM-\d{4}-0001$/', $result->reference);
        self::assertSame([1, 1, 1, 1, 1], [$this->rows('request_permit_request'), $this->rows('request_permit_request_municipality'), $this->rows('request_permit_request_zone'), $this->rows('request_permit_request_vehicle'), $this->rows('request_idempotency_key')]);
        $row = $this->em->getConnection()->fetchAssociative('SELECT origin, entered_by_user_id, entered_by_municipality_id, motif_label FROM request_permit_request');
        self::assertSame(['PUBLIC', null, null, 'Accès au domicile'], array_values($row ?: []));

        $audits = $this->audits();
        self::assertCount(1, $audits);
        self::assertSame(str_replace('-', '', $this->organizer->id()->toRfc4122()).'|PUBLIC|-', $audits[0][0]);
        self::assertSame($result->reference, $audits[0][1]['reference']);
        self::assertStringNotContainsString('Martin', (string) json_encode($audits));
    }

    public function testMulticommunalRequestKeepsOneAuditLinePerMunicipalityWithoutLeakingTheOthers(): void
    {
        $this->deposit()->submitPublic($this->command(['zoneIds' => [$this->organizerZone->id()->toRfc4122(), $this->partnerZone->id()->toRfc4122()]]), $this->key());

        self::assertSame([1, 2, 2], [$this->rows('request_permit_request'), $this->rows('request_permit_request_municipality'), $this->rows('request_permit_request_zone')]);
        $statuses = $this->em->getConnection()->fetchFirstColumn('SELECT DISTINCT status FROM request_permit_request_municipality');
        self::assertSame(['PENDING'], $statuses);
        $audits = $this->audits();
        self::assertCount(2, $audits);
        foreach ($audits as [, $state]) {
            self::assertSame(1, $state['zones']);
            $keys = array_keys($state);
            sort($keys);
            self::assertSame(['enteredHere', 'event', 'origin', 'reference', 'travelMode', 'vehicles', 'zones'], $keys);
        }
    }

    /** La délivrance directe approuve : l'événement doit avoir des périodes d'accès à venir (l'application utilise la vraie horloge). */
    private function eventAroundNow(): void
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->em->getConnection()->executeStatement('UPDATE event_event SET starts_at = :s, ends_at = :e', ['s' => $now->modify('-1 day')->format('Y-m-d H:i:s'), 'e' => $now->modify('+10 days')->format('Y-m-d H:i:s')]);
        $this->em->refresh($this->event); // les agents du décor restent gérés (ils sont rattachés à la décision)
    }

    public function testAgentDepositRecordsTheAgentAndOnlyConcernsHisOwnMunicipality(): void
    {
        $this->eventAroundNow();
        $this->deposit()->issueDirectly($this->instructor, $this->organizer->id()->toRfc4122(), $this->command(['zoneIds' => [$this->organizerZone->id()->toRfc4122()]]), ApprovalDuration::untilClosure(), $this->key());

        $row = $this->em->getConnection()->fetchAssociative('SELECT origin, LOWER(HEX(entered_by_user_id)) AS u, LOWER(HEX(entered_by_municipality_id)) AS m FROM request_permit_request');
        self::assertSame('AGENT', $row['origin']);
        self::assertSame(str_replace('-', '', $this->instructor->id()->toRfc4122()), $row['u']);
        self::assertSame(str_replace('-', '', $this->organizer->id()->toRfc4122()), $row['m']);
        $parts = $this->em->getConnection()->fetchFirstColumn('SELECT LOWER(HEX(municipality_id)) FROM request_permit_request_municipality');
        self::assertSame([str_replace('-', '', $this->organizer->id()->toRfc4122())], $parts);

        // Audit : la commune de saisie de l'agent est aussi la seule commune du dossier.
        $byMunicipality = [];
        foreach ($this->audits() as [$key, $state]) {
            $byMunicipality[explode('|', $key)[0]] = $state;
        }
        self::assertSame(1, $byMunicipality[str_replace('-', '', $this->organizer->id()->toRfc4122())]['zones']);
        self::assertTrue($byMunicipality[str_replace('-', '', $this->organizer->id()->toRfc4122())]['enteredHere']);
    }

    public function testAnAgentCannotRequestZonesOfAnotherMunicipalityEvenAlongsideHisOwn(): void
    {
        foreach ([[$this->partnerZone], [$this->organizerZone, $this->partnerZone]] as $zones) {
            try {
                $this->deposit()->issueDirectly($this->instructor, $this->organizer->id()->toRfc4122(), $this->command(['zoneIds' => array_map(static fn ($z): string => $z->id()->toRfc4122(), $zones)]), ApprovalDuration::untilClosure(), $this->key());
                self::fail('une zone d\'une autre commune est acceptée');
            } catch (DomainRuleViolation) {
                $this->addToAssertionCount(1);
            }
        }
        self::assertSame(0, $this->rows('request_permit_request'));
    }

    public function testAgentEntryRightsAreCheckedOnTheServer(): void
    {
        $cases = [
            'commune dont l\'agent n\'est pas membre (identifiant forgé)' => [$this->instructor, $this->partner],
            'agent d\'une commune inconnue' => [$this->instructor, null],
        ];
        foreach ($cases as $label => [$agent, $municipality]) {
            try {
                $this->deposit()->issueDirectly($agent, $municipality?->id()->toRfc4122() ?? Uuid::v7()->toRfc4122(), $this->command(), ApprovalDuration::untilClosure(), $this->key());
                self::fail($label);
            } catch (OperationNotAllowed) {
                $this->addToAssertionCount(1);
            }
        }
        self::assertSame(0, $this->rows('request_permit_request'));
    }

    public function testTheCommunalAdministratorMayEnterARequestForHisOwnMunicipality(): void
    {
        $this->eventAroundNow();
        $result = $this->deposit()->issueDirectly($this->adminOnly, $this->organizer->id()->toRfc4122(), $this->command(['zoneIds' => [$this->organizerZone->id()->toRfc4122()]]), ApprovalDuration::untilClosure(), $this->key());

        self::assertFalse($result->replayed);
        self::assertSame(1, $this->rows('request_permit_request'));
    }

    public function testAnAgentWhoseParticipationIsNotActiveCannotEnterARequest(): void
    {
        $this->sql('UPDATE event_participation SET status = \'SUSPENDED\' WHERE id = UNHEX(:id)', $this->partnerParticipation->id()->toRfc4122());

        $this->expectException(OperationNotAllowed::class);
        $this->deposit()->issueDirectly($this->partnerInstructor, $this->partner->id()->toRfc4122(), $this->command(['zoneIds' => [$this->partnerZone->id()->toRfc4122()]]), ApprovalDuration::untilClosure(), $this->key());
    }

    public function testAClosedOrSuspendedEventRefusesTheDepositAndAnUnknownEventIsUniform(): void
    {
        $this->sql('UPDATE event_event SET status = \'SUSPENDED\' WHERE id = UNHEX(:id)', $this->event->id()->toRfc4122());
        try {
            $this->deposit()->submitPublic($this->command(), $this->key());
            self::fail();
        } catch (DomainRuleViolation $e) {
            self::assertStringContainsString('ne sont pas ouvertes', $e->getMessage());
        }
        foreach ([Uuid::v7()->toRfc4122(), 'pas-un-uuid'] as $forged) {
            try {
                $this->deposit()->submitPublic($this->command(['eventId' => $forged]), $this->key());
                self::fail($forged);
            } catch (OperationNotAllowed) {
                $this->addToAssertionCount(1);
            }
        }
        self::assertSame(0, $this->rows('request_permit_request'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unproposableZones(): iterable
    {
        yield 'validité à configurer' => ['UPDATE event_zone SET validity_mode = \'UNCONFIGURED\' WHERE id = UNHEX(:id)'];
        yield 'zone suspendue' => ['UPDATE event_zone SET status = \'SUSPENDED\' WHERE id = UNHEX(:id)'];
        yield 'zone supprimée' => ['UPDATE event_zone SET deleted_at = NOW() WHERE id = UNHEX(:id)'];
        yield 'zone non confirmée' => ['UPDATE event_zone SET confirmed_at = NULL WHERE id = UNHEX(:id)'];
    }

    #[DataProvider('unproposableZones')]
    public function testAZoneThatIsNoLongerProposableIsRefusedAndNothingIsWritten(string $update): void
    {
        $this->sql($update, $this->organizerZone->id()->toRfc4122());

        try {
            $this->deposit()->submitPublic($this->command(), $this->key());
            self::fail();
        } catch (DomainRuleViolation $e) {
            self::assertStringContainsString('proposée', $e->getMessage());
        }
        self::assertSame([0, 0, 0], [$this->rows('request_permit_request'), $this->rows('request_idempotency_key'), $this->rows('audit_log')]);
    }

    public function testForgedZoneIdentifiersAreRefusedUniformly(): void
    {
        $foreignCreator = UserBuilder::aUser()->build();
        $otherEvent = F::draft(MunicipalityBuilder::aMunicipality()->withInseeCode('44109')->build(), $foreignCreator, $this->event->type());
        $otherEvent->activate(F::now());
        $foreign = F::organizerParticipation($otherEvent);
        $foreignZone = F::readyZone($foreign);
        $this->save($otherEvent->organizerMunicipality(), $foreignCreator, $otherEvent, $foreign, $foreignZone->createdBy(), $foreignZone);

        foreach ([Uuid::v7()->toRfc4122(), $foreignZone->id()->toRfc4122(), 'pas-un-uuid'] as $forged) {
            try {
                $this->deposit()->submitPublic($this->command(['zoneIds' => [$this->organizerZone->id()->toRfc4122(), $forged]]), $this->key());
                self::fail($forged);
            } catch (DomainRuleViolation $e) {
                self::assertSame('Une zone demandée n\'est plus proposée.', $e->getMessage());
            }
        }
        self::assertSame(0, $this->rows('request_permit_request'));
    }

    public function testDuplicateAndExcessiveZoneListsAreRefused(): void
    {
        $zone = $this->organizerZone->id()->toRfc4122();
        foreach ([[$zone, $zone], array_map(static fn (): string => Uuid::v7()->toRfc4122(), range(1, RequestSubmissionService::MAX_ZONES + 1)), []] as $ids) {
            try {
                $this->deposit()->submitPublic($this->command(['zoneIds' => $ids]), $this->key());
                self::fail();
            } catch (DomainRuleViolation) {
                $this->addToAssertionCount(1);
            }
        }
    }

    private function addSlot(string $date): EventZoneValidity
    {
        $zone = $this->em->find(EventZone::class, $this->organizerZone->id());
        $slot = EventZoneValidity::fromSlot($zone, ValiditySlot::punctual(T::date($date), T::time('09:00'), T::time('17:00')), F::now());
        $this->save($slot);

        return $slot;
    }

    private function assertZoneRefused(string $messagePart): void
    {
        $before = $this->rows('request_permit_request');
        try {
            $this->deposit()->submitPublic($this->command(), $this->key());
            self::fail('Le dépôt aurait dû être refusé.');
        } catch (DomainRuleViolation $e) {
            self::assertStringContainsString($messagePart, $e->getMessage());
        }
        self::assertSame($before, $this->rows('request_permit_request'));
    }

    public function testASpecificPeriodsZoneNeedsAValidSlotThatIsNotEntirelyExpired(): void
    {
        // Événement long (jusqu'en 2099) : le créneau doit être dans sa durée, la date du jour est la vraie horloge.
        $this->sql('UPDATE event_event SET ends_at = \'2099-01-01 00:00:00\'');
        $this->sql('UPDATE event_zone SET validity_mode = \'SPECIFIC_PERIODS\' WHERE id = UNHEX(:id)', $this->organizerZone->id()->toRfc4122());

        $this->assertZoneRefused('aucune période de validité');            // aucun créneau
        $this->addSlot('2026-06-12');                                       // entièrement expiré
        $this->assertZoneRefused('aucune période de validité');
        $future = $this->addSlot('2098-06-12');                             // futur, pas en cours : suffit
        self::assertFalse($this->deposit()->submitPublic($this->command(), $this->key())->replayed);

        $this->sql('UPDATE event_zone_validity SET deleted_at = NOW() WHERE id = UNHEX(:id)', $future->id()->toRfc4122());   // supprimé : ne compte plus
        $this->assertZoneRefused('aucune période de validité');
    }

    public function testASlotBeyondTheEventDurationIsNotAUsablePeriod(): void
    {
        $this->sql('UPDATE event_zone SET validity_mode = \'SPECIFIC_PERIODS\' WHERE id = UNHEX(:id)', $this->organizerZone->id()->toRfc4122());
        $this->addSlot('2098-06-12'); // l'événement de test se termine en 2026 : période hors durée, bornée à zéro

        $this->assertZoneRefused('aucune période de validité');
    }

    public function testAFailureAfterTheReferenceWasDrawnLeavesNoTraceNorGap(): void
    {
        try {
            $this->deposit()->submitPublic($this->command(['travelMode' => TravelMode::Vehicle, 'plates' => ['plaque étrangère']]), $this->key());
            self::fail();
        } catch (DomainRuleViolation) {
        }
        self::assertSame([0, 0, 0, 0, 0, 0], [
            $this->rows('request_permit_request'), $this->rows('request_permit_request_municipality'), $this->rows('request_permit_request_zone'),
            $this->rows('request_idempotency_key'), $this->rows('audit_log'), $this->rows('request_reference_counter'),
        ]);

        self::assertMatchesRegularExpression('/-0001$/', $this->deposit()->submitPublic($this->command(), $this->key())->reference);
    }

    public function testTheSameAttemptNeverCreatesTwoRequests(): void
    {
        $key = $this->key();
        $first = $this->deposit()->submitPublic($this->command(), $key);
        $again = $this->deposit()->submitPublic($this->command(), $key);

        self::assertFalse($first->replayed);
        self::assertTrue($again->replayed);
        self::assertSame([$first->requestId, $first->reference], [$again->requestId, $again->reference]);
        self::assertSame([1, 1, 1], [$this->rows('request_permit_request'), $this->rows('request_idempotency_key'), $this->rows('audit_log')]);

        $other = $this->deposit()->submitPublic($this->command(), $this->key());
        self::assertNotSame($first->reference, $other->reference);
        self::assertSame(2, $this->rows('request_permit_request'));
    }

    public function testAnIdempotencyKeyIsScopedToItsOriginAndActor(): void
    {
        $this->eventAroundNow();
        $key = $this->key();
        $public = $this->deposit()->submitPublic($this->command(), $key);
        $agent = $this->deposit()->issueDirectly($this->instructor, $this->organizer->id()->toRfc4122(), $this->command(), ApprovalDuration::untilClosure(), $key);

        self::assertFalse($agent->replayed);
        self::assertNotSame($public->requestId, $agent->requestId);
        self::assertSame(2, $this->rows('request_permit_request'));
    }

    public function testAMalformedIdempotencyKeyIsRefused(): void
    {
        $this->expectException(DomainRuleViolation::class);

        $this->deposit()->submitPublic($this->command(), 'court');
    }

    /**
     * @param list<array{string, string}> $files contenu et nom d'origine
     *
     * @return list<AttachmentReference>
     */
    private function upload(array $files): array
    {
        $references = [];
        foreach ($files as [$content, $name]) {
            $stored = $this->service(TemporaryUploadService::class)->store(S::temporaryFile($content), $name);
            $references[] = new AttachmentReference($stored->temporaryKey, $name);
        }

        return $references;
    }

    private function storage(): FileStorageInterface
    {
        return $this->service(FileStorageInterface::class);
    }

    public function testAttachmentsAreVerifiedPromotedAndRecordedWithTheirRealMetadata(): void
    {
        $references = $this->upload([[S::pdf(), 'attestation.pdf'], [S::png(), 'photo.png']]);

        $this->deposit()->submitPublic($this->command(['attachments' => $references]), $this->key());

        $rows = $this->em->getConnection()->fetchAllAssociative('SELECT original_name, mime_type, size_bytes, storage_key FROM request_permit_request_attachment ORDER BY original_name');
        self::assertSame(['attestation.pdf', 'application/pdf', \strlen(S::pdf())], [$rows[0]['original_name'], $rows[0]['mime_type'], (int) $rows[0]['size_bytes']]);
        self::assertSame(['photo.png', 'image/png', \strlen(S::png())], [$rows[1]['original_name'], $rows[1]['mime_type'], (int) $rows[1]['size_bytes']]);
        foreach ($rows as $row) {
            self::assertMatchesRegularExpression('/^files\/[a-f0-9]{32}$/', $row['storage_key']);
            self::assertTrue($this->storage()->exists($row['storage_key']), 'aucun dossier ne pointe vers un fichier absent');
        }
        // Les copies temporaires ne servent plus après le dépôt.
        foreach ($references as $reference) {
            self::assertFalse($this->storage()->exists($reference->temporaryKey));
        }
        self::assertStringNotContainsString('attestation', (string) json_encode($this->audits()), 'aucun nom de fichier dans l\'audit');
    }

    public function testAttachmentsAreOptional(): void
    {
        $this->deposit()->submitPublic($this->command(), $this->key());

        self::assertSame(0, $this->rows('request_permit_request_attachment'));
    }

    public function testANonCompliantOrMissingAttachmentRefusesTheWholeDepositBeforeAnyWrite(): void
    {
        $good = $this->upload([[S::pdf(), 'ok.pdf']]);
        $missing = new AttachmentReference('tmp/'.str_repeat('a', 32), 'disparu.pdf');
        $forged = new AttachmentReference('../../etc/passwd', 'passwd.pdf');
        // Un fichier valide à l'envoi dont le contenu a ensuite été altéré en zone temporaire n'est jamais cru sur parole.
        $tampered = $this->upload([[S::pdf(), 'altere.pdf']])[0];
        $this->tamper($tampered->temporaryKey, 'plus du tout un PDF');
        $tooMany = $this->upload(array_map(static fn (int $i): array => [S::pdf(), 'p'.$i.'.pdf'], range(1, 6)));

        foreach ([[...$good, $missing], [...$good, $forged], [...$good, $tampered], [...$good, $good[0]], $tooMany] as $attachments) {
            try {
                $this->deposit()->submitPublic($this->command(['attachments' => $attachments]), $this->key());
                self::fail('Dépôt accepté avec un justificatif non conforme.');
            } catch (DomainRuleViolation) {
                $this->addToAssertionCount(1);
            }
        }
        self::assertSame([0, 0, 0], [$this->rows('request_permit_request'), $this->rows('request_permit_request_attachment'), $this->rows('audit_log')]);
        self::assertTrue($this->storage()->exists($good[0]->temporaryKey), 'les fichiers temporaires restent disponibles pour une nouvelle tentative');
    }

    public function testFilesCreatedForAFailedDepositAreRemoved(): void
    {
        $references = $this->upload([[S::pdf(), 'a.pdf'], [S::jpeg(), 'b.jpg']]);
        // L'échec survient APRÈS le rattachement des fichiers (écriture de l'audit) : la transaction SQL est annulée.
        $failing = new RequestSubmissionService(
            $this->service(EventTransactions::class),
            $this->service(EventLocker::class),
            $this->service(ClockInterface::class),
            $this->service(MunicipalityContext::class),
            $this->service(EventParticipationRepository::class),
            $this->service(EventZoneRepository::class),
            $this->service(RequestReferentialRepository::class),
            $this->service(PermitRequestRepository::class),
            $this->service(RequestReferenceGenerator::class),
            $this->service(PhoneNumberNormalizer::class),
            new FailingAuditTrail($this->service(AuditTrail::class), 1),
            $this->storage(),
            $this->service(UploadedDocumentInspector::class),
            $this->service(ProposableZones::class),
            new \App\Request\Application\MunicipalDecisionApplier($this->service(AuditTrail::class), $this->service(\App\Event\Domain\EventZoneValidityRepository::class), new \App\Request\Infrastructure\NullApprovedPartListener()),
        );

        try {
            $failing->submitPublic($this->command(['attachments' => $references]), $this->key());
            self::fail();
        } catch (\RuntimeException) {
        }

        self::assertSame([0, 0], [$this->rows('request_permit_request'), $this->rows('request_permit_request_attachment')]);
        self::assertSame([], $this->permanentFiles(), 'aucun fichier définitif orphelin');
        foreach ($references as $reference) {
            self::assertTrue($this->storage()->exists($reference->temporaryKey), 'le citoyen peut réessayer sans renvoyer ses fichiers');
        }
    }

    public function testReplayingAnAttemptNeverTouchesTheFilesAgain(): void
    {
        $references = $this->upload([[S::pdf(), 'a.pdf']]);
        $key = $this->key();
        $first = $this->deposit()->submitPublic($this->command(['attachments' => $references]), $key);
        $files = $this->permanentFiles();

        // Les copies temporaires ont été supprimées : la répétition renvoie le dossier d'origine, sans erreur ni nouvelle pièce.
        $again = $this->deposit()->submitPublic($this->command(['attachments' => $references]), $key);

        self::assertTrue($again->replayed);
        self::assertSame($first->reference, $again->reference);
        self::assertSame($files, $this->permanentFiles());
        self::assertSame(1, $this->rows('request_permit_request_attachment'));
    }

    public function testThePurgeCommandRemovesExpiredUploadsButNeverTheAttachmentsOfASubmittedRequest(): void
    {
        $attached = $this->upload([[S::pdf(), 'rattache.pdf']]);
        $this->deposit()->submitPublic($this->command(['attachments' => $attached]), $this->key());
        $permanent = $this->permanentFiles();
        $orphan = $this->upload([[S::png(), 'abandonne.png']])[0];
        $recent = $this->upload([[S::png(), 'recent.png']])[0];
        $root = self::getContainer()->getParameter('kernel.project_dir').'/var/storage_test';
        touch($root.'/'.$orphan->temporaryKey, time() - 25 * 3600);
        foreach ($permanent as $file) {
            touch($root.'/files/'.$file, time() - 400 * 24 * 3600);
        }

        $tester = new \Symfony\Component\Console\Tester\CommandTester((new \Symfony\Bundle\FrameworkBundle\Console\Application(self::$kernel))->find('app:request-uploads:purge'));
        self::assertSame(0, $tester->execute([]));

        self::assertStringContainsString('1 fichier(s)', $tester->getDisplay());
        self::assertFalse($this->storage()->exists($orphan->temporaryKey));
        self::assertTrue($this->storage()->exists($recent->temporaryKey));
        self::assertSame($permanent, $this->permanentFiles());
        $key = $this->em->getConnection()->fetchOne('SELECT storage_key FROM request_permit_request_attachment');
        self::assertTrue($this->storage()->exists($key));
    }

    /**
     * @return list<string>
     */
    private function permanentFiles(): array
    {
        $directory = self::getContainer()->getParameter('kernel.project_dir').'/var/storage_test/files';

        return is_dir($directory) ? array_values(array_diff((array) scandir($directory), ['.', '..'])) : [];
    }

    private function tamper(string $temporaryKey, string $content): void
    {
        file_put_contents(self::getContainer()->getParameter('kernel.project_dir').'/var/storage_test/'.$temporaryKey, $content);
    }
}
