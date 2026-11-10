<?php

declare(strict_types=1);

namespace App\Tests\Functional\Permit;

use App\Event\Application\Command\UpdateEventCommand;
use App\Event\Application\Command\UpdateZoneCommand;
use App\Event\Application\Service\EventLifecycleService;
use App\Event\Application\Service\EventParticipationService;
use App\Event\Application\Service\EventZoneService;
use App\Event\Domain\Event;
use App\Event\Domain\EventParticipation;
use App\Event\Domain\EventZone;
use App\Identity\Domain\User;
use App\Municipality\Application\MunicipalityManager;
use App\Municipality\Domain\Municipality;
use App\Municipality\Domain\MunicipalityRole as Role;
use App\Municipality\Domain\MunicipalityStatus;
use App\Permit\Application\PermitAccessService;
use App\Permit\Application\PublicVerificationReader;
use App\Permit\Application\Read\PublicVerification;
use App\Permit\Application\Result\PublicVerificationOutcome;
use App\Permit\Domain\PublicVerdict;
use App\Permit\Domain\RightState;
use App\Request\Application\Command\CorrectIdentityCommand;
use App\Request\Application\Command\SubmitRequestCommand;
use App\Request\Application\MunicipalInstructionService;
use App\Request\Application\RefusalReasonInitializer;
use App\Request\Application\RequestIdentityCorrectionService;
use App\Request\Application\RequestSubmissionService;
use App\Request\Domain\ApprovalDuration;
use App\Request\Domain\TravelMode;
use App\Tests\Support\EventFixtures as F;
use App\Tests\Support\MembershipBuilder;
use App\Tests\Support\MunicipalityBuilder;
use App\Tests\Support\PublicRequestTestCase;
use App\Tests\Support\UserBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Recette automatisée du contrôle public (LOT 05.4.4) : scénarios COMPLETS sur la page `/v/{jeton}` d'un laissez-passer de TROIS communes.
 * Chaque scénario : décision réelle, consultation du QR, VRAIE opération métier (services applicatifs, jamais du SQL), nouvelle consultation du
 * MÊME QR, vérification de l'évolution exacte des droits (jeton, communes, zones, périodes, plaques, statut global, aucune donnée interdite).
 *
 * Les opérations de la liste du LOT 05.4.4 qui n'existent pas en V1 (retrait ou ajout de zone d'une autorisation, révocation d'une
 * autorisation, prolongation d'une autorisation, plaques, remplacement du jeton) ne sont PAS simulées ici : voir docs/exploitation-verification-publique.md.
 * L'interrupteur est activé ICI, dans un environnement de test isolé ; il est désactivé partout ailleurs.
 */
#[Group('requires-request-schema')]
final class PublicVerificationScenariosTest extends PublicRequestTestCase
{
    private const ADDRESS = '12 rue SENTINELLE des Lilas';
    private const EMAIL = 'sentinelle.secrete@exemple.test';
    private const PHONE = '0611223344';
    private const MOTIF_DETAIL = 'SENTINELLE-MOTIF-DETAIL';
    private const ORGANIZER_LABEL = 'Zone organisatrice nord';
    private const PARTNER_LABEL = 'Zone partenaire centre';
    private const THIRD_LABEL = 'Zone troisième commune';
    private const PLATES = ['GH-482-KT', 'AB-123-CD'];

    private ?string $previousSwitch = null;
    private Municipality $third;
    private EventParticipation $thirdParticipation;
    private EventZone $thirdZone;
    /** @var array<string, User> agent instructeur par identifiant de commune */
    private array $instructors = [];
    /** @var array<string, User> administrateur par identifiant de commune */
    private array $admins = [];
    private string $requestId;

    protected function setUp(): void
    {
        $this->previousSwitch = $_SERVER['PUBLIC_VERIFICATION_ENABLED'] ?? null;
        self::switchTo('1');
        parent::setUp();
        $organizer = $this->municipality($this->organizerParticipation);
        $partner = $this->municipality($this->partnerParticipation);
        $this->third = MunicipalityBuilder::aMunicipality()->withInseeCode('33063')->build();
        $creator = UserBuilder::aUser()->build();
        $this->thirdParticipation = F::invited($this->em()->find(Event::class, $this->event->id()) ?? self::fail('Événement absent.'), $this->em()->find(Municipality::class, $this->third->id()) ?? $this->third, $creator);
        $this->thirdParticipation->accept(F::now());
        $this->thirdZone = F::readyZone($this->thirdParticipation);
        $this->save($this->third, $creator, $this->thirdParticipation, $this->thirdZone->createdBy(), $this->thirdZone);
        foreach ([$organizer, $partner, $this->em()->find(Municipality::class, $this->third->id()) ?? self::fail()] as $commune) {
            $id = $commune->id()->toRfc4122();
            $instructor = UserBuilder::aUser()->build();
            $admin = UserBuilder::aUser()->build();
            $this->save(
                $instructor,
                $admin,
                MembershipBuilder::aMembership($instructor, $commune)->withRoles(Role::Instructor)->build(),
                MembershipBuilder::aMembership($admin, $commune)->withRoles(Role::MunicipalityAdmin, Role::EventManager)->build(),
            );
            $this->instructors[$id] = $instructor;
            $this->admins[$id] = $admin;
        }
        foreach ([[$this->organizerZone, self::ORGANIZER_LABEL], [$this->partnerZone, self::PARTNER_LABEL], [$this->thirdZone, self::THIRD_LABEL]] as [$zone, $label]) {
            $this->sql('UPDATE event_zone SET label = :l WHERE id = :id', ['l' => $label, 'id' => $zone->id()->toBinary()]);
        }
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->sql('UPDATE event_event SET starts_at = :s, ends_at = :e', ['s' => $now->modify('-1 day')->format('Y-m-d H:i:s'), 'e' => $now->modify('+10 days')->format('Y-m-d H:i:s')]);
        $this->requestId = static::getContainer()->get(RequestSubmissionService::class)->submitPublic(
            new SubmitRequestCommand(
                $this->event->id()->toRfc4122(),
                'Martin',
                'Camille',
                self::ADDRESS,
                self::EMAIL,
                self::PHONE,
                'private_person',
                'other',
                self::MOTIF_DETAIL,
                TravelMode::Vehicle,
                self::PLATES,
                [$this->organizerZone->id()->toRfc4122(), $this->partnerZone->id()->toRfc4122(), $this->thirdZone->id()->toRfc4122()],
                true,
            ),
            sprintf('scenario-%016d', random_int(1, 999999)),
        )->requestId;
    }

    protected function tearDown(): void
    {
        self::switchTo($this->previousSwitch ?? '0');
        parent::tearDown();
    }

    private static function switchTo(string $value): void
    {
        $_SERVER['PUBLIC_VERIFICATION_ENABLED'] = $_ENV['PUBLIC_VERIFICATION_ENABLED'] = $value;
        putenv('PUBLIC_VERIFICATION_ENABLED='.$value);
    }

    private function municipality(EventParticipation $participation): Municipality
    {
        return $this->em()->find(Municipality::class, $participation->municipality()->id()) ?? self::fail('Commune absente.');
    }

    private function managed(User $user): User
    {
        return $this->em()->find(User::class, $user->id()) ?? self::fail('Utilisateur absent.');
    }

    private function instruction(): MunicipalInstructionService
    {
        return static::getContainer()->get(MunicipalInstructionService::class);
    }

    private function approve(Municipality|EventParticipation $commune, ?ApprovalDuration $duration = null): void
    {
        $id = ($commune instanceof EventParticipation ? $commune->municipality() : $commune)->id()->toRfc4122();
        $this->instruction()->approve($this->managed($this->instructors[$id]), $id, $this->requestId, $duration ?? ApprovalDuration::untilClosure());
    }

    private function approveAll(): void
    {
        foreach ([$this->organizerParticipation, $this->partnerParticipation, $this->thirdParticipation] as $participation) {
            $this->approve($participation);
        }
    }

    private function adminOf(EventParticipation $participation): User
    {
        return $this->managed($this->admins[$participation->municipality()->id()->toRfc4122()]);
    }

    private function token(): string
    {
        return (string) $this->em()->getConnection()->fetchOne('SELECT token FROM permit_verification_token');
    }

    private function open(): string
    {
        $this->client->request('GET', '/v/'.$this->token());
        self::assertResponseStatusCodeSame(200);

        return (string) $this->client->getResponse()->getContent();
    }

    private function verification(): PublicVerification
    {
        $this->em()->clear();
        $lookup = static::getContainer()->get(PublicVerificationReader::class)->find($this->token(), new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        self::assertSame(PublicVerificationOutcome::Found, $lookup->outcome);

        return $lookup->verification ?? self::fail('Vérification absente.');
    }

    /**
     * États de zone par libellé de commune.
     *
     * @return array<string, list<RightState>>
     */
    private function statesByCommune(PublicVerification $v): array
    {
        $states = [];
        foreach ($v->authorizations as $authorization) {
            foreach ($authorization->zones as $zone) {
                $states[$authorization->municipalityName][] = $zone->state;
            }
        }

        return $states;
    }

    private function communeName(EventParticipation $participation): string
    {
        return $this->municipality($participation)->name();
    }

    /** Aucune donnée interdite, jamais, dans la page. */
    private function assertNothingForbidden(string $html, string $context): void
    {
        foreach ([self::ADDRESS, self::EMAIL, self::PHONE, self::MOTIF_DETAIL, 'SENTINELLE', $this->token(), $this->requestId] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $html, $context.' : donnée interdite '.$forbidden);
        }
    }

    // ------------------------------------------------------------------ trois communes, approbations successives

    public function testEachNewCommuneApprovalAddsItsRubricToTheSameQrAndNothingElseChanges(): void
    {
        $this->approve($this->organizerParticipation);
        $token = $this->token();
        $first = $this->open();
        self::assertStringContainsString('1 zone active sur 1', $first);
        self::assertStringContainsString($this->communeName($this->organizerParticipation), $first);
        self::assertStringNotContainsString($this->communeName($this->partnerParticipation), $first, 'une commune qui n\'a pas décidé n\'apparaît pas');
        self::assertStringNotContainsString($this->communeName($this->thirdParticipation), $first);

        $this->approve($this->partnerParticipation);
        $second = $this->open();
        self::assertStringContainsString('2 zones actives sur 2', $second);
        self::assertStringContainsString($this->communeName($this->partnerParticipation), $second);
        self::assertStringNotContainsString($this->communeName($this->thirdParticipation), $second);

        $this->approve($this->thirdParticipation);
        $third = $this->open();

        self::assertSame($token, $this->token(), 'le QR (jeton) ne change jamais quand une commune approuve');
        self::assertSame(1, $this->rows('permit_permit'));
        self::assertSame(3, $this->rows('permit_authorization'));
        foreach ([$this->organizerParticipation, $this->partnerParticipation, $this->thirdParticipation] as $participation) {
            self::assertStringContainsString($this->communeName($participation), $third);
        }
        foreach ([self::ORGANIZER_LABEL, self::PARTNER_LABEL, self::THIRD_LABEL] as $label) {
            self::assertStringContainsString($label, $third);
        }
        self::assertStringContainsString('3 zones actives sur 3', $third);
        self::assertStringContainsString('Droits actifs', $third);
        self::assertStringContainsString('cp-verdict--valid', $third);
        self::assertSame(3, substr_count($third, 'Valide actuellement'), 'une ligne « valide » par zone');
        foreach (self::PLATES as $plate) {
            self::assertStringContainsString($plate, $third);
        }
        self::assertStringContainsString('Camille MARTIN', $third);
        $this->assertNothingForbidden($third, 'trois communes');
        self::assertSame(1, substr_count($third, 'Camille MARTIN'), 'le bénéficiaire n\'est écrit qu\'une fois');
    }

    public function testACommuneThatRefusesNeverAppearsOnThePage(): void
    {
        $this->approve($this->organizerParticipation);
        $this->approve($this->partnerParticipation);
        static::getContainer()->get(RefusalReasonInitializer::class)->initialize();
        $thirdId = $this->third->id()->toRfc4122();
        $this->instruction()->refuse($this->managed($this->instructors[$thirdId]), $thirdId, $this->requestId, 'duplicate', null);

        $html = $this->open();

        self::assertStringNotContainsString($this->communeName($this->thirdParticipation), $html);
        self::assertStringNotContainsString(self::THIRD_LABEL, $html);
        self::assertStringContainsString('2 zones actives sur 2', $html);
        $this->assertNothingForbidden($html, 'refus');
    }

    // ------------------------------------------------------------------ opérations réelles sur l'événement

    public function testSuspendingThenReactivatingTheEventIsFollowedByTheSameQr(): void
    {
        $this->approveAll();
        $token = $this->token();
        self::assertStringContainsString('3 zones actives sur 3', $this->open());
        $organizer = $this->adminOf($this->organizerParticipation);

        static::getContainer()->get(EventLifecycleService::class)->suspend($organizer, $this->event->id()->toRfc4122(), 'Alerte levée provisoirement');
        $suspended = $this->open();
        $v = $this->verification();

        self::assertStringContainsString('0 zones actives sur 3', $suspended);
        self::assertStringNotContainsString('cp-verdict--valid', $suspended);
        self::assertNotSame(PublicVerdict::ActiveRights, $v->verdict);
        self::assertSame(0, $v->activeZoneCount);
        self::assertSame(3, $v->zoneCount, 'les zones restent listées');
        foreach ($this->statesByCommune($v) as $states) {
            self::assertSame([RightState::EventNotActive], $states);
        }

        static::getContainer()->get(EventLifecycleService::class)->reactivate($this->adminOf($this->organizerParticipation), $this->event->id()->toRfc4122());
        $back = $this->open();

        self::assertSame($token, $this->token());
        self::assertStringContainsString('3 zones actives sur 3', $back);
        self::assertStringContainsString('cp-verdict--valid', $back);
        $this->assertNothingForbidden($back, 'réactivation');
    }

    public function testClosingTheEventReplacesThePageByANeutralStatusWithNoPersonalDataAndKeepsTheQr(): void
    {
        $this->approveAll();
        $token = $this->token();
        $before = $this->verification();
        self::assertSame(PublicVerdict::ActiveRights, $before->verdict);

        static::getContainer()->get(EventLifecycleService::class)->close($this->adminOf($this->organizerParticipation), $this->event->id()->toRfc4122());
        $html = $this->open();

        self::assertSame($token, $this->token(), 'la clôture ne change pas le QR');
        self::assertStringContainsString('>Événement terminé<', $html);
        foreach (['zones actives', 'Valide actuellement', 'cp-verdict', ...self::PLATES, 'Martin', 'MARTIN', 'Camille', $this->organizerParticipation->municipality()->name(), $this->partnerParticipation->municipality()->name()] as $leak) {
            self::assertStringNotContainsString($leak, $html, 'après clôture : '.$leak);
        }
        $this->assertNothingForbidden($html, 'clôture');
        $this->em()->clear();
        $lookup = static::getContainer()->get(PublicVerificationReader::class)->find($token, new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        self::assertSame(PublicVerificationOutcome::EventClosed, $lookup->outcome);
        self::assertNull($lookup->verification);
    }

    public function testExtendingTheEventEndIsFollowedByTheRightsOfAnUntilClosureAuthorization(): void
    {
        $this->approveAll();
        $before = $this->verification();
        $oldEnd = $this->latestEnd($before);

        $newEnd = $oldEnd->modify('+10 days');
        static::getContainer()->get(EventLifecycleService::class)->update(
            $this->adminOf($this->organizerParticipation),
            $this->event->id()->toRfc4122(),
            new UpdateEventCommand(endsAt: $newEnd),
        );
        $after = $this->verification();
        $html = $this->open();

        self::assertEquals($newEnd, $this->latestEnd($after), 'la fin de chaque période suit la nouvelle date de fin de l\'événement');
        self::assertSame(PublicVerdict::ActiveRights, $after->verdict);
        self::assertStringContainsString('3 zones actives sur 3', $html);
        $this->assertNothingForbidden($html, 'prolongation');

        $inTwoDays = new \DateTimeImmutable('+2 days', new \DateTimeZone('UTC'));
        $shortened = $inTwoDays->setTime((int) $inTwoDays->format('H'), (int) $inTwoDays->format('i'), 0);
        static::getContainer()->get(EventLifecycleService::class)->update($this->adminOf($this->organizerParticipation), $this->event->id()->toRfc4122(), new UpdateEventCommand(endsAt: $shortened));
        self::assertEquals($shortened, $this->latestEnd($this->verification()), 'un raccourcissement est suivi lui aussi');
    }

    private function latestEnd(PublicVerification $v): \DateTimeImmutable
    {
        $ends = [];
        foreach ($v->authorizations as $authorization) {
            foreach ($authorization->zones as $zone) {
                foreach ($zone->periods as $period) {
                    $ends[] = $period->until ?? self::fail('Période sans fin.');
                }
            }
        }
        self::assertNotSame([], $ends);

        return max($ends);
    }

    // ------------------------------------------------------------------ opérations réelles sur une commune

    /**
     * @return iterable<string, array{MunicipalityStatus, RightState}>
     */
    public static function communeStatuses(): iterable
    {
        yield 'commune suspendue' => [MunicipalityStatus::Suspended, RightState::CommuneInactive];
        yield 'commune désactivée' => [MunicipalityStatus::Inactive, RightState::CommuneInactive];
    }

    #[DataProvider('communeStatuses')]
    public function testASuspendedOrInactiveCommuneLosesOnlyItsOwnRightsAndThenRecovers(MunicipalityStatus $status, RightState $expected): void
    {
        $this->approveAll();
        $token = $this->token();
        $partnerName = $this->communeName($this->partnerParticipation);
        $partner = $this->municipality($this->partnerParticipation);
        $root = UserBuilder::aUser()->superAdmin()->build();
        $this->save($root);
        $manager = static::getContainer()->get(MunicipalityManager::class);

        $manager->changeStatus($partner, $status, $this->managed($root));
        $html = $this->open();
        $v = $this->verification();

        $states = $this->statesByCommune($v);
        self::assertSame([$expected], $states[$partnerName], 'seule la commune touchée perd ses droits');
        self::assertSame([RightState::Valid], $states[$this->communeName($this->organizerParticipation)]);
        self::assertSame([RightState::Valid], $states[$this->communeName($this->thirdParticipation)]);
        self::assertSame(PublicVerdict::ActiveRights, $v->verdict, 'des droits subsistent : statut global « actif », compteur partiel');
        self::assertStringContainsString('2 zones actives sur 3', $html);
        self::assertStringContainsString('cp-verdict--uncertain', $html);
        self::assertStringContainsString(self::PARTNER_LABEL, $html, 'la zone reste listée');
        self::assertSame($token, $this->token());

        $manager->changeStatus($this->municipality($this->partnerParticipation), MunicipalityStatus::Active, $this->managed($root));
        $back = $this->open();
        self::assertStringContainsString('3 zones actives sur 3', $back);
        self::assertStringContainsString('cp-verdict--valid', $back);
        $this->assertNothingForbidden($back, 'commune réactivée');
    }

    public function testSuspendingAParticipationThenClosingItAffectsOnlyThatCommune(): void
    {
        $this->approveAll();
        $partnerName = $this->communeName($this->partnerParticipation);
        $participations = static::getContainer()->get(EventParticipationService::class);

        $participations->suspend($this->adminOf($this->partnerParticipation), $this->partnerParticipation->id()->toRfc4122(), 'Moyens indisponibles');
        $suspended = $this->verification();
        self::assertSame([RightState::ParticipationInactive], $this->statesByCommune($suspended)[$partnerName]);
        self::assertSame(2, $suspended->activeZoneCount);
        self::assertStringContainsString('2 zones actives sur 3', $this->open());

        $participations->reactivate($this->adminOf($this->partnerParticipation), $this->partnerParticipation->id()->toRfc4122());
        self::assertSame(3, $this->verification()->activeZoneCount);

        $participations->close($this->adminOf($this->partnerParticipation), $this->partnerParticipation->id()->toRfc4122());
        $closed = $this->verification();
        self::assertSame([RightState::ParticipationInactive], $this->statesByCommune($closed)[$partnerName], 'une participation clôturée n\'ouvre plus de droit');
        self::assertSame(2, $closed->activeZoneCount);
        self::assertSame(PublicVerdict::ActiveRights, $closed->verdict);
    }

    public function testSuspendingAZoneIsFollowedAndReactivatingItGivesTheRightBack(): void
    {
        $this->approveAll();
        $zones = static::getContainer()->get(EventZoneService::class);

        $zones->suspend($this->adminOf($this->thirdParticipation), $this->thirdZone->id()->toRfc4122());
        $html = $this->open();
        $v = $this->verification();
        self::assertSame([RightState::ZoneSuspended], $this->statesByCommune($v)[$this->communeName($this->thirdParticipation)]);
        self::assertStringContainsString('2 zones actives sur 3', $html);
        self::assertStringContainsString('Zone suspendue', $html);

        $zones->reactivate($this->adminOf($this->thirdParticipation), $this->thirdZone->id()->toRfc4122());
        self::assertStringContainsString('3 zones actives sur 3', $this->open());
    }

    public function testRenamingAZoneNeverChangesTheLabelCopiedOnTheAuthorization(): void
    {
        $this->approveAll();
        $zones = static::getContainer()->get(EventZoneService::class);

        $zones->update($this->adminOf($this->organizerParticipation), $this->organizerZone->id()->toRfc4122(), new UpdateZoneCommand(label: 'Libellé renommé après décision'));
        $html = $this->open();

        self::assertStringContainsString(self::ORGANIZER_LABEL, $html, 'le libellé autorisé est celui du moment de la décision');
        self::assertStringNotContainsString('Libellé renommé après décision', $html);
    }

    // ------------------------------------------------------------------ opérations sur la demande et le lien bénéficiaire

    public function testCorrectingTheIdentityIsFollowedButPlatesAndTokenAreUntouched(): void
    {
        $this->approveAll();
        $token = $this->token();
        $organizerId = $this->organizerParticipation->municipality()->id()->toRfc4122();
        $version = (int) $this->em()->getConnection()->fetchOne('SELECT identity_version FROM request_permit_request WHERE id = UNHEX(:id)', ['id' => str_replace('-', '', $this->requestId)]);

        static::getContainer()->get(RequestIdentityCorrectionService::class)->correct(
            $this->managed($this->instructors[$organizerId]),
            $organizerId,
            $this->requestId,
            new CorrectIdentityCommand($version, 'Martin-Durand', 'Camille', self::ADDRESS, self::EMAIL, self::PHONE, true),
        );
        $html = $this->open();

        self::assertStringContainsString('Camille MARTIN-DURAND', $html);
        foreach (self::PLATES as $plate) {
            self::assertStringContainsString($plate, $html);
        }
        self::assertSame($token, $this->token());
        $this->assertNothingForbidden($html, 'correction d\'identité');
    }

    public function testRevokingTheBeneficiaryLinkDoesNotTouchTheQr(): void
    {
        $this->approveAll();
        $token = $this->token();
        $organizerId = $this->organizerParticipation->municipality()->id()->toRfc4122();
        $agent = $this->managed($this->instructors[$organizerId]);
        $access = static::getContainer()->get(PermitAccessService::class);
        $access->issue($agent, $organizerId, $this->requestId);
        $linkId = (string) $this->em()->getConnection()->fetchOne('SELECT LOWER(CONCAT_WS(\'-\', HEX(SUBSTR(id,1,4)), HEX(SUBSTR(id,5,2)), HEX(SUBSTR(id,7,2)), HEX(SUBSTR(id,9,2)), HEX(SUBSTR(id,11,6)))) FROM permit_access_token');

        $access->revoke($this->managed($this->instructors[$organizerId]), $organizerId, $this->requestId, $linkId);
        $html = $this->open();

        self::assertSame($token, $this->token(), 'révoquer le lien du bénéficiaire ne révoque ni ne remplace le QR');
        self::assertStringContainsString('3 zones actives sur 3', $html);
        $this->assertNothingForbidden($html, 'lien révoqué');
    }

    // ------------------------------------------------------------------ sécurité conservée à travers les opérations

    public function testEveryEvolutionKeepsTheProtectionsAndNeverSetsACookie(): void
    {
        $this->approveAll();
        $service = static::getContainer()->get(EventLifecycleService::class);
        $this->open();
        $service->suspend($this->adminOf($this->organizerParticipation), $this->event->id()->toRfc4122(), 'Pause');
        $this->client->request('GET', '/v/'.$this->token());

        $headers = $this->client->getResponse()->headers;
        self::assertSame('no-store, private', $headers->get('Cache-Control'));
        self::assertSame('no-referrer', $headers->get('Referrer-Policy'));
        self::assertSame([], $headers->getCookies(), 'aucun cookie, même après une opération métier');
        self::assertFalse($this->client->getRequest()->hasSession() && $this->client->getRequest()->getSession()->isStarted());
    }
}
