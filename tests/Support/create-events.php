<?php

declare(strict_types=1);

/**
 * Processus de test (EventCreationServiceTest) : crée N événements via EventCreationService et imprime les références.
 * Arguments : userId municipalityId count. Base de TEST uniquement.
 */

use App\Event\Application\Command\CreateEventCommand;
use App\Event\Application\Service\EventCreationService;
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
$service = $container->get(EventCreationService::class);

$references = [];
for ($i = 0; $i < (int) $argv[3]; ++$i) {
    try {
        $created = $service->create($actor, $argv[2], new CreateEventCommand('fire', 'Événement '.$i, new DateTimeImmutable('2026-12-01 08:00:00', new DateTimeZone('UTC'))));
        $references[] = $created->publicReference;
    } catch (Throwable $e) {
        fwrite(\STDERR, get_class($e).': '.$e->getMessage()."\n");
        exit(1);
    }
}
echo json_encode($references, \JSON_THROW_ON_ERROR);
