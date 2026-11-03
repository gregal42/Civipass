<?php

declare(strict_types=1);

/**
 * Processus de test (EventLifecycleConcurrencyTest) : une action du cycle de vie ; imprime le résultat ou le refus en JSON.
 * Arguments : userId action targetId [motif]. Actions : close-event, suspend-event, reactivate-event, accept, suspend, reactivate, close.
 * Base de TEST uniquement.
 */

use App\Event\Application\Service\EventLifecycleService;
use App\Event\Application\Service\EventParticipationService;
use App\Identity\Domain\User;
use Symfony\Component\Uid\Uuid;

$_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'test';
require dirname(__DIR__).'/bootstrap.php';

$kernel = new App\Kernel('test', true);
$kernel->boot();
$container = $kernel->getContainer();
$em = $container->get('doctrine')->getManager();
if (!str_contains((string) $em->getConnection()->getDatabase(), '_test')) {
    fwrite(\STDERR, "Base refusée.\n");
    exit(2);
}
$actor = $em->find(User::class, Uuid::fromString($argv[1])) ?? throw new RuntimeException('Utilisateur introuvable.');
$reason = $argv[4] ?? 'Motif de test';

try {
    $lifecycle = $container->get(EventLifecycleService::class);
    $participations = $container->get(EventParticipationService::class);
    match ($argv[2]) {
        'close-event' => $lifecycle->close($actor, $argv[3]),
        'suspend-event' => $lifecycle->suspend($actor, $argv[3], $reason),
        'reactivate-event' => $lifecycle->reactivate($actor, $argv[3]),
        'accept' => $participations->accept($actor, $argv[3]),
        'suspend' => $participations->suspend($actor, $argv[3], $reason),
        'reactivate' => $participations->reactivate($actor, $argv[3]),
        'close' => $participations->close($actor, $argv[3]),
    };
    echo json_encode(['ok' => true], \JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => get_class($e), 'message' => $e->getMessage()], \JSON_THROW_ON_ERROR);
}
