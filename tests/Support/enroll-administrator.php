<?php

declare(strict_types=1);

/**
 * Processus de test (concurrence) : inscrit un utilisateur comme premier administrateur d'une commune. Arguments : userId
 * municipalityId. Imprime {"ok":true} ou le refus en JSON. Base de TEST uniquement.
 */

use App\Identity\Domain\User;
use App\Municipality\Application\MembershipManager;
use App\Municipality\Domain\Municipality;
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

try {
    $user = $em->find(User::class, Uuid::fromString($argv[1])) ?? throw new RuntimeException('Utilisateur introuvable.');
    $municipality = $em->find(Municipality::class, Uuid::fromString($argv[2])) ?? throw new RuntimeException('Commune introuvable.');
    $container->get(MembershipManager::class)->enrollFirstAdministrator($user, $municipality, static function (): bool {
        usleep(300_000); // élargit la fenêtre de course : sans verrou, les deux passeraient

        return true;
    });
    echo json_encode(['ok' => true], \JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => get_class($e), 'message' => $e->getMessage()], \JSON_THROW_ON_ERROR);
}
