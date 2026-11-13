<?php

declare(strict_types=1);

/**
 * Processus de test (concurrence, LOT 07.1) : exécute UNE opération d'administration CWS ou d'invitation. Arguments : opération puis
 * ses paramètres. Opérations : accept <jeton> <userId> | revoke <invitationId> <actorId> | resend <invitationId> <actorId> |
 * remove <membershipId> <actorId> | grant <municipalityId> <actorId> | open <insee> <actorId> | deactivate <userId> <actorId>.
 * Imprime {"ok":true} ou le refus en JSON. Base de TEST uniquement.
 */

use App\Identity\Application\UserManager;
use App\Identity\Domain\User;
use App\Municipality\Application\CommercialAccessManager;
use App\Municipality\Application\MembershipManager;
use App\Municipality\Application\MunicipalityOpening;
use App\Municipality\Domain\InseeCode;
use App\Municipality\Domain\Municipality;
use App\Municipality\Domain\MunicipalityMembership;
use App\Municipality\Domain\NationalCommune;
use App\Onboarding\Application\AdminInvitationEnrollment;
use App\Onboarding\Application\MunicipalityAdminInvitationService;
use App\Onboarding\Domain\MunicipalityAdminInvitation;
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
    $operation = $argv[1] ?? '';
    $find = static fn (string $class, string $id): object => $em->find($class, Uuid::fromString($id)) ?? throw new RuntimeException('Introuvable.');
    switch ($operation) {
        case 'accept':
            $enrollment = $container->get(AdminInvitationEnrollment::class);
            $resolution = $enrollment->resolve($argv[2]);
            $user = $find(User::class, $argv[3]);
            $enrollment->accept($resolution, $user, null, true);
            break;
        case 'revoke':
            $container->get(MunicipalityAdminInvitationService::class)->revoke($find(MunicipalityAdminInvitation::class, $argv[2]), $find(User::class, $argv[3]));
            break;
        case 'resend':
            $container->get(MunicipalityAdminInvitationService::class)->resend($find(MunicipalityAdminInvitation::class, $argv[2]), $find(User::class, $argv[3]));
            break;
        case 'remove':
            $container->get(MembershipManager::class)->removeAdministratorByCws($find(MunicipalityMembership::class, $argv[2]), $find(User::class, $argv[3]));
            break;
        case 'grant':
            $container->get(CommercialAccessManager::class)->grant($find(Municipality::class, $argv[2]), null, 'Course', $find(User::class, $argv[3]));
            break;
        case 'open':
            $commune = $em->getRepository(NationalCommune::class)->findOneBy(['inseeCode' => InseeCode::fromString($argv[2])->value]) ?? throw new RuntimeException('Référentiel.');
            $container->get(MunicipalityOpening::class)->open($commune, $find(User::class, $argv[3]));
            break;
        case 'deactivate':
            $container->get(UserManager::class)->deactivateByCws($find(User::class, $argv[2]), $find(User::class, $argv[3]));
            break;
        default:
            throw new RuntimeException('Opération inconnue.');
    }
    echo json_encode(['ok' => true], \JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => get_class($e), 'message' => $e->getMessage()], \JSON_THROW_ON_ERROR);
}
