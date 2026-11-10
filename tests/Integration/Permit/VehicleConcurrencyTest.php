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
use Symfony\Component\Uid\Uuid;

/**
 * Concurrence réelle (processus PHP sur MySQL) des modifications de plaques (LOT 05.5.4) : la première opération TIENT ses verrous (attente avant le commit) ; la seconde
 * attend puis voit l'état COMPLET de la première (relu sous verrou). Plaques contre révocation, deux communes qui modifient les plaques communes en même temps, course
 * sur la dernière plaque, sur un doublon et sur le plafond de dix plaques, plaques contre zones : jamais d'interblocage, jamais d'effet perdu ni d'état interdit.
 */
#[Group('requires-request-schema')]
final class VehicleConcurrencyTest extends MunicipalDecisionTestCase
{
    private const HOLD_SECONDS = 2.5;

    private EventZone $zoneB;

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
        $this->zoneB = F::readyZone($organizer);
        $this->save($this->zoneB->createdBy(), $this->zoneB);
    }

    /** @param list<string> $plates */
    private function approved(array $plates): string
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
            $plates,
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
     * @param array<string, mixed> $payload
     *
     * @return array{resource, array<int, resource>, string}
     */
    private function launch(string $requestId, string $action, array $payload, bool $hold = false, ?User $agent = null, ?Municipality $commune = null): array
    {
        $arguments = [($agent ?? $this->instructor)->id()->toRfc4122(), ($commune ?? $this->organizer)->id()->toRfc4122(), $requestId, $action, json_encode($payload, \JSON_THROW_ON_ERROR)];
        $marker = null;
        if ($hold) {
            $marker = $this->marker();
            $arguments[] = $marker.':'.self::HOLD_SECONDS;
        }
        $process = ParallelPhp::start('vehicle-change.php', $arguments);
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

    private function id(string $registration): string
    {
        return Uuid::fromBinary((string) $this->em->getConnection()->fetchOne('SELECT id FROM permit_vehicle WHERE registration = :r', ['r' => $registration]))->toRfc4122();
    }

    private function activeCount(): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM permit_vehicle WHERE removed_at IS NULL');
    }

    public function testAPlateChangeHoldingItsLocksMakesTheConcurrentRevocationWaitThenBothApply(): void
    {
        $requestId = $this->approved(['AA111AA', 'BB222BB']);

        $change = $this->launch($requestId, 'vehicles', ['adds' => ['CC333CC']], hold: true);
        $revocation = $this->launch($requestId, 'revoke', []);
        usleep(600_000);
        self::assertTrue(ParallelPhp::isRunning($revocation), 'la révocation attend les verrous de la modification');

        self::assertTrue($this->outcome($change)['ok']);
        self::assertSame('Revoked', $this->outcome($revocation)['outcome']);

        self::assertSame(3, $this->activeCount(), 'la modification a abouti avant la révocation');
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM permit_authorization WHERE revoked_at IS NOT NULL'));
    }

    public function testARevocationHoldingItsLocksMakesTheWaitingPlateChangeFailAndNothingIsChanged(): void
    {
        $requestId = $this->approved(['AA111AA', 'BB222BB']);

        $revocation = $this->launch($requestId, 'revoke', [], hold: true);
        $change = $this->launch($requestId, 'vehicles', ['adds' => ['CC333CC']]);
        usleep(600_000);
        self::assertTrue(ParallelPhp::isRunning($change));

        self::assertSame('Revoked', $this->outcome($revocation)['outcome']);
        $result = $this->outcome($change);

        self::assertFalse($result['ok']);
        self::assertSame(DomainRuleViolation::class, $result['error']);
        self::assertStringContainsString('révoquée', (string) $result['message']);
        self::assertSame(2, $this->activeCount(), 'la révocation ne se contourne pas : aucune plaque ajoutée');
        self::assertSame(0, $this->rows('permit_revision_vehicle'));
    }

    public function testTheOtherCommunesRevocationDoesNotBlockAPlateChangeOfTheFirstCommune(): void
    {
        $requestId = $this->approved(['AA111AA', 'BB222BB']);

        $revocation = $this->launch($requestId, 'revoke', [], hold: true, agent: $this->partnerInstructor, commune: $this->partner);
        $change = $this->launch($requestId, 'vehicles', ['adds' => ['CC333CC']]);

        self::assertSame('Revoked', $this->outcome($revocation)['outcome']);
        self::assertTrue($this->outcome($change)['ok'], 'la première commune garde son autorisation : elle modifie les plaques communes');
        self::assertSame(3, $this->activeCount());
    }

    public function testTwoCommunesChangingTheCommonPlatesAtTheSameTimeAreSerializedAndNeitherIsLost(): void
    {
        $requestId = $this->approved(['AA111AA', 'BB222BB']);

        $first = $this->launch($requestId, 'vehicles', ['adds' => ['CC333CC']], hold: true);
        $second = $this->launch($requestId, 'vehicles', ['adds' => ['DD444DD']], agent: $this->partnerInstructor, commune: $this->partner);
        usleep(600_000);
        self::assertTrue(ParallelPhp::isRunning($second), 'la seconde commune attend le verrou du laissez-passer');

        $a = $this->outcome($first);
        $b = $this->outcome($second);

        self::assertTrue($a['ok'] && $b['ok'], json_encode([$a, $b], \JSON_THROW_ON_ERROR));
        self::assertSame(4, $this->activeCount(), 'les deux ajouts sont là');
        foreach ([$this->organizer, $this->partner] as $commune) {
            self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM permit_revision WHERE municipality_id = :m', ['m' => $commune->id()->toBinary()]), 'une révision par commune agissante');
        }
        $positions = $this->em->getConnection()->fetchFirstColumn('SELECT position FROM permit_vehicle ORDER BY position');
        self::assertCount(4, array_unique($positions), 'positions distinctes : la seconde a relu les lignes sous verrou');
    }

    public function testTwoSimultaneousAdditionsOfTheSamePlateLeaveExactlyOneRow(): void
    {
        $requestId = $this->approved(['AA111AA', 'BB222BB']);

        $first = $this->launch($requestId, 'vehicles', ['adds' => ['CC333CC']], hold: true);
        $second = $this->launch($requestId, 'vehicles', ['adds' => ['cc-333-cc']], agent: $this->partnerInstructor, commune: $this->partner);
        $a = $this->outcome($first);
        $b = $this->outcome($second);

        self::assertTrue($a['ok']);
        self::assertFalse($b['ok']);
        self::assertStringContainsString('figure déjà', (string) $b['message']);
        self::assertSame(3, $this->activeCount());
        self::assertSame(1, $this->rows('permit_revision'), 'une seule révision : la seconde voit l\'état complet de la première');
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM permit_vehicle WHERE registration = 'CC333CC'"));
    }

    public function testTwoRemovalsThatWouldLeaveNoPlateNeverBothSucceed(): void
    {
        $requestId = $this->approved(['AA111AA', 'BB222BB']);
        $a = $this->id('AA111AA');
        $b = $this->id('BB222BB');

        $first = $this->launch($requestId, 'vehicles', ['removes' => [$a]], hold: true);
        $second = $this->launch($requestId, 'vehicles', ['removes' => [$b]], agent: $this->partnerInstructor, commune: $this->partner);
        $one = $this->outcome($first);
        $two = $this->outcome($second);

        self::assertTrue($one['ok']);
        self::assertFalse($two['ok'], 'la seconde voit l\'état final de la première : il ne reste qu\'une plaque');
        self::assertStringContainsString('au moins une plaque', (string) $two['message']);
        self::assertSame(1, $this->activeCount(), 'jamais un laissez-passer véhicule sans plaque');
    }

    public function testTwoAdditionsThatWouldExceedTenPlatesNeverBothSucceed(): void
    {
        $requestId = $this->approved(['AA111AA', 'AA222AA', 'AA333AA', 'AA444AA', 'AA555AA', 'AA666AA', 'AA777AA', 'AA888AA', 'AA999AA']);

        $first = $this->launch($requestId, 'vehicles', ['adds' => ['CC333CC']], hold: true);
        $second = $this->launch($requestId, 'vehicles', ['adds' => ['DD444DD']], agent: $this->partnerInstructor, commune: $this->partner);
        $a = $this->outcome($first);
        $b = $this->outcome($second);

        self::assertTrue($a['ok']);
        self::assertFalse($b['ok']);
        self::assertStringContainsString('plus de 10', (string) $b['message']);
        self::assertSame(10, $this->activeCount(), 'jamais plus de dix plaques actives');
    }

    public function testAPlateReinstatementAndARemovalOfTheSameRowAreSerialized(): void
    {
        $requestId = $this->approved(['AA111AA', 'BB222BB']);
        $b = $this->id('BB222BB');
        self::assertTrue($this->outcome($this->launch($requestId, 'vehicles', ['removes' => [$b]]))['ok']);

        $first = $this->launch($requestId, 'vehicles', ['reinstates' => [$b]], hold: true);
        $second = $this->launch($requestId, 'vehicles', ['reinstates' => [$b]], agent: $this->partnerInstructor, commune: $this->partner);
        $one = $this->outcome($first);
        $two = $this->outcome($second);

        self::assertTrue($one['ok']);
        self::assertFalse($two['ok']);
        self::assertStringContainsString('rien à réactiver', (string) $two['message']);
        self::assertSame(2, $this->activeCount());
        self::assertSame(2, $this->rows('permit_vehicle'), 'la ligne est réutilisée, jamais dupliquée');
    }

    /**
     * Ordre global des verrous : une modification de plaques (événement → participation → laissez-passer) et une modification de zones de la MÊME commune (événement →
     * participation → zones → laissez-passer) se serialisent sans interblocage, et aucune ne perd son effet.
     */
    public function testAPlateChangeAndAZoneChangeOfTheSamePermitNeverDeadlock(): void
    {
        $requestId = $this->approved(['AA111AA', 'BB222BB']);

        $plates = $this->launch($requestId, 'vehicles', ['adds' => ['CC333CC']], hold: true);
        $zones = $this->launch($requestId, 'zones', ['adds' => [$this->zoneB->id()->toRfc4122()]], agent: $this->adminOnly);
        $a = $this->outcome($plates);
        $b = $this->outcome($zones);

        self::assertTrue($a['ok'] && $b['ok'], json_encode([$a, $b], \JSON_THROW_ON_ERROR));
        self::assertSame(3, $this->activeCount());
        self::assertSame(2, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM permit_authorization_zone az JOIN permit_authorization a ON a.id = az.authorization_id WHERE a.municipality_id = :m AND az.removed_at IS NULL', ['m' => $this->organizer->id()->toBinary()]));
    }
}
