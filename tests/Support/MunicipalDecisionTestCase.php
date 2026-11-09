<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Event\Application\Support\EventLocker;
use App\Event\Application\Support\EventTransactions;
use App\Event\Domain\Event;
use App\Event\Domain\EventParticipation;
use App\Event\Domain\EventZone;
use App\Event\Domain\EventZoneValidityRepository;
use App\Identity\Domain\User;
use App\Municipality\Application\MunicipalityContext;
use App\Municipality\Domain\Municipality;
use App\Municipality\Domain\MunicipalityRole as Role;
use App\Request\Application\ApprovedPartListener;
use App\Request\Application\Command\SubmitRequestCommand;
use App\Request\Application\MunicipalDecisionApplier;
use App\Request\Application\MunicipalInstructionService;
use App\Request\Application\RefusalReasonInitializer;
use App\Request\Application\RequestReferentialInitializer;
use App\Request\Application\RequestSubmissionService;
use App\Request\Domain\PermitRequestRepository;
use App\Request\Domain\TravelMode;
use App\Request\Infrastructure\DoctrineRefusalReasonRepository;
use App\Shared\Application\AuditTrail;
use App\Tests\Support\EventFixtures as F;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;

/**
 * Décor des tests du moteur de décision municipale (LOT 04.1) : un événement actif du 10 au 20 juin 2026 (heure de Paris), une
 * commune organisatrice et une commune partenaire (zones confirmées, durée de l'événement), des agents aux rôles distincts, et
 * une horloge figée au 12 juin 2026 09:00 UTC pour des durées déterministes. Les services de décision sont assemblés ici (le
 * conteneur retire les services non encore utilisés par l'application) avec les VRAIS dépôts et le VRAI audit.
 */
abstract class MunicipalDecisionTestCase extends RequestDepositTestCase
{
    protected Event $event;
    protected Municipality $organizer;
    protected Municipality $partner;
    protected Municipality $outsider;
    protected EventParticipation $organizerParticipation;
    protected EventParticipation $partnerParticipation;
    protected EventZone $organizerZone;
    protected EventZone $partnerZone;
    protected User $instructor;
    protected User $partnerInstructor;
    protected User $outsiderInstructor;
    protected User $adminOnly;
    protected User $managerOnly;
    protected MockClock $clock;
    private int $keys = 0;

    protected function setUp(): void
    {
        parent::setUp();
        if (!$this->em->getConnection()->createSchemaManager()->tablesExist(['request_permit_request_decision', 'request_refusal_reason'])) {
            self::markTestSkipped('Schéma de décision non migré : test exécutable après `make test-db` (LOT 04, incrément 04.1).');
        }
        $this->clock = new MockClock('2026-06-12 09:00:00 UTC');
        $this->service(RequestReferentialInitializer::class)->initialize();
        (new RefusalReasonInitializer(new DoctrineRefusalReasonRepository($this->em), $this->service(\App\Shared\Application\TransactionRunner::class)))->initialize();
        $this->organizer = MunicipalityBuilder::aMunicipality()->build();
        $this->partner = MunicipalityBuilder::aMunicipality()->withInseeCode('33527')->build();
        $this->outsider = MunicipalityBuilder::aMunicipality()->withInseeCode('33063')->build();
        $creator = UserBuilder::aUser()->build();
        $this->instructor = UserBuilder::aUser()->build();
        $this->partnerInstructor = UserBuilder::aUser()->build();
        $this->outsiderInstructor = UserBuilder::aUser()->build();
        $this->adminOnly = UserBuilder::aUser()->build();
        $this->managerOnly = UserBuilder::aUser()->build();
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
            $this->outsider,
            $creator,
            $this->instructor,
            $this->partnerInstructor,
            $this->outsiderInstructor,
            $this->adminOnly,
            $this->managerOnly,
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
            MembershipBuilder::aMembership($this->outsiderInstructor, $this->outsider)->withRoles(Role::Instructor)->build(),
            MembershipBuilder::aMembership($this->adminOnly, $this->organizer)->withRoles(Role::MunicipalityAdmin)->build(),
            MembershipBuilder::aMembership($this->managerOnly, $this->organizer)->withRoles(Role::EventManager)->build(),
        );
    }

    protected function instruction(?AuditTrail $audit = null, ?ApprovedPartListener $listener = null, ?ClockInterface $clock = null): MunicipalInstructionService
    {
        $container = self::getContainer();
        $audit ??= $container->get(AuditTrail::class);
        $applier = new MunicipalDecisionApplier(
            $audit,
            $container->get(EventZoneValidityRepository::class),
            $listener ?? PermitIssuerFactory::create($container, $audit), // le VRAI créateur de laissez-passer (LOT 05.1) : les tests du LOT 04 le traversent
        );

        // Les agents des tests sont relus à l'usage (les assertions SQL vident l'EntityManager) : en production le
        // jeton de sécurité fournit un utilisateur géré par l'EntityManager de la requête.
        return new MunicipalInstructionService(
            $container->get(EventTransactions::class),
            $container->get(EventLocker::class),
            $clock ?? $this->clock,
            $container->get(MunicipalityContext::class),
            $container->get(PermitRequestRepository::class),
            new DoctrineRefusalReasonRepository($this->em),
            $applier,
        );
    }

    /** Service de dépôt à horloge figée (12 juin 2026 09:00 UTC), pour la délivrance directe. */
    protected function issuer(?AuditTrail $audit = null, ?ApprovedPartListener $listener = null): RequestSubmissionService
    {
        return DirectIssuanceFactory::create(self::getContainer(), $this->clock, $audit, $listener);
    }

    /**
     * Commande de saisie d'un bénéficiaire pour les zones données (à pied, e-mail).
     *
     * @param list<EventZone> $zones
     */
    protected function command(array $zones): SubmitRequestCommand
    {
        return new SubmitRequestCommand(
            $this->event->id()->toRfc4122(),
            'Martin',
            'Camille',
            null,
            'camille@exemple.fr',
            null,
            'private_person',
            'home',
            null,
            TravelMode::FootBike,
            [],
            array_map(static fn (EventZone $z): string => $z->id()->toRfc4122(), $zones),
            true,
        );
    }

    /**
     * Dépose une demande publique ; sans argument : les deux communes. Retourne l'identifiant du dossier.
     *
     * @param list<EventZone>|null $zones
     */
    protected function submit(?array $zones = null): string
    {
        $zones ??= [$this->organizerZone, $this->partnerZone];
        $command = new SubmitRequestCommand(
            $this->event->id()->toRfc4122(),
            'Martin',
            'Camille',
            null,
            'camille@exemple.fr',
            null,
            'private_person',
            'home',
            null,
            TravelMode::FootBike,
            [],
            array_map(static fn (EventZone $z): string => $z->id()->toRfc4122(), $zones),
            true,
        );

        return $this->service(RequestSubmissionService::class)->submitPublic($command, sprintf('decision-test-%016d', ++$this->keys))->requestId;
    }

    protected function key(): string
    {
        return sprintf('decision-test-%016d', ++$this->keys);
    }

    protected function rows(string $table): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM '.$table);
    }

    /** Statut courant de la part de la commune pour ce dossier. */
    protected function partStatus(string $requestId, Municipality $municipality): string
    {
        return (string) $this->em->getConnection()->fetchOne(
            'SELECT status FROM request_permit_request_municipality WHERE request_id = :r AND municipality_id = :m',
            ['r' => (string) hex2bin(str_replace('-', '', $requestId)), 'm' => $municipality->id()->toBinary()],
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function decisionRow(string $requestId, Municipality $municipality): ?array
    {
        $row = $this->em->getConnection()->fetchAssociative(
            'SELECT d.* FROM request_permit_request_decision d JOIN request_permit_request_municipality m ON m.id = d.request_municipality_id'
            .' WHERE m.request_id = :r AND m.municipality_id = :m',
            ['r' => (string) hex2bin(str_replace('-', '', $requestId)), 'm' => $municipality->id()->toBinary()],
        );

        return false === $row ? null : $row;
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function decisionAudits(): array
    {
        $rows = $this->em->getConnection()->fetchAllAssociative("SELECT action, LOWER(HEX(municipality_id)) AS municipality, LOWER(HEX(actor_id)) AS actor, previous_value, new_value FROM audit_log WHERE action IN ('REQUEST_APPROVED', 'REQUEST_REFUSED') ORDER BY occurred_at");

        return array_map(static fn (array $r): array => ['action' => $r['action'], 'municipality' => $r['municipality'], 'actor' => $r['actor'], 'previous' => json_decode((string) $r['previous_value'], true), 'new' => json_decode((string) $r['new_value'], true)], $rows);
    }

    /**
     * @param array<string, mixed> $params
     */
    protected function sql(string $statement, array $params = []): void
    {
        $this->em->getConnection()->executeStatement($statement, $params);
        $this->em->clear();
    }
}
