<?php

declare(strict_types=1);

namespace App\Tests\Integration\Permit;

use App\Identity\Domain\User;
use App\Request\Application\Command\SubmitRequestCommand;
use App\Request\Application\RequestSubmissionService;
use App\Request\Domain\ApprovalDuration;
use App\Request\Domain\DurationMode;
use App\Request\Domain\TravelMode;
use App\Shared\Domain\DomainRuleViolation;
use App\Shared\Domain\OperationConflict;
use App\Shared\Domain\OperationNotAllowed;
use App\Tests\Support\MunicipalDecisionTestCase;
use App\Tests\Support\ParallelPhp;
use PHPUnit\Framework\Attributes\Group;

/**
 * Concurrence réelle (processus PHP sur MySQL) des changements de période (LOT 05.5.2) : la première opération TIENT ses verrous (attente avant le commit) ; la
 * seconde attend puis voit l'état COMPLET de la première. Aucune mise à jour perdue, aucune période chevauchante, une seule reprise, la révocation l'emporte.
 */
#[Group('requires-request-schema')]
final class PeriodConcurrencyTest extends MunicipalDecisionTestCase
{
    private const HOLD_SECONDS = 2.5;

    /** @var list<string> */
    private array $markers = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (!$this->em->getConnection()->createSchemaManager()->tablesExist(['permit_authorization_period'])) {
            self::markTestSkipped('Schéma des périodes non migré (LOT 05.5.2) : test exécutable après la migration validée.');
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->markers as $marker) {
            @unlink($marker);
        }
        parent::tearDown();
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
            [$this->organizerZone->id()->toRfc4122()],
            true,
        ), $this->key())->requestId;
        $user = $this->em->find(User::class, $this->instructor->id()) ?? $this->instructor;
        $this->instruction()->approve($user, $this->organizer->id()->toRfc4122(), $id, ApprovalDuration::fixed(DurationMode::H72));

        return $id;
    }

    /**
     * @param array<string, string> $payload
     *
     * @return array{resource, array<int, resource>, string}
     */
    private function start(string $action, string $requestId, array $payload = [], bool $hold = false): array
    {
        $arguments = [$this->instructor->id()->toRfc4122(), $this->organizer->id()->toRfc4122(), $requestId, $action, json_encode($payload, \JSON_THROW_ON_ERROR)];
        $marker = null;
        if ($hold) {
            $marker = tempnam(sys_get_temp_dir(), 'civipass-hold-');
            self::assertNotFalse($marker);
            unlink($marker);
            $this->markers[] = $marker;
            $arguments[] = $marker.':'.self::HOLD_SECONDS;
        }
        $process = ParallelPhp::start('period-change.php', $arguments);
        if (null !== $marker) {
            $deadline = microtime(true) + 8;
            while (!is_file($marker) && microtime(true) < $deadline) {
                usleep(20_000);
            }
            if (!is_file($marker)) {
                $raw = ParallelPhp::finish($process);
                self::fail('L\'opération tenante n\'a pas pris ses verrous : '.$raw['stdout'].' '.substr($raw['stderr'], -500));
            }
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
        if (false === $decoded['ok']) {
            self::assertContains($decoded['error'], [DomainRuleViolation::class, OperationNotAllowed::class, OperationConflict::class], 'refus contrôlé, jamais une erreur technique : '.json_encode($decoded));
        }

        return $decoded;
    }

    private function until(): string
    {
        return (string) $this->em->getConnection()->fetchOne('SELECT valid_until FROM permit_authorization');
    }

    public function testTwoExtensionsAreSerializedAndNoUpdateIsLost(): void
    {
        $requestId = $this->approved(); // fin 15 juin 09:00

        $first = $this->start('extend', $requestId, ['mode' => 'H24'], hold: true);
        $second = $this->start('extend', $requestId, ['mode' => 'H24']);
        usleep(600_000);
        self::assertTrue(ParallelPhp::isRunning($second), 'la seconde prolongation attend les verrous de la première');

        self::assertTrue($this->outcome($first)['ok']);
        self::assertTrue($this->outcome($second)['ok']);

        self::assertSame('2026-06-17 09:00:00', $this->until(), '+24 h puis +24 h : la seconde a vu le résultat de la première');
        self::assertSame(2, $this->rows('permit_revision'));
        self::assertSame(0, $this->rows('permit_authorization_period'));
    }

    public function testTwoSimultaneousResumptionsArchiveExactlyOnePeriod(): void
    {
        $requestId = $this->approved();
        $payload = ['mode' => 'H24', 'clock' => '2026-06-16 09:00:00'];

        $first = $this->start('extend', $requestId, $payload, hold: true);
        $second = $this->start('extend', $requestId, $payload);
        $a = $this->outcome($first);
        $b = $this->outcome($second);

        self::assertSame('RESUMED', $a['outcome']);
        self::assertSame('EXTENDED', $b['outcome'] ?? null, 'la seconde voit une période courante non terminée : ordinaire, jamais une seconde reprise');
        self::assertSame(1, $this->rows('permit_authorization_period'), 'une seule période archivée');
        self::assertSame(2, $this->rows('permit_revision'));
        self::assertSame('2026-06-18 09:00:00', $this->until());
    }

    public function testARevocationHoldingItsLocksMakesTheWaitingExtensionFail(): void
    {
        $requestId = $this->approved();

        $revocation = $this->start('revoke', $requestId, [], hold: true);
        $extension = $this->start('extend', $requestId, ['mode' => 'H24']);
        usleep(600_000);
        self::assertTrue(ParallelPhp::isRunning($extension));

        self::assertSame('Revoked', $this->outcome($revocation)['outcome']);
        $b = $this->outcome($extension);

        self::assertFalse($b['ok']);
        self::assertSame(DomainRuleViolation::class, $b['error']);
        self::assertStringContainsString('révoquée', (string) $b['message']);
        self::assertSame('2026-06-15 09:00:00', $this->until(), 'la période n\'a pas bougé : la révocation ne se contourne pas');
    }

    public function testAShorteningAndAnExtensionAreSerializedWithACoherentFinalState(): void
    {
        $requestId = $this->approved();

        $first = $this->start('shorten', $requestId, ['mode' => 'CUSTOM', 'date' => '2026-06-14', 'time' => '12:00'], hold: true); // 10:00 UTC
        $second = $this->start('extend', $requestId, ['mode' => 'H24']);
        $a = $this->outcome($first);
        $b = $this->outcome($second);

        self::assertTrue($a['ok'] && $b['ok']);
        self::assertSame('2026-06-15 10:00:00', $this->until(), 'raccourci à 14 juin 10:00 puis +24 h : jamais un état intermédiaire perdu');
        self::assertSame(2, $this->rows('permit_revision'));
    }
}
