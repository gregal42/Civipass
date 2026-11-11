<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Event\Application\Service\EventLifecycleService;
use App\Identity\Domain\User;
use App\Municipality\Domain\Municipality;
use App\Municipality\Domain\MunicipalityRole as Role;
use App\Permit\Application\PermitAccessService;
use App\Request\Application\Command\AttachmentReference;
use App\Request\Application\Command\SubmitRequestCommand;
use App\Request\Application\MunicipalInstructionService;
use App\Request\Application\RequestSubmissionService;
use App\Request\Application\TemporaryUploadService;
use App\Request\Domain\ApprovalDuration;
use App\Request\Domain\TravelMode;
use App\Tests\Support\SampleFiles as S;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Uid\Uuid;

/**
 * Scénario partagé des tests de clôture (LOT 05.6.2) : deux communes, un dossier approuvé par les deux, un justificatif, un lien bénéficiaire ; une
 * commune étrangère sans lien avec l'événement. Les rôles sont ceux de la production (INSTRUCTOR, EVENT_MANAGER, MUNICIPALITY_ADMIN).
 */
#[Group('requires-request-schema')]
abstract class ClosedEventScenarioTestCase extends PublicRequestTestCase
{
    protected const SENTINELS = ['Martin', 'Camille', 'GH-482-KT', 'camille.secret@exemple.fr', 'attestation', 'secret.pdf'];

    protected User $instructor;
    protected User $manager;
    protected User $admin;
    protected User $partnerInstructor;
    protected User $outsider;
    protected string $organizerId;
    protected string $partnerId;
    protected string $outsiderCommuneId;
    protected string $requestId;
    protected string $attachmentId;
    protected string $linkSecret;
    protected string $linkId;

    protected function setUp(): void
    {
        parent::setUp();
        if (!$this->em()->getConnection()->createSchemaManager()->tablesExist(['permit_revision'])) {
            self::markTestSkipped('Schéma des révisions non migré (LOT 05.5.1).');
        }
        $organizer = $this->em()->find(Municipality::class, $this->organizerParticipation->municipality()->id()) ?? self::fail('Commune absente.');
        $partner = $this->em()->find(Municipality::class, $this->partnerParticipation->municipality()->id()) ?? self::fail('Commune absente.');
        $other = MunicipalityBuilder::aMunicipality()->withInseeCode('33063')->build();
        $this->organizerId = $organizer->id()->toRfc4122();
        $this->partnerId = $partner->id()->toRfc4122();
        $this->outsiderCommuneId = $other->id()->toRfc4122();
        [$this->instructor, $this->manager, $this->admin, $this->partnerInstructor, $this->outsider] = array_map(static fn (): User => UserBuilder::aUser()->build(), range(1, 5));
        $this->save(
            $other,
            $this->instructor,
            $this->manager,
            $this->admin,
            $this->partnerInstructor,
            $this->outsider,
            MembershipBuilder::aMembership($this->instructor, $organizer)->withRoles(Role::Instructor)->build(),
            MembershipBuilder::aMembership($this->manager, $organizer)->withRoles(Role::EventManager)->build(),
            MembershipBuilder::aMembership($this->admin, $organizer)->withRoles(Role::MunicipalityAdmin)->build(),
            MembershipBuilder::aMembership($this->partnerInstructor, $partner)->withRoles(Role::Instructor)->build(),
            MembershipBuilder::aMembership($this->outsider, $other)->withRoles(Role::Instructor)->build(),
        );
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->sql('UPDATE event_event SET starts_at = :s, ends_at = :e', ['s' => $now->modify('-1 day')->format('Y-m-d H:i:s'), 'e' => $now->modify('+10 days')->format('Y-m-d H:i:s')]);
        $stored = static::getContainer()->get(TemporaryUploadService::class)->store(S::temporaryFile(S::pdf()), 'attestation-secret.pdf');
        $this->requestId = static::getContainer()->get(RequestSubmissionService::class)->submitPublic(
            new SubmitRequestCommand($this->event->id()->toRfc4122(), 'Martin', 'Camille', '12 rue des Lilas', 'camille.secret@exemple.fr', '0611223344', 'private_person', 'home', null, TravelMode::Vehicle, ['GH-482-KT'], [$this->organizerZone->id()->toRfc4122(), $this->partnerZone->id()->toRfc4122()], true, null, [new AttachmentReference($stored->temporaryKey, 'attestation-secret.pdf')]),
            sprintf('barrier-%016d', random_int(1, 999999)),
        )->requestId;
        $instruction = static::getContainer()->get(MunicipalInstructionService::class);
        $instruction->approve($this->managed($this->instructor), $this->organizerId, $this->requestId, ApprovalDuration::untilClosure());
        $instruction->approve($this->managed($this->partnerInstructor), $this->partnerId, $this->requestId, ApprovalDuration::untilClosure());
        $this->attachmentId = Uuid::fromBinary((string) $this->em()->getConnection()->fetchOne('SELECT id FROM request_permit_request_attachment'))->toRfc4122();
        $link = static::getContainer()->get(PermitAccessService::class)->issue($this->managed($this->instructor), $this->organizerId, $this->requestId);
        [$this->linkSecret, $this->linkId] = [$link->secret, $link->linkId];
    }

    protected function managed(User $user): User
    {
        return $this->em()->find(User::class, $user->id()) ?? self::fail('Utilisateur absent.');
    }

    protected function as(User $user): void
    {
        $this->client->loginUser($this->managed($user));
    }

    protected function close(): void
    {
        static::getContainer()->get(EventLifecycleService::class)->close($this->managed($this->admin), $this->event->id()->toRfc4122());
        $this->em()->clear();
        self::assertSame('CLOSED', $this->em()->getConnection()->fetchOne('SELECT status FROM event_event'), 'clôture effective');
    }
}
