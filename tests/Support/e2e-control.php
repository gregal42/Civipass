<?php

declare(strict_types=1);

/**
 * Support du parcours e2e LOCAL du contrôle hors connexion (LOT 09.6). Base de TEST uniquement (refuse toute autre). Actions :
 *   seed                       réinitialise la base de test et crée : commune, administrateur, instructeur, événement actif, zone, trois laissez-passer, un code d'activation ;
 *   approve <libellé>          approuve l'appareil en attente de ce libellé (lit son code d'appariement en base : l'administrateur le saisirait) ;
 *   revoke <référence LP-…>    révoque l'autorisation de la commune pour ce laissez-passer ;
 *   deactivate <libellé>       désactive l'appareil (motif : perdu) ;
 *   state                      état des appareils (JSON).
 * Imprime un JSON. Aucune donnée réelle : tout est fictif.
 */

use App\Control\Application\ControlActivationService;
use App\Control\Application\DeviceApprovalService;
use App\Control\Domain\AssuranceMode;
use App\Control\Domain\ControlDevice;
use App\Control\Domain\DeactivationReason;
use App\Identity\Domain\User;
use App\Municipality\Domain\Municipality;
use App\Municipality\Domain\MunicipalityRole;
use App\Permit\Application\AuthorizationRevocationService;
use App\Permit\Application\QrCodeRenderer;
use App\Permit\Application\VerificationUrlFactory;
use App\Request\Application\Command\SubmitRequestCommand;
use App\Request\Application\MunicipalInstructionService;
use App\Request\Application\RequestReferentialInitializer;
use App\Request\Application\RequestSubmissionService;
use App\Request\Domain\ApprovalDuration;
use App\Request\Domain\TravelMode;
use App\Tests\Support\EventFixtures as F;
use App\Tests\Support\MembershipBuilder;
use App\Tests\Support\MunicipalityBuilder;
use App\Tests\Support\TestDatabase;
use App\Tests\Support\UserBuilder;

$_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'test';
$_SERVER['OFFLINE_CONTROL_ENABLED'] = $_ENV['OFFLINE_CONTROL_ENABLED'] = '1';
require dirname(__DIR__).'/bootstrap.php';

$kernel = new App\Kernel('test', true);
$kernel->boot();
$c = $kernel->getContainer()->get('test.service_container');
$em = $c->get('doctrine')->getManager();
if (!str_contains((string) $em->getConnection()->getDatabase(), '_test')) {
    fwrite(\STDERR, "Base refusée.\n");
    exit(2);
}
$db = $em->getConnection();
$out = static function (array $data): void {
    echo json_encode($data, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES), "\n";
};
$userBy = static fn (string $email): User => $c->get(App\Identity\Domain\UserRepository::class)->findByEmail(App\Identity\Domain\EmailAddress::fromString($email)) ?? throw new RuntimeException('Utilisateur introuvable.');
$latest = static fn (string $label): ControlDevice => $em->getRepository(ControlDevice::class)->findOneBy(['label' => $label], ['registeredAt' => 'DESC']) ?? throw new RuntimeException('Appareil introuvable : '.$label);

switch ($argv[1] ?? '') {
    case 'seed':
        TestDatabase::reset($em);
        $c->get(RequestReferentialInitializer::class)->initialize();
        $org = MunicipalityBuilder::aMunicipality()->withInseeCode('75056')->build();
        $admin = UserBuilder::aUser()->withEmail('admin@e2e.test')->build();
        $instructor = UserBuilder::aUser()->withEmail('instructeur@e2e.test')->build();
        $type = F::type();
        $event = F::draft($org, $admin, $type);
        $event->activate(F::now());
        $participation = F::organizerParticipation($event);
        $zone = F::readyZone($participation);
        foreach ([$org, $admin, $instructor, $type, $event, $participation, $zone->createdBy(), $zone, MembershipBuilder::aMembership($admin, $org)->withRoles(MunicipalityRole::MunicipalityAdmin)->build(), MembershipBuilder::aMembership($instructor, $org)->withRoles(MunicipalityRole::Instructor)->build()] as $entity) {
            $em->persist($entity);
        }
        $em->flush();
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $db->executeStatement('UPDATE event_event SET starts_at = :s, ends_at = :e', ['s' => $now->modify('-1 day')->format('Y-m-d H:i:s'), 'e' => $now->modify('+10 days')->format('Y-m-d H:i:s')]);
        $db->executeStatement("UPDATE event_zone SET label = 'Zone e2e'");
        $em->clear();

        $tokens = [];
        $references = [];
        foreach ([['MARTIN', 'Camille', 'AB-123-CD'], ['DURAND', 'Alice', 'EF-456-GH'], ['LEROY', 'Bruno', 'IJ-789-KL']] as $i => [$last, $first, $plate]) {
            $requestId = $c->get(RequestSubmissionService::class)->submitPublic(
                new SubmitRequestCommand($event->id()->toRfc4122(), $last, $first, '12 rue des Lilas', 'e2e@exemple.fr', '0611223344', 'private_person', 'home', null, TravelMode::Vehicle, [$plate], [$zone->id()->toRfc4122()], true),
                sprintf('e2e-%016d', $i + 1),
            )->requestId;
            $c->get(MunicipalInstructionService::class)->approve($em->find(User::class, $instructor->id()), $org->id()->toRfc4122(), $requestId, ApprovalDuration::untilClosure());
            $em->clear();
            $row = $db->fetchAssociative('SELECT p.reference FROM permit_permit p WHERE p.request_id = :r', ['r' => Symfony\Component\Uid\Uuid::fromString($requestId)->toBinary()]);
            $tokens[$last] = App\Tests\Support\PlainTokens::one($db, 'p.request_id = ?', [Symfony\Component\Uid\Uuid::fromString($requestId)->toBinary()]);
            $references[$last] = $row['reference'];
        }
        $issued = $c->get(ControlActivationService::class)->issue($em->find(Municipality::class, $org->id()), $event->id(), $em->find(User::class, $admin->id()), AssuranceMode::Standard, 5, null, null);
        $urls = $c->get(VerificationUrlFactory::class);
        $out([
            'code' => $issued->code->display(),
            'tokens' => $tokens,
            'references' => $references,
            'verificationBase' => $urls->absolute('/'),
            'qrPng' => base64_encode($c->get(QrCodeRenderer::class)->png($urls->forToken($tokens['MARTIN']), 480)),
            'municipality' => $org->name(),
        ]);
        break;

    case 'approve':
        $device = $latest($argv[2]);
        $admin = $userBy('admin@e2e.test');
        $c->get(DeviceApprovalService::class)->approve($device->activation()->municipality(), $device->id(), (string) $device->pairingCode(), $admin);
        $out(['ok' => true]);
        break;

    case 'revoke':
        $row = $db->fetchAssociative('SELECT p.request_id FROM permit_permit p WHERE p.reference = :r', ['r' => $argv[2]]);
        $instructor = $userBy('instructeur@e2e.test');
        $municipalityId = Symfony\Component\Uid\Uuid::fromBinary((string) $db->fetchOne('SELECT municipality_id FROM permit_authorization LIMIT 1'))->toRfc4122();
        $c->get(AuthorizationRevocationService::class)->revoke($instructor, $municipalityId, Symfony\Component\Uid\Uuid::fromBinary((string) $row['request_id'])->toRfc4122(), 'PUBLIC_SAFETY', null, true);
        $out(['ok' => true]);
        break;

    case 'deactivate':
        $device = $latest($argv[2]);
        $admin = $userBy('admin@e2e.test');
        $c->get(DeviceApprovalService::class)->deactivateDevice($device->activation()->municipality(), $device->id(), DeactivationReason::Lost, $admin);
        $out(['ok' => true]);
        break;

    case 'state':
        $out(['devices' => $db->fetchAllAssociative('SELECT label, status, sync_count, deactivation_reason FROM control_device ORDER BY registered_at')]);
        break;

    default:
        fwrite(\STDERR, "Action inconnue.\n");
        exit(64);
}
