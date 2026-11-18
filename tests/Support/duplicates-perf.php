<?php

declare(strict_types=1);

/**
 * Mesure de performance de la détection de doublons (LOT 08.4) : dépose N demandes FICTIVES dans la base de TEST (dont ~5 % partagent un téléphone, ~2 % un nom,
 * ~2 % une plaque), puis mesure la requête de doublons d'une page de liste (25 lignes) et celle d'une fiche, avec la liste complète. Un second passage mesure le
 * pire cas : toutes les demandes partagent le même téléphone. Usage : `php tests/Support/duplicates-perf.php 1000 10000`. Base de TEST uniquement ; les mesures
 * dépendent de la machine (budget indicatif). AUCUN index n'est créé : la mesure décide s'il en faut un.
 */

use App\Municipality\Domain\MunicipalityRole;
use App\Request\Application\Command\SubmitRequestCommand;
use App\Request\Application\MunicipalRequestReadModel;
use App\Request\Application\RequestReferentialInitializer;
use App\Request\Application\RequestSubmissionService;
use App\Request\Application\Read\MunicipalRequestQuery;
use App\Request\Domain\TravelMode;
use App\Tests\Support\EventFixtures as F;
use App\Tests\Support\MembershipBuilder;
use App\Tests\Support\MunicipalityBuilder;
use App\Tests\Support\TestDatabase;
use App\Tests\Support\UserBuilder;
use Symfony\Component\Uid\Uuid;

$_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'test';
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
$sizes = array_map('intval', \array_slice($argv, 1)) ?: [1000, 10000];
sort($sizes);

TestDatabase::reset($em);
$c->get(RequestReferentialInitializer::class)->initialize();
$org = MunicipalityBuilder::aMunicipality()->withInseeCode('75056')->build();
$admin = UserBuilder::aUser()->build();
$type = F::type();
$event = F::draft($org, $admin, $type);
$event->activate(F::now());
$participation = F::organizerParticipation($event);
$zone = F::readyZone($participation);
foreach ([$org, $admin, $type, $event, $participation, $zone->createdBy(), $zone, MembershipBuilder::aMembership($admin, $org)->withRoles(MunicipalityRole::MunicipalityAdmin)->build()] as $entity) {
    $em->persist($entity);
}
$em->flush();
$em->clear();
$eventId = $event->id()->toRfc4122();
$zoneId = $zone->id()->toRfc4122();
$orgId = $org->id();
$letters = 'ABCDEFGHJKLMNPQRSTVWXYZ';
$read = $c->get(MunicipalRequestReadModel::class);

$created = 0;
$make = static function (int $target, bool $worstCase) use (&$created, $letters, $c, $em, $eventId, $zoneId): void {
    while ($created < $target) {
        $i = ++$created;
        $plate = sprintf('%s%s-%03d-%s%s', $letters[$i % 23], $letters[intdiv($i, 23) % 23], 100 + $i % 900, $letters[intdiv($i, 529) % 23], $letters[intdiv($i, 12167) % 23]);
        $phone = $worstCase || 0 === $i % 20 ? '0611223344' : sprintf('06%08d', $i);
        $last = 0 === $i % 50 ? 'DUPONT' : 'NOM'.$i;
        $first = 0 === $i % 50 ? 'Alice' : 'Prenom'.$i;
        $c->get(RequestSubmissionService::class)->submitPublic(
            new SubmitRequestCommand($eventId, $last, $first, '12 rue des Lilas', ($worstCase ? 'x' : 'p'.$i).'@exemple.fr', $phone, 'private_person', 'home', null, TravelMode::Vehicle, [0 === $i % 50 ? 'ZZ-999-ZZ' : $plate], [$zoneId], true),
            sprintf('dperf-%016d', $i),
        )->requestId;
        if (0 === $i % 25) {
            $em->clear();
        }
    }
    $em->clear();
};

$measure = static function (string $label, callable $fn): void {
    $best = \INF;
    for ($k = 0; $k < 5; ++$k) {
        $t = microtime(true);
        $fn();
        $best = min($best, microtime(true) - $t);
    }
    printf("   %-46s %8.1f ms\n", $label, $best * 1000);
};

foreach ([false, true] as $worst) {
    printf("\n%s\n", $worst ? 'PIRE CAS : toutes les demandes partagent le même téléphone et la même adresse e-mail' : 'JEU RÉALISTE : ~5 % de téléphones, ~2 % de noms et de plaques partagés');
    if ($worst) {
        $db->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        foreach (['request_permit_request_zone', 'request_permit_request_attachment', 'request_permit_request_vehicle', 'request_permit_request_municipality', 'request_permit_request', 'request_idempotency_key'] as $table) {
            $db->executeStatement('DELETE FROM '.$table);
        }
        $db->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
        $created = 0;
    }
    foreach ($sizes as $size) {
        $t0 = microtime(true);
        $make($size, $worst);
        printf("%d demandes (dépôt : %.0f s)\n", $size, microtime(true) - $t0);
        // Statistiques à jour (comme après le nettoyage régulier de la base) : sans elles, le plan peut rester celui de la table petite.
        foreach (['request_permit_request', 'request_permit_request_vehicle', 'request_permit_request_municipality', 'event_participation'] as $table) {
            $db->executeQuery('ANALYZE TABLE '.$table)->fetchAllAssociative();
        }
        $options = $read->eventsOf($orgId);
        $page = $read->search($orgId, $options[0], new MunicipalRequestQuery('', null, 1));
        $ids = array_map(static fn ($r): Uuid => Uuid::fromString($r->requestId), $page->items);
        $one = [$ids[0]];
        $measure('liste : page de 25 (search, doublons inclus)', static fn () => $read->search($orgId, $options[0], new MunicipalRequestQuery('', null, 1)));
        $measure('doublons seuls : 25 demandes', static fn () => $read->duplicatesOf($orgId, $ids));
        $measure('doublons seuls : 1 fiche', static fn () => $read->duplicatesOf($orgId, $one));
        $explain = $db->fetchAllAssociative('EXPLAIN SELECT b.id FROM request_permit_request a JOIN request_permit_request b ON b.event_id = a.event_id AND b.id <> a.id WHERE a.id = ? AND (a.phone IS NOT NULL AND a.phone = b.phone)', [$ids[0]->toBinary()]);
        printf("   EXPLAIN (téléphone) : type=%s, clé=%s, lignes estimées=%s\n", $explain[1]['type'] ?? '?', $explain[1]['key'] ?? 'aucune', $explain[1]['rows'] ?? '?');
        $em->clear();
    }
}
