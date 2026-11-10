<?php

declare(strict_types=1);

/**
 * Processus de test (concurrence des changements de période et révocations, LOT 05.5.2) : une opération ; imprime le résultat ou le refus en JSON.
 * Arguments : userId municipalityId requestId action(extend|shorten|revoke) [json] [marqueur:secondes]. Base de TEST uniquement.
 * Le JSON peut porter : `clock` (instant UTC de l'horloge figée, défaut 12 juin 2026 09:00:00), `mode`, `date`, `time`, `reason`.
 * Avec `marqueur:secondes`, l'opération tient ses verrous (attente à l'écriture d'audit, avant le commit) et dépose le fichier marqueur dès qu'elle les tient.
 */

use App\Event\Application\Support\EventLocker;
use App\Event\Application\Support\EventTransactions;
use App\Identity\Domain\User;
use App\Permit\Application\AuthorizationPeriodService;
use App\Permit\Application\AuthorizationRevocationService;
use App\Permit\Application\NotificationIntentRecorder;
use App\Permit\Application\PermitRevisionGuard;
use App\Permit\Domain\PeriodEnd;
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
    $clock = new MockClock(($payload['clock'] ?? '2026-06-12 09:00:00').' UTC');
    $end = static fn (): PeriodEnd => PeriodEnd::fromInput($payload['mode'] ?? 'H24', $payload['date'] ?? null, $payload['time'] ?? null);
    $periods = new AuthorizationPeriodService(
        $container->get(EventTransactions::class),
        $container->get(EventLocker::class),
        $container->get(PermitRevisionGuard::class),
        $container->get(PermitRepository::class),
        $container->get(PermitRevisionRepository::class),
        $clock,
        $audit,
        $container->get(NotificationIntentRecorder::class),
    );
    $result = match ($argv[4]) {
        'extend' => $periods->extend($actor, $argv[2], $argv[3], $end(), $payload['reason'] ?? 'NEED_EXTENDED', null)->plan->kind->value,
        'shorten' => $periods->shorten($actor, $argv[2], $argv[3], $end(), $payload['reason'] ?? 'NEED_ENDED', null, true)->plan->kind->value,
        'revoke' => (new AuthorizationRevocationService($container->get(EventTransactions::class), $container->get(PermitRevisionGuard::class), $container->get(PermitRevisionRepository::class), $clock, $audit, $container->get(NotificationIntentRecorder::class)))->revoke($actor, $argv[2], $argv[3], $payload['reason'] ?? 'DUPLICATE', null, true)->name,
    };
    echo json_encode(['ok' => true, 'outcome' => $result], \JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => get_class($e), 'message' => $e->getMessage(), 'cause' => $e instanceof App\Shared\Domain\OperationConflict ? $e->cause->value : null], \JSON_THROW_ON_ERROR);
}
