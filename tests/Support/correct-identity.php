<?php

declare(strict_types=1);

/**
 * Processus de test (concurrence de la correction d'identité) : une correction du NOM par un agent ; imprime le résultat ou le refus en JSON.
 * Arguments : userId municipalityId requestId versionAttendue nouveauNom [marqueur:secondes]. Base de TEST uniquement. Avec `marqueur:secondes`,
 * l'opération tient son verrou (attente à l'écriture d'audit, avant le commit) et dépose le fichier marqueur dès qu'elle le tient.
 */

use App\Identity\Domain\User;
use App\Request\Application\Command\CorrectIdentityCommand;
use App\Shared\Application\AuditTrail;
use App\Tests\Support\IdentityCorrectionFactory;
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
    if (isset($argv[6])) {
        [$marker, $seconds] = explode(':', $argv[6]);
        $audit = new SleepingAuditTrail($audit, $marker, (float) $seconds);
    }
    $service = IdentityCorrectionFactory::create($container, new MockClock('2026-06-12 09:00:00 UTC'), $audit);
    $result = $service->correct($actor, $argv[2], $argv[3], new CorrectIdentityCommand((int) $argv[4], $argv[5], 'Camille', '', 'camille@exemple.fr', '', true));
    echo json_encode(['ok' => true, 'changed' => $result->changedFields], \JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => get_class($e), 'message' => $e->getMessage(), 'cause' => $e instanceof App\Shared\Domain\OperationConflict ? $e->cause->value : null], \JSON_THROW_ON_ERROR);
}
