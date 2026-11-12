<?php

declare(strict_types=1);

/**
 * Processus de test (course entre habilitations et retraits de rôle, LOT 05.6.3) : UNE opération réelle par les services du conteneur ; imprime le résultat ou le refus
 * en JSON. Arguments : kind actorUserId municipalityId [eventId|dossierId] [targetUserId]. kind : designate | remove-role | place-hold. Base de TEST uniquement.
 */

use App\Archive\Application\ArchiveDesignationService;
use App\Archive\Application\LegalHoldService;
use App\Identity\Domain\User;
use App\Municipality\Application\MembershipManager;
use App\Municipality\Domain\MembershipRepository;
use App\Municipality\Domain\Municipality;
use App\Municipality\Domain\MunicipalityRole;
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
$actor = $em->find(User::class, Uuid::fromString($argv[2])) ?? throw new RuntimeException('Utilisateur introuvable.');

try {
    $result = match ($argv[1]) {
        'designate' => $container->get(ArchiveDesignationService::class)->designate($actor, $argv[3], $argv[4], $argv[5])::class,
        'remove-role' => (static function () use ($container, $em, $actor, $argv): string {
            $commune = $em->find(Municipality::class, Uuid::fromString($argv[3])) ?? throw new RuntimeException('Commune introuvable.');
            $target = $em->find(User::class, Uuid::fromString($argv[4])) ?? throw new RuntimeException('Cible introuvable.');
            $membership = $container->get(MembershipRepository::class)->findOneByUserAndMunicipality($target, $commune) ?? throw new RuntimeException('Appartenance introuvable.');
            $container->get(MembershipManager::class)->changeRoles($membership, [MunicipalityRole::Instructor], $actor);

            return 'roles-changed';
        })(),
        'place-hold' => $container->get(LegalHoldService::class)->place($actor, $argv[3], $argv[4], 'LITIGE', null)::class,
    };
    echo json_encode(['ok' => true, 'outcome' => $result], \JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => get_class($e)], \JSON_THROW_ON_ERROR);
}
