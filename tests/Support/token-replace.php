<?php

declare(strict_types=1);

/**
 * Processus de test (concurrence et reprise du remplacement du QR, LOT 05.5.5) : un remplacement du QR, une révocation ou une modification de plaques ; imprime le résultat ou
 * le refus en JSON. Arguments : userId municipalityId requestId action(replace|revoke|vehicles) [json] [marqueur:secondes]. Base de TEST uniquement. Horloge figée au 12 juin
 * 2026 09:00:00 UTC (+ `offset` secondes du JSON). Le JSON de `replace` : { operation, reason?, comment?, reference?, confirm? } ; celui de `vehicles` : { adds: [plaques] }.
 * Avec `marqueur:secondes`, l'opération tient ses verrous (attente à l'écriture d'audit, avant le commit) et dépose le fichier marqueur dès qu'elle les tient. Jamais un jeton
 * dans la sortie : seulement le numéro d'ordre et les indicateurs.
 */

use App\Event\Application\Support\EventLocker;
use App\Event\Application\Support\EventTransactions;
use App\Identity\Domain\User;
use App\Permit\Application\AuthorizationRevocationService;
use App\Permit\Application\NotificationIntentRecorder;
use App\Permit\Application\PermitRevisionGuard;
use App\Permit\Application\PermitTokenReplacementService;
use App\Permit\Application\PermitVehicleService;
use App\Permit\Application\Result\TokenReplacementRequest;
use App\Permit\Application\Result\VehicleChangeRequest;
use App\Permit\Domain\PermitRepository;
use App\Permit\Domain\PermitRevisionRepository;
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
    $clock->sleep((int) ($payload['offset'] ?? 0));
    $result = match ($argv[4]) {
        'replace' => (static function () use ($container, $clock, $audit, $actor, $argv, $payload): string {
            $service = new PermitTokenReplacementService(
                $container->get(EventTransactions::class),
                $container->get(EventLocker::class),
                $container->get(PermitRevisionGuard::class),
                $container->get(PermitRevisionRepository::class),
                $container->get(PermitRepository::class),
                $clock,
                $audit,
                $container->get(NotificationIntentRecorder::class),
            );
            $r = $service->replace($actor, $argv[2], $argv[3], new TokenReplacementRequest($payload['operation'], $payload['reason'] ?? 'LOST', $payload['comment'] ?? null, $payload['confirm'] ?? true, $payload['reference'] ?? null));

            return sprintf('REPLACED:%d:%s', $r->sequence, $r->replayed ? 'replayed' : 'done');
        })(),
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
            $plan = $service->change($actor, $argv[2], $argv[3], new VehicleChangeRequest($payload['adds'] ?? [], [], [], [], 'NEW_VEHICLE', null, null, null, null, true))->plan;

            return 'CHANGED:'.\count($plan->changes);
        })(),
        'revoke' => (new AuthorizationRevocationService($container->get(EventTransactions::class), $container->get(PermitRevisionGuard::class), $container->get(PermitRevisionRepository::class), $clock, $audit, $container->get(NotificationIntentRecorder::class)))->revoke($actor, $argv[2], $argv[3], 'DUPLICATE', null, true)->name,
    };
    echo json_encode(['ok' => true, 'outcome' => $result], \JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => get_class($e), 'message' => $e->getMessage(), 'cause' => $e instanceof App\Shared\Domain\OperationConflict ? $e->cause->value : null], \JSON_THROW_ON_ERROR);
}
