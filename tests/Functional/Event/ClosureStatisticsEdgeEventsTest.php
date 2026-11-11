<?php

declare(strict_types=1);

namespace App\Tests\Functional\Event;

use App\Event\Application\ClosureStatisticsReader;
use App\Event\Application\Service\EventLifecycleService;
use App\Event\Domain\ClosureStatistics;
use App\Identity\Domain\User;
use App\Municipality\Domain\Municipality;
use App\Municipality\Domain\MunicipalityRole as Role;
use App\Request\Application\Command\SubmitRequestCommand;
use App\Request\Application\MunicipalInstructionService;
use App\Request\Application\RequestSubmissionService;
use App\Request\Domain\ApprovalDuration;
use App\Request\Domain\TravelMode;
use App\Tests\Support\MembershipBuilder;
use App\Tests\Support\PublicRequestTestCase;
use App\Tests\Support\UserBuilder;
use PHPUnit\Framework\Attributes\Group;

/**
 * Bilan de clôture des cas limites (LOT 05.6.2) : événement SANS demande, événement à UNE commune, dossiers refusés et non décidés conservés avec leur statut réel.
 */
#[Group('requires-request-schema')]
final class ClosureStatisticsEdgeEventsTest extends PublicRequestTestCase
{
    private User $admin;
    private User $instructor;
    private string $organizerId;
    private string $partnerId;

    protected function setUp(): void
    {
        parent::setUp();
        $organizer = $this->em()->find(Municipality::class, $this->organizerParticipation->municipality()->id()) ?? self::fail('Commune absente.');
        $this->organizerId = $organizer->id()->toRfc4122();
        $this->partnerId = $this->partnerParticipation->municipality()->id()->toRfc4122();
        [$this->admin, $this->instructor] = [UserBuilder::aUser()->build(), UserBuilder::aUser()->build()];
        $this->save(
            $this->admin,
            $this->instructor,
            MembershipBuilder::aMembership($this->admin, $organizer)->withRoles(Role::MunicipalityAdmin)->build(),
            MembershipBuilder::aMembership($this->instructor, $organizer)->withRoles(Role::Instructor)->build(),
        );
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->sql('UPDATE event_event SET starts_at = :s, ends_at = :e', ['s' => $now->modify('-1 day')->format('Y-m-d H:i:s'), 'e' => $now->modify('+10 days')->format('Y-m-d H:i:s')]);
    }

    private function managed(User $user): User
    {
        return $this->em()->find(User::class, $user->id()) ?? self::fail('Utilisateur absent.');
    }

    private function deposit(string $suffix, bool $partnerZone): string
    {
        $zones = [$this->organizerZone->id()->toRfc4122()];
        if ($partnerZone) {
            $zones[] = $this->partnerZone->id()->toRfc4122();
        }

        return static::getContainer()->get(RequestSubmissionService::class)->submitPublic(
            new SubmitRequestCommand($this->event->id()->toRfc4122(), 'Edge'.$suffix, 'Zoé', null, 'edge'.$suffix.'@exemple.fr', null, 'private_person', 'home', null, TravelMode::Vehicle, [sprintf('ED-%03d-GE', random_int(100, 999))], $zones, true),
            sprintf('edge-%s-%012d', $suffix, random_int(1, 99999999)),
        )->requestId;
    }

    private function close(): ClosureStatistics
    {
        static::getContainer()->get(EventLifecycleService::class)->close($this->managed($this->admin), $this->event->id()->toRfc4122());
        $this->em()->clear();
        $raw = $this->em()->getConnection()->fetchOne('SELECT closure_statistics FROM event_event');
        self::assertIsString($raw);

        return ClosureStatistics::fromArray(json_decode($raw, true, 512, \JSON_THROW_ON_ERROR));
    }

    public function testAnEventWithoutAnyRequestRecordsZeroCountersAndPublishesZeros(): void
    {
        $stored = $this->close();

        self::assertSame(0, $stored->requests);
        foreach ($stored->municipalities as $row) {
            self::assertSame(0, $row->dossiers());
        }
        self::assertCount(2, $stored->municipalities, 'organisatrice et partenaire (acceptée) ; la commune invitée jamais acceptée n\'est pas comptée');
        $view = static::getContainer()->get(ClosureStatisticsReader::class)->forViewer($this->managed($this->admin), $this->organizerId, $this->event->id()->toRfc4122());
        self::assertSame(0, $view?->global?->dossiers, 'zéro publié : ce n\'est pas une observation individuelle');
        self::assertNull(static::getContainer()->get(ClosureStatisticsReader::class)->forViewer($this->managed($this->instructor), $this->organizerId, $this->event->id()->toRfc4122()));
    }

    public function testAnEventWithOneMunicipalityPublishesItsStatusBreakdownWhenEveryCellIsPublishable(): void
    {
        $instruction = static::getContainer()->get(MunicipalInstructionService::class);
        $approvals = 0;
        for ($i = 0; $i < 6; ++$i) {
            $id = $this->deposit('one'.$i, false);
            $instruction->approve($this->managed($this->instructor), $this->organizerId, $id, ApprovalDuration::untilClosure());
            ++$approvals;
        }
        // Le partenaire n'a aucun dossier ; on le retire de l'événement en le laissant à zéro : l'événement reste « à une commune » pour les dossiers.
        $stored = $this->close();

        self::assertSame($approvals, $stored->requests);
        $organizer = array_values(array_filter($stored->municipalities, fn ($m): bool => $m->municipalityId === $this->organizerId))[0];
        self::assertSame([6, 0, 0, 6], [$organizer->approved, $organizer->refused, $organizer->pending, $organizer->authorizations]);
        $partner = array_values(array_filter($stored->municipalities, fn ($m): bool => $m->municipalityId === $this->partnerId))[0];
        self::assertSame(0, $partner->dossiers());
        $view = static::getContainer()->get(ClosureStatisticsReader::class)->forViewer($this->managed($this->admin), $this->organizerId, $this->event->id()->toRfc4122());
        self::assertSame(6, $view?->global?->dossiers, 'le partenaire à zéro dossier est publiable (zéro) : le total est lisible');
        self::assertSame([6, 0], array_column($view->global->communes ?? [], 'dossiers'));
    }

    public function testRefusedAndUndecidedDossiersAreCountedWithTheirRealStatus(): void
    {
        $instruction = static::getContainer()->get(MunicipalInstructionService::class);
        $approved = $this->deposit('a', false);
        $refused = $this->deposit('r', false);
        $this->deposit('p', false); // jamais décidée
        $instruction->approve($this->managed($this->instructor), $this->organizerId, $approved, ApprovalDuration::untilClosure());
        static::getContainer()->get(\App\Request\Application\RefusalReasonInitializer::class)->initialize();
        $instruction->refuse($this->managed($this->instructor), $this->organizerId, $refused, 'reason_not_justified', 'Justificatif manquant.');

        $stored = $this->close();

        $organizer = array_values(array_filter($stored->municipalities, fn ($m): bool => $m->municipalityId === $this->organizerId))[0];
        self::assertSame([1, 1, 1, 1], [$organizer->approved, $organizer->refused, $organizer->pending, $organizer->authorizations], 'la demande non décidée est conservée avec son statut réel');
        self::assertSame(3, $stored->requests);
    }
}
