<?php

declare(strict_types=1);

/**
 * Mesure de performance du jeu de données de contrôle (LOT 09.3) : construit N laissez-passer FICTIFS dans la base de TEST, puis mesure la construction et la
 * sérialisation du jeu (temps, mémoire, taille brute et compressée), l'« inchangé » et un différentiel. Usage : `php tests/Support/control-perf.php 100 1000`.
 * Base de TEST uniquement. Les mesures dépendent de la machine : elles servent de budget indicatif, pas de garantie.
 */

use App\Control\Application\ControlActivationService;
use App\Control\Application\DeviceApprovalService;
use App\Control\Application\DeviceRegistrationService;
use App\Control\Application\DeviceSyncService;
use App\Control\Domain\AssuranceMode;
use App\Control\Domain\ControlDevice;
use App\Identity\Domain\User;
use App\Municipality\Domain\Municipality;
use App\Municipality\Domain\MunicipalityRole;
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
$kernel = new App\Kernel('test', false);
$kernel->boot();
$c = $kernel->getContainer()->get('test.service_container');
$em = $c->get('doctrine')->getManager();
if (!str_contains((string) $em->getConnection()->getDatabase(), '_test')) {
    fwrite(\STDERR, "Base refusée.\n");
    exit(2);
}
$db = $em->getConnection();
$sizes = array_map('intval', \array_slice($argv, 1)) ?: [100, 1000];
sort($sizes);

TestDatabase::reset($em);
$c->get(RequestReferentialInitializer::class)->initialize();
$org = MunicipalityBuilder::aMunicipality()->withInseeCode('75056')->build();
$admin = UserBuilder::aUser()->build();
$instructor = UserBuilder::aUser()->build();
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
$em->clear();
$eventId = $event->id()->toRfc4122();
$zoneId = $zone->id()->toRfc4122();
$orgId = $org->id()->toRfc4122();

$created = 0;
$letters = 'ABCDEFGHJKLMNPQRSTVWXYZ';
$make = static function (int $target) use (&$created, $letters, $c, $em, $eventId, $zoneId, $orgId, $instructor): void {
    while ($created < $target) {
        $i = ++$created;
        $requestId = $c->get(RequestSubmissionService::class)->submitPublic(
            new SubmitRequestCommand($eventId, 'NOM'.$i, 'Prénom'.$i, '12 rue des Lilas', 'p'.$i.'@exemple.fr', '0611223344', 'private_person', 'home', null, TravelMode::Vehicle, [sprintf('%s%s-%03d-%s%s', $letters[$i % 23], $letters[intdiv($i, 23) % 23], 100 + $i % 900, $letters[intdiv($i, 529) % 23], $letters[intdiv($i, 12167) % 23])], [$zoneId], true),
            sprintf('perf-%016d', $i),
        )->requestId;
        $c->get(MunicipalInstructionService::class)->approve($em->find(User::class, $instructor->id()), $orgId, $requestId, ApprovalDuration::untilClosure());
        if (0 === $i % 25) {
            $em->clear();
        }
    }
    $em->clear();
};

// appareil approuvé
$issued = $c->get(ControlActivationService::class)->issue($em->find(Municipality::class, $org->id()), $event->id(), $em->find(User::class, $admin->id()), AssuranceMode::Standard, 1, null, null);
$registered = $c->get(DeviceRegistrationService::class)->register($issued->code->value(), 'Perf', 'ANDROID', null, 'v4:127.0.0.1');
$device = $em->getRepository(ControlDevice::class)->find($registered->device->id());
$c->get(DeviceApprovalService::class)->approve($em->find(Municipality::class, $org->id()), $device->id(), (string) $device->pairingCode(), $em->find(User::class, $admin->id()));
$em->clear();

printf("%-8s %-12s %-10s %-10s %-10s %-10s %-10s\n", 'permits', 'build (s)', 'mem (Mo)', 'JSON (ko)', 'gzip (ko)', 'inchangé', 'lignes');
foreach ($sizes as $size) {
    $t0 = microtime(true);
    $make($size);
    $creation = microtime(true) - $t0;
    $device = $em->getRepository(ControlDevice::class)->find($registered->device->id());
    $before = memory_get_peak_usage(true);
    $t1 = microtime(true);
    $full = $c->get(DeviceSyncService::class)->sync($device, null);
    $build = microtime(true) - $t1;
    $json = json_encode($full, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
    $t2 = microtime(true);
    $em->clear();
    $device = $em->getRepository(ControlDevice::class)->find($registered->device->id());
    $c->get(DeviceSyncService::class)->sync($device, $full['bk']);
    $unchanged = microtime(true) - $t2;
    printf("%-8d %-12.2f %-10.1f %-10.0f %-10.0f %-10.2f %-10d (création : %.0f s)\n", $size, $build, (memory_get_peak_usage(true) - $before) / 1048576, \strlen($json) / 1024, \strlen((string) gzencode($json, 6)) / 1024, $unchanged, \count($full['lines']), $creation);
}
