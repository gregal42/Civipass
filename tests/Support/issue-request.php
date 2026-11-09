<?php

declare(strict_types=1);

/**
 * Processus de test (concurrence de la délivrance directe) : une délivrance par un agent ; imprime le résultat ou le refus en JSON.
 * Arguments : userId municipalityId eventId zoneId clé [mode] [marqueur:secondes]. Base de TEST uniquement. L'horloge est figée au
 * 12 juin 2026 09:00 UTC (événement du 10 au 20 juin). Avec `marqueur:secondes`, l'opération tient ses verrous (attente à l'écriture
 * d'audit, avant le commit) et dépose le fichier marqueur dès qu'elle les tient.
 */

use App\Identity\Domain\User;
use App\Request\Application\Command\SubmitRequestCommand;
use App\Request\Domain\ApprovalDuration;
use App\Request\Domain\DurationMode;
use App\Request\Domain\TravelMode;
use App\Shared\Application\AuditTrail;
use App\Tests\Support\DirectIssuanceFactory;
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
    if (isset($argv[7])) {
        [$marker, $seconds] = explode(':', $argv[7]);
        $audit = new SleepingAuditTrail($audit, $marker, (float) $seconds);
    }
    $service = DirectIssuanceFactory::create($container, new MockClock('2026-06-12 09:00:00 UTC'), $audit);
    $command = new SubmitRequestCommand($argv[3], 'Martin', 'Camille', null, 'camille@exemple.fr', null, 'private_person', 'home', null, TravelMode::FootBike, [], [$argv[4]], true);
    $result = $service->issueDirectly($actor, $argv[2], $command, ApprovalDuration::fixed(DurationMode::from($argv[6] ?? 'H24')), $argv[5]);
    echo json_encode(['ok' => true, 'reference' => $result->reference, 'replayed' => $result->replayed, 'mode' => $result->decision?->durationMode?->value], \JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => get_class($e), 'message' => $e->getMessage(), 'cause' => $e instanceof App\Shared\Domain\OperationConflict ? $e->cause->value : null], \JSON_THROW_ON_ERROR);
}
