<?php

declare(strict_types=1);

/**
 * Processus de test (concurrence des révocations, LOT 05.5.1) : une révocation d'autorisation ; imprime le résultat ou le refus en JSON.
 * Arguments : userId municipalityId requestId reasonCode [marqueur:secondes]. Base de TEST uniquement. Horloge figée au 12 juin 2026 09:00 UTC.
 * Avec `marqueur:secondes`, l'opération tient ses verrous (attente à l'écriture d'audit, avant le commit) et dépose le fichier marqueur dès qu'elle les tient.
 */

use App\Event\Application\Support\EventTransactions;
use App\Identity\Domain\User;
use App\Permit\Application\AuthorizationRevocationService;
use App\Permit\Application\NotificationIntentRecorder;
use App\Permit\Application\PermitRevisionGuard;
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

try {
    $audit = $container->get(AuditTrail::class);
    if (isset($argv[5])) {
        [$marker, $seconds] = explode(':', $argv[5]);
        $audit = new SleepingAuditTrail($audit, $marker, (float) $seconds, 'PERMIT_AUTHORIZATION_REVOKED');
    }
    $service = new AuthorizationRevocationService(
        $container->get(EventTransactions::class),
        $container->get(PermitRevisionGuard::class),
        $container->get(PermitRevisionRepository::class),
        new MockClock('2026-06-12 09:00:00 UTC'),
        $audit,
        $container->get(NotificationIntentRecorder::class),
    );
    $outcome = $service->revoke($actor, $argv[2], $argv[3], $argv[4], null, true);
    echo json_encode(['ok' => true, 'outcome' => $outcome->name], \JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => get_class($e), 'message' => $e->getMessage(), 'cause' => $e instanceof App\Shared\Domain\OperationConflict ? $e->cause->value : null], \JSON_THROW_ON_ERROR);
}
