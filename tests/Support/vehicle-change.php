<?php

declare(strict_types=1);

/**
 * Processus de test (concurrence des modifications de plaques, LOT 05.5.4) : une modification de plaques, une modification de zones ou une révocation ; imprime le
 * résultat ou le refus en JSON. Arguments : userId municipalityId requestId action(vehicles|zones|revoke) [json] [marqueur:secondes]. Base de TEST uniquement. Horloge
 * figée au 12 juin 2026 09:00:00 UTC. Le JSON de `vehicles` : { adds: [plaques], removes: [identifiants], reinstates: [identifiants], replacements: [{from, to}] } ; motifs
 * fixés. Avec `marqueur:secondes`, l'opération tient ses verrous (attente à l'écriture d'audit, avant le commit) et dépose le fichier marqueur dès qu'elle les tient.
 */

use App\Event\Application\Support\EventLocker;
use App\Event\Application\Support\EventTransactions;
use App\Event\Domain\EventZoneRepository;
use App\Identity\Domain\User;
use App\Permit\Application\AuthorizationRevocationService;
use App\Permit\Application\AuthorizationZoneService;
use App\Permit\Application\NotificationIntentRecorder;
use App\Permit\Application\PermitRevisionGuard;
use App\Permit\Application\PermitVehicleService;
use App\Permit\Application\Result\VehicleChangeRequest;
use App\Permit\Application\Result\ZoneChangeRequest;
use App\Permit\Domain\PermitRepository;
use App\Permit\Domain\PermitRevisionRepository;
use App\Request\Application\ProposableZones;
use App\Shared\Application\AuditTrail;
use App\Tests\Support\SleepingAuditTrail;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

$_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'test';
require dirname(__DIR__).'/bootstrap.php';

$kernel = new App\Kernel('test', true);
$kernel->boot();
$container = $kernel->getContainer()->get('test.service_container');
$em = $container->get('doctrine')->getManager();
if (!str_contains((string) $em->getConnection()->getDatabase(), '_test')) {
    fwrite(\STDERR, "Base refusée.\n");
    exit(2);
}
$actor = $em->find(User::class, Uuid::fromString($argv[1])) ?? throw new RuntimeException('Utilisateur introuvable.');
$payload = isset($argv[5]) ? json_decode($argv[5], true, 512, \JSON_THROW_ON_ERROR) : [];

try {
    $audit = $container->get(AuditTrail::class);
    if (isset($argv[6])) {
        [$marker, $seconds] = explode(':', $argv[6]);
        $audit = new SleepingAuditTrail($audit, $marker, (float) $seconds, null);
    }
    $clock = new MockClock('2026-06-12 09:00:00 UTC');
    $result = match ($argv[4]) {
        'vehicles' => (static function () use ($container, $clock, $audit, $actor, $argv, $payload): string {
            $service = new PermitVehicleService(
                $container->get(EventTransactions::class),
                $container->get(EventLocker::class),
                $container->get(PermitRevisionGuard::class),
                $container->get(PermitRevisionRepository::class),
                $container->get(PermitRepository::class),
                $clock,
                $audit,
                $container->get(NotificationIntentRecorder::class),
            );
            $plan = $service->change($actor, $argv[2], $argv[3], new VehicleChangeRequest(
                $payload['adds'] ?? [],
                $payload['removes'] ?? [],
                $payload['reinstates'] ?? [],
                $payload['replacements'] ?? [],
                'NEW_VEHICLE',
                'VEHICLE_NO_LONGER_USED',
                'VEHICLE_CHANGED',
                'REMOVED_IN_ERROR',
                null,
                true,
            ))->plan;

            return 'CHANGED:'.\count($plan->changes);
        })(),
        'zones' => (static function () use ($container, $clock, $audit, $actor, $argv, $payload): string {
            $service = new AuthorizationZoneService(
                $container->get(EventTransactions::class),
                $container->get(EventLocker::class),
                $container->get(PermitRevisionGuard::class),
                $container->get(PermitRevisionRepository::class),
                $container->get(EventZoneRepository::class),
                $container->get(ProposableZones::class),
                $clock,
                $audit,
                $container->get(NotificationIntentRecorder::class),
            );
            $plan = $service->change($actor, $argv[2], $argv[3], new ZoneChangeRequest($payload['adds'] ?? [], $payload['removes'] ?? [], $payload['reinstates'] ?? [], null, 'NEED_ENDED', 'NEED_RETURNED', null))->plan;

            return 'CHANGED:'.\count($plan->changes);
        })(),
        'revoke' => (new AuthorizationRevocationService($container->get(EventTransactions::class), $container->get(PermitRevisionGuard::class), $container->get(PermitRevisionRepository::class), $clock, $audit, $container->get(NotificationIntentRecorder::class)))->revoke($actor, $argv[2], $argv[3], 'DUPLICATE', null, true)->name,
    };
    echo json_encode(['ok' => true, 'outcome' => $result], \JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => get_class($e), 'message' => $e->getMessage(), 'cause' => $e instanceof App\Shared\Domain\OperationConflict ? $e->cause->value : null], \JSON_THROW_ON_ERROR);
}
