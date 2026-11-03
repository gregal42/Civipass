<?php

declare(strict_types=1);

/**
 * Processus de test (EventInvitationServiceTest) : une invitation ; imprime le résultat ou le refus en JSON.
 * Arguments : userId eventId municipalityId. Base de TEST uniquement.
 */

use App\Event\Application\Command\InviteMunicipalityCommand;
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

try {
    $result = $container->get(EventParticipationService::class)->invite($actor, new InviteMunicipalityCommand($argv[2], $argv[3]));
    echo json_encode(['ok' => true, 'participationId' => $result->participationId], \JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => get_class($e), 'message' => $e->getMessage()], \JSON_THROW_ON_ERROR);
}
