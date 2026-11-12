<?php

declare(strict_types=1);

namespace App\Tests\Functional\Rights;

use App\Rights\Application\RightsExportService;
use App\Rights\Domain\RightsRequestKind;
use App\Shared\Domain\DomainRuleViolation;
use App\Shared\Domain\OperationNotAllowed;
use App\Tests\Support\ParallelPhp;
use App\Tests\Support\RightsScenarioTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Export d'accès (LOT 05.6.6, D3) : pendant un événement actif, après attestation, à la volée, 3 téléchargements et 30 jours au plus, habilitations recontrôlées à chaque
 * téléchargement, journal de chaque téléchargement, aucun contenu propre d'une autre commune, aucun fichier conservé ; concurrence réelle sur le compteur.
 */
final class RightsExportTest extends RightsScenarioTestCase
{
    private function export(): RightsExportService
    {
        return static::getContainer()->get(RightsExportService::class);
    }

    /** Demande d'accès attestée et accordée sur l'événement ACTIF du scénario ; retourne son identifiant. */
    private function granted(): string
    {
        $id = $this->verified(RightsRequestKind::Access);
        $this->svc()->decide($this->managed($this->admin), $this->organizerId, $id, 'GRANTED', null, false);
        $this->em()->clear();

        return $id;
    }

    /** @return array{string, array<string, string>} chemin et contenu des entrées (le fichier est supprimé) */
    private function download(string $id): array
    {
        $file = $this->export()->download($this->managed($this->admin), $this->organizerId, $id);
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($file['path']));
        $entries = [];
        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $entries[(string) $zip->getNameIndex($i)] = (string) $zip->getFromIndex($i);
        }
        $zip->close();
        unlink($file['path']);

        return [$file['filename'], $entries];
    }

    public function testTheDossierCanBeExportedDuringAnActiveEventAfterTheIdentityAttestation(): void
    {
        $id = $this->granted();
        self::assertSame('ACTIVE', $this->em()->getConnection()->fetchOne('SELECT status FROM event_event'));

        [$name, $entries] = $this->download($id);

        self::assertStringStartsWith('export-dr-', $name);
        $json = array_filter($entries, static fn (string $k): bool => str_ends_with($k, 'dossier.json'), \ARRAY_FILTER_USE_KEY);
        self::assertCount(1, $json);
        $data = json_decode((string) array_values($json)[0], true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame('Martin', $data['identite']['nom']);
        self::assertSame('camille.secret@exemple.fr', $data['identite']['email']);
        self::assertSame(['GH482KT'], $data['plaques']);
        self::assertNotNull($data['dossier_de_votre_commune']['decision']);
        self::assertSame(1, $data['autres_communes']['communes_concernees_en_plus_de_la_votre']);
        self::assertNotEmpty(array_filter(array_keys($entries), static fn (string $k): bool => str_contains($k, 'justificatifs/')), 'les justificatifs sont inclus');
        $audit = $this->em()->getConnection()->fetchAllAssociative('SELECT new_value FROM audit_log WHERE action = \'RIGHTS_EXPORT_DOWNLOADED\'');
        self::assertCount(1, $audit);
        self::assertStringNotContainsString('Martin', (string) $audit[0]['new_value']);
    }

    public function testTheExportNeverContainsAnotherCommunesOwnContent(): void
    {
        $this->sql('UPDATE request_permit_request_zone z JOIN request_permit_request_municipality rm ON rm.id = z.request_municipality_id SET z.zone_label = \'SECRET-DE-LA-COMMUNE-PARTENAIRE\' WHERE rm.municipality_id = UNHEX(:m)', ['m' => str_replace('-', '', $this->partnerId)]);
        $id = $this->granted();

        [, $entries] = $this->download($id);

        $all = implode("\n", array_filter($entries, static fn (string $k): bool => !str_contains($k, 'justificatifs/'), \ARRAY_FILTER_USE_KEY));
        self::assertStringNotContainsString('SECRET-DE-LA-COMMUNE-PARTENAIRE', $all);
        self::assertStringNotContainsString('token', strtolower($all), 'aucun jeton');
    }

    public function testThereIsNoExportBeforeTheDecisionOrAfterARefusalOrToAnyoneElse(): void
    {
        $id = $this->verified(RightsRequestKind::Access);
        try {
            $this->export()->download($this->managed($this->admin), $this->organizerId, $id);
            self::fail('Export avant la décision.');
        } catch (DomainRuleViolation) {
            $this->addToAssertionCount(1);
        }
        $this->svc()->decide($this->managed($this->admin), $this->organizerId, $id, 'REFUSED', 'OTHER_GROUNDS', false);
        try {
            $this->export()->download($this->managed($this->admin), $this->organizerId, $id);
            self::fail('Export après un refus.');
        } catch (DomainRuleViolation) {
            $this->addToAssertionCount(1);
        }
        self::assertSame(0, $this->requestRow($id)['export_downloads'], 'aucun téléchargement décompté');

        $granted = $this->granted();
        foreach ([[$this->manager, $this->organizerId], [$this->instructor, $this->organizerId], [$this->partnerAdmin, $this->partnerId], [$this->partnerAdmin, $this->organizerId], [$this->outsider, $this->organizerId], [$this->superAdmin, $this->organizerId]] as [$user, $commune]) {
            try {
                $this->export()->download($this->managed($user), $commune, $granted);
                self::fail('Export par un utilisateur non habilité.');
            } catch (OperationNotAllowed) {
                $this->addToAssertionCount(1);
            }
        }
        self::assertSame(0, $this->requestRow($granted)['export_downloads']);
    }

    public function testThreeDownloadsAtMostEachOneJournaledAndNothingRemainsOnDisk(): void
    {
        $id = $this->granted();
        $temp = static fn (): int => \count(glob(sys_get_temp_dir().'/civipass-export-*') ?: []);
        $before = $temp();

        for ($i = 1; $i <= 3; ++$i) {
            $this->download($id);
            self::assertSame($i, $this->requestRow($id)['export_downloads']);
        }
        try {
            $this->download($id);
            self::fail('Quatrième téléchargement accepté.');
        } catch (DomainRuleViolation $e) {
            self::assertStringContainsString('limite de 3', $e->getMessage());
        }
        self::assertSame(3, $this->requestRow($id)['export_downloads']);
        self::assertSame(3, $this->rightsAuditCount('RIGHTS_EXPORT_DOWNLOADED'), 'chaque téléchargement est journalisé');
        self::assertSame($before, $temp(), 'aucun fichier d\'export conservé');
    }

    public function testTheWindowExpiresAndNeverExceedsThirtyDays(): void
    {
        $id = $this->granted();
        $until = new \DateTimeImmutable((string) $this->requestRow($id)['export_available_until'], new \DateTimeZone('UTC'));
        self::assertLessThanOrEqual(30 * 86400 + 5, $until->getTimestamp() - time());

        $this->sql('UPDATE rights_request SET export_available_until = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 MINUTE)');
        $this->expectException(DomainRuleViolation::class);
        $this->expectExceptionMessage('expirée');
        $this->download($id);
    }

    public function testTheEntitlementIsCheckedAgainOnEveryDownload(): void
    {
        $id = $this->granted();
        $this->download($id);

        $this->sql('UPDATE municipality_membership SET roles = JSON_ARRAY(\'INSTRUCTOR\') WHERE user_id = UNHEX(:u) AND municipality_id = UNHEX(:m)', ['u' => str_replace('-', '', $this->admin->id()->toRfc4122()), 'm' => str_replace('-', '', $this->organizerId)]);
        try {
            $this->download($id);
            self::fail('Téléchargement après retrait du rôle.');
        } catch (OperationNotAllowed) {
            $this->addToAssertionCount(1);
        }
        $this->sql('UPDATE municipality_membership SET roles = JSON_ARRAY(\'MUNICIPALITY_ADMIN\'), active = 0 WHERE user_id = UNHEX(:u) AND municipality_id = UNHEX(:m)', ['u' => str_replace('-', '', $this->admin->id()->toRfc4122()), 'm' => str_replace('-', '', $this->organizerId)]);
        try {
            $this->download($id);
            self::fail('Téléchargement après désactivation de l\'appartenance.');
        } catch (OperationNotAllowed) {
            $this->addToAssertionCount(1);
        }
        self::assertSame(1, $this->requestRow($id)['export_downloads'], 'les refus ne consomment aucun téléchargement');
    }

    public function testAClosedRequestOrAPurgedDossierNoLongerExports(): void
    {
        $id = $this->granted();
        $this->svc()->close($this->managed($this->admin), $this->organizerId, $id);
        $this->expectException(DomainRuleViolation::class);
        $this->download($id);
    }

    public function testADownloadThatFailsGivesBackItsCounter(): void
    {
        $id = $this->granted();
        $this->sql('DELETE FROM rights_request_dossier');
        try {
            $this->download($id);
            self::fail('Export sans dossier.');
        } catch (DomainRuleViolation) {
            $this->addToAssertionCount(1);
        }
        self::assertSame(0, $this->requestRow($id)['export_downloads']);
        self::assertSame(0, $this->rightsAuditCount('RIGHTS_EXPORT_DOWNLOADED'));
    }

    public function testTheHttpDownloadChecksTheRightThenTheTokenThenServesAZipWithoutCaching(): void
    {
        $id = $this->granted();
        $post = '/communes/'.$this->organizerId.'/droits/'.$id.'/export';

        $this->as($this->instructor);
        $this->client->request('POST', $post, ['_token' => 'x']);
        self::assertSame(404, $this->client->getResponse()->getStatusCode(), 'refus avant le jeton CSRF, uniforme');

        $this->as($this->admin);
        $crawler = $this->client->request('GET', '/communes/'.$this->organizerId.'/droits/'.$id);
        $token = (string) $crawler->filter('form[action="'.$post.'"] input[name=_token]')->attr('value');
        $this->client->request('POST', $post, ['_token' => 'invalide']);
        self::assertResponseRedirects();
        self::assertSame(0, $this->requestRow($id)['export_downloads'], 'jeton invalide : aucun téléchargement décompté');

        $this->client->request('POST', $post, ['_token' => $token]);
        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/zip', $response->headers->get('Content-Type'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertStringContainsString('attachment', (string) $response->headers->get('Content-Disposition'));
        self::assertSame(1, $this->requestRow($id)['export_downloads']);
    }

    public function testConcurrentDownloadsNeverExceedTheLimit(): void
    {
        $id = $this->granted();
        $admin = $this->admin->id()->toRfc4122();

        $results = [];
        foreach (ParallelPhp::run('rights-race.php', array_fill(0, 7, ['download', $admin, $this->organizerId, $id])) as $raw) {
            $decoded = json_decode($raw['stdout'], true, 512, \JSON_THROW_ON_ERROR);
            self::assertIsArray($decoded, $raw['stderr']);
            self::assertArrayNotHasKey('error', $decoded, json_encode($decoded, \JSON_THROW_ON_ERROR));
            $results[] = $decoded;
        }

        self::assertSame(3, \count(array_filter($results, static fn (array $r): bool => $r['ok'])), 'trois téléchargements exactement');
        self::assertSame(3, $this->requestRow($id)['export_downloads']);
        self::assertSame(3, $this->rightsAuditCount('RIGHTS_EXPORT_DOWNLOADED'));
        self::assertTrue(Uuid::isValid($id));
    }
}
