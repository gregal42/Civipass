<?php

declare(strict_types=1);

namespace App\Tests\Integration\Permit;

use App\Event\Domain\EventParticipation;
use App\Event\Domain\EventZone;
use App\Identity\Domain\User;
use App\Municipality\Domain\Municipality;
use App\Request\Application\Command\SubmitRequestCommand;
use App\Request\Application\RequestSubmissionService;
use App\Request\Domain\ApprovalDuration;
use App\Request\Domain\DurationMode;
use App\Request\Domain\TravelMode;
use App\Shared\Domain\DomainRuleViolation;
use App\Shared\Domain\OperationConflict;
use App\Shared\Domain\OperationNotAllowed;
use App\Tests\Support\EventFixtures as F;
use App\Tests\Support\MunicipalDecisionTestCase;
use App\Tests\Support\ParallelPhp;
use PHPUnit\Framework\Attributes\Group;

/**
 * Concurrence réelle (processus PHP sur MySQL) des modifications de zones (LOT 05.5.3) : la première opération TIENT ses verrous (attente avant le commit) ; la
 * seconde attend puis voit l'état COMPLET de la première. Modification contre révocation, contre suspension de zone, deux modifications du même laissez-passer,
 * deux communes en parallèle, deux retraits qui laisseraient l'autorisation sans zone. Ordre global des verrous : aucun interblocage.
 */
#[Group('requires-request-schema')]
final class ZoneConcurrencyTest extends MunicipalDecisionTestCase
{
    private const HOLD_SECONDS = 2.5;

    private EventZone $zoneB;
    private EventZone $zoneC;
    private EventZone $partnerExtra;

    /** @var list<string> */
    private array $markers = [];

    protected function tearDown(): void
    {
        foreach ($this->markers as $marker) {
            @unlink($marker);
        }
        parent::tearDown();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $organizer = $this->em->find(EventParticipation::class, $this->organizerParticipation->id()) ?? self::fail();
        $partner = $this->em->find(EventParticipation::class, $this->partnerParticipation->id()) ?? self::fail();
        $this->zoneB = F::readyZone($organizer);
        $this->zoneC = F::readyZone($organizer);
        $this->partnerExtra = F::readyZone($partner);
        $this->save($this->zoneB->createdBy(), $this->zoneB, $this->zoneC->createdBy(), $this->zoneC, $this->partnerExtra->createdBy(), $this->partnerExtra);
        foreach ([[$this->organizerZone, 'Zone A'], [$this->zoneB, 'Zone B'], [$this->zoneC, 'Zone C'], [$this->partnerZone, 'Zone P1'], [$this->partnerExtra, 'Zone P2']] as [$zone, $label]) {
            $this->sql('UPDATE event_zone SET label = :l WHERE id = :id', ['l' => $label, 'id' => $zone->id()->toBinary()]);
        }
    }

    private function approved(): string
    {
        $id = $this->service(RequestSubmissionService::class)->submitPublic(new SubmitRequestCommand(
            $this->event->id()->toRfc4122(),
            'Dupont',
            'Léa',
            null,
            'lea@exemple.fr',
            null,
            'private_person',
            'home',
            null,
            TravelMode::Vehicle,
            ['QW-123-ER'],
            [$this->organizerZone->id()->toRfc4122(), $this->partnerZone->id()->toRfc4122()],
            true,
        ), $this->key())->requestId;
        foreach ([[$this->instructor, $this->organizer], [$this->partnerInstructor, $this->partner]] as [$agent, $commune]) {
            $user = $this->em->find(User::class, $agent->id()) ?? $agent;
            $this->instruction()->approve($user, $commune->id()->toRfc4122(), $id, ApprovalDuration::fixed(DurationMode::H72));
        }

        return $id;
    }

    private function marker(): string
    {
        $marker = tempnam(sys_get_temp_dir(), 'civipass-hold-');
        self::assertNotFalse($marker);
        unlink($marker);
        $this->markers[] = $marker;

        return $marker;
    }

    /**
     * @param array{resource, array<int, resource>, string} $process
     */
    private function waitFor(string $marker, array $process): void
    {
        $deadline = microtime(true) + 8;
        while (!is_file($marker) && microtime(true) < $deadline) {
            usleep(20_000);
        }
        if (!is_file($marker)) {
            $raw = ParallelPhp::finish($process);
            self::fail('L\'opération tenante n\'a pas pris ses verrous : '.$raw['stdout'].' '.substr($raw['stderr'], -500));
        }
    }

    /**
     * @param array<string, list<string>> $payload
     *
     * @return array{resource, array<int, resource>, string}
     */
    private function zones(string $requestId, array $payload, bool $hold = false, ?User $agent = null, ?Municipality $commune = null, string $action = 'zones'): array
    {
        $arguments = [($agent ?? $this->instructor)->id()->toRfc4122(), ($commune ?? $this->organizer)->id()->toRfc4122(), $requestId, $action, json_encode($payload, \JSON_THROW_ON_ERROR)];
        $marker = null;
        if ($hold) {
            $marker = $this->marker();
            $arguments[] = $marker.':'.self::HOLD_SECONDS;
        }
        $process = ParallelPhp::start('zone-change.php', $arguments);
        if (null !== $marker) {
            $this->waitFor($marker, $process);
        }

        return $process;
    }

    /** @return array{resource, array<int, resource>, string} */
    private function suspendZone(EventZone $zone, bool $hold = false): array
    {
        $arguments = [$this->adminOnly->id()->toRfc4122(), 'zone-suspend', $zone->id()->toRfc4122(), '{}'];
        $marker = null;
        if ($hold) {
            $marker = $this->marker();
            $arguments[] = $marker.':'.self::HOLD_SECONDS;
        }
        $process = ParallelPhp::start('zone-action.php', $arguments);
        if (null !== $marker) {
            $this->waitFor($marker, $process);
        }

        return $process;
    }

    /**
     * @param array{resource, array<int, resource>, string} $process
     *
     * @return array<string, mixed>
     */
    private function outcome(array $process): array
    {
        $raw = ParallelPhp::finish($process);
        $decoded = json_decode($raw['stdout'], true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded, $raw['stderr']);
        if (false === ($decoded['ok'] ?? true)) {
            self::assertContains($decoded['error'], [DomainRuleViolation::class, OperationNotAllowed::class, OperationConflict::class], 'refus contrôlé, jamais une erreur technique : '.json_encode($decoded));
        }

        return $decoded;
    }

    private function attached(Municipality $commune): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM permit_authorization_zone az JOIN permit_authorization a ON a.id = az.authorization_id WHERE a.municipality_id = :m AND az.removed_at IS NULL', ['m' => $commune->id()->toBinary()]);
    }

    private function id(EventZone $zone): string
    {
        return $zone->id()->toRfc4122();
    }

    public function testAZoneChangeHoldingItsLocksMakesTheConcurrentRevocationWaitThenWinsOverNothingAndTheRevocationApplies(): void
    {
        $requestId = $this->approved();

        $change = $this->zones($requestId, ['adds' => [$this->id($this->zoneB)]], hold: true);
        $revocation = $this->zones($requestId, [], action: 'revoke');
        usleep(600_000);
        self::assertTrue(ParallelPhp::isRunning($revocation), 'la révocation attend les verrous de la modification');

        self::assertTrue($this->outcome($change)['ok']);
        self::assertSame('Revoked', $this->outcome($revocation)['outcome']);

        self::assertSame(2, $this->attached($this->organizer), 'la modification a abouti avant la révocation');
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM permit_authorization WHERE revoked_at IS NOT NULL'));
    }

    public function testARevocationHoldingItsLocksMakesTheWaitingZoneChangeFailAndNothingIsChanged(): void
    {
        $requestId = $this->approved();

        $revocation = $this->zones($requestId, [], hold: true, action: 'revoke');
        $change = $this->zones($requestId, ['adds' => [$this->id($this->zoneB)]]);
        usleep(600_000);
        self::assertTrue(ParallelPhp::isRunning($change));

        self::assertSame('Revoked', $this->outcome($revocation)['outcome']);
        $result = $this->outcome($change);

        self::assertFalse($result['ok']);
        self::assertSame(DomainRuleViolation::class, $result['error']);
        self::assertStringContainsString('révoquée', (string) $result['message']);
        self::assertSame(1, $this->attached($this->organizer), 'la révocation ne se contourne pas : aucune zone ajoutée');
    }

    public function testAZoneSuspensionHoldingItsLockMakesTheAdditionWaitThenRefuseTheZone(): void
    {
        $requestId = $this->approved();

        $suspension = $this->suspendZone($this->zoneB, hold: true);
        $addition = $this->zones($requestId, ['adds' => [$this->id($this->zoneB)]]);
        usleep(600_000);
        self::assertTrue(ParallelPhp::isRunning($addition), 'l\'ajout attend le verrou de la zone');

        self::assertTrue($this->outcome($suspension)['ok']);
        $result = $this->outcome($addition);

        self::assertFalse($result['ok']);
        self::assertStringContainsString('pas ouverte', (string) $result['message'], 'la zone suspendue n\'est plus proposable : vu sous verrou');
        self::assertSame(1, $this->attached($this->organizer));
    }

    public function testAnAdditionHoldingTheZoneLockMakesTheSuspensionWaitAndTheZoneEndsSuspendedButAttached(): void
    {
        $requestId = $this->approved();

        $addition = $this->zones($requestId, ['adds' => [$this->id($this->zoneB)]], hold: true);
        $suspension = $this->suspendZone($this->zoneB);
        usleep(600_000);
        self::assertTrue(ParallelPhp::isRunning($suspension), 'la suspension attend le verrou de la zone');

        self::assertTrue($this->outcome($addition)['ok']);
        self::assertTrue($this->outcome($suspension)['ok']);

        self::assertSame(2, $this->attached($this->organizer), 'zone rattachée');
        self::assertSame('SUSPENDED', $this->em->getConnection()->fetchOne('SELECT status FROM event_zone WHERE id = :id', ['id' => $this->zoneB->id()->toBinary()]));
    }

    public function testTwoSimultaneousAdditionsOfTheSameZoneLeaveExactlyOneRow(): void
    {
        $requestId = $this->approved();

        $first = $this->zones($requestId, ['adds' => [$this->id($this->zoneB)]], hold: true);
        $second = $this->zones($requestId, ['adds' => [$this->id($this->zoneB)]], agent: $this->adminOnly);
        $a = $this->outcome($first);
        $b = $this->outcome($second);

        self::assertTrue($a['ok']);
        self::assertFalse($b['ok']);
        self::assertStringContainsString('figure déjà', (string) $b['message']);
        self::assertSame(2, $this->attached($this->organizer));
        self::assertSame(1, $this->rows('permit_revision'), 'une seule révision : la seconde voit l\'état complet de la première');
    }

    public function testTwoModificationsOfTheSamePermitAreSerializedAndNeitherIsLost(): void
    {
        $requestId = $this->approved();

        $first = $this->zones($requestId, ['adds' => [$this->id($this->zoneB)]], hold: true);
        $second = $this->zones($requestId, ['adds' => [$this->id($this->zoneC)]], agent: $this->adminOnly);
        usleep(600_000);
        self::assertTrue(ParallelPhp::isRunning($second));

        self::assertTrue($this->outcome($first)['ok']);
        self::assertTrue($this->outcome($second)['ok']);

        self::assertSame(3, $this->attached($this->organizer), 'les deux ajouts sont là');
        self::assertSame(2, $this->rows('permit_revision'));
    }

    public function testTwoCommunesChangingTheirOwnZonesInParallelBothSucceedWithoutDeadlock(): void
    {
        $requestId = $this->approved();

        $first = $this->zones($requestId, ['adds' => [$this->id($this->zoneB)]], hold: true);
        $second = $this->zones($requestId, ['adds' => [$this->id($this->partnerExtra)]], agent: $this->partnerInstructor, commune: $this->partner);
        $a = $this->outcome($first);
        $b = $this->outcome($second);

        self::assertTrue($a['ok'] && $b['ok'], json_encode([$a, $b], \JSON_THROW_ON_ERROR));
        self::assertSame([2, 2], [$this->attached($this->organizer), $this->attached($this->partner)], 'chaque commune a modifié ses propres zones, sans perte');
    }

    public function testTwoRemovalsThatWouldEmptyTheAuthorizationNeverBothSucceed(): void
    {
        $requestId = $this->approved();
        $setup = $this->outcome($this->zones($requestId, ['adds' => [$this->id($this->zoneB)]])); // deux zones rattachées : A et B
        self::assertTrue($setup['ok']);

        $first = $this->zones($requestId, ['removes' => [$this->id($this->organizerZone)]], hold: true);
        $second = $this->zones($requestId, ['removes' => [$this->id($this->zoneB)]], agent: $this->adminOnly);
        $a = $this->outcome($first);
        $b = $this->outcome($second);

        self::assertTrue($a['ok']);
        self::assertFalse($b['ok'], 'la seconde voit l\'état final de la première : il ne reste qu\'une zone');
        self::assertStringContainsString('au moins une zone', (string) $b['message']);
        self::assertSame(1, $this->attached($this->organizer), 'jamais une autorisation sans zone');
    }

    /**
     * Ordre global des verrous (événement → participation → zones → … → laissez-passer) face au moteur de décision : la modification de zones de l'organisatrice et
     * l'approbation de la commune partenaire (qui verrouille ses zones, la demande puis le laissez-passer) se serialisent sans interblocage.
     */
    public function testAZoneChangeAndTheOtherCommunesApprovalOfTheSameRequestNeverDeadlock(): void
    {
        $id = $this->service(RequestSubmissionService::class)->submitPublic(new SubmitRequestCommand(
            $this->event->id()->toRfc4122(),
            'Dupont',
            'Léa',
            null,
            'lea@exemple.fr',
            null,
            'private_person',
            'home',
            null,
            TravelMode::Vehicle,
            ['QW-123-ER'],
            [$this->organizerZone->id()->toRfc4122(), $this->partnerZone->id()->toRfc4122()],
            true,
        ), $this->key())->requestId;
        $user = $this->em->find(User::class, $this->instructor->id()) ?? $this->instructor;
        $this->instruction()->approve($user, $this->organizer->id()->toRfc4122(), $id, ApprovalDuration::fixed(DurationMode::H72));

        $change = $this->zones($id, ['adds' => [$this->id($this->zoneB)]], hold: true);
        $approval = ParallelPhp::start('decide-request.php', [$this->partnerInstructor->id()->toRfc4122(), $this->partner->id()->toRfc4122(), $id, 'approve', json_encode(['mode' => 'H72'], \JSON_THROW_ON_ERROR)]);
        $a = $this->outcome($change);
        $raw = ParallelPhp::finish($approval);
        $b = json_decode($raw['stdout'], true, 512, \JSON_THROW_ON_ERROR);

        self::assertTrue($a['ok']);
        self::assertTrue($b['ok'], 'l\'approbation aboutit (elle attend le laissez-passer puis passe) : '.$raw['stdout'].' '.substr($raw['stderr'], -300));
        self::assertSame(2, $this->rows('permit_authorization'));
        self::assertSame(2, $this->attached($this->organizer));
    }
}
