<?php

declare(strict_types=1);

namespace App\Tests\Functional\Recette;

use App\Identity\Domain\User;
use App\Municipality\Domain\Municipality;
use App\Municipality\Domain\MunicipalityRole as Role;
use App\Permit\Application\PermitAccessService;
use App\Request\Application\Command\SubmitRequestCommand;
use App\Request\Application\MunicipalInstructionService;
use App\Request\Application\RequestSubmissionService;
use App\Request\Domain\ApprovalDuration;
use App\Request\Domain\TravelMode;
use App\Tests\Support\MembershipBuilder;
use App\Tests\Support\PublicRequestTestCase;
use App\Tests\Support\UserBuilder;
use PHPUnit\Framework\Attributes\Group;

/**
 * RECETTE (LOT 05.5.6) : produit le HTML RÉEL des écrans des LOT 05.4 à 05.5.5 (page publique active, QR remplacé, événement clos, QR inconnu, lien du bénéficiaire, écrans
 * agent des droits, des plaques et du QR) dans `var/recette/snapshots/` pour un rendu à 390 px dans un vrai navigateur (voir docs/lot-05-5-6-recette.md). Ignoré sauf si
 * `CIVIPASS_RECETTE_SNAPSHOT=1` ; ne fait pas partie de `make quality`. Données de test uniquement ; l'interrupteur public n'est activé qu'ICI, dans cet environnement isolé.
 */
#[Group('requires-request-schema')]
#[Group('recette')]
final class MobileSnapshotRecetteTest extends PublicRequestTestCase
{
    private ?string $previousSwitch = null;

    protected function setUp(): void
    {
        if ('1' !== getenv('CIVIPASS_RECETTE_SNAPSHOT')) {
            self::markTestSkipped('Rendu mobile : définir CIVIPASS_RECETTE_SNAPSHOT=1.');
        }
        $this->previousSwitch = $_SERVER['PUBLIC_VERIFICATION_ENABLED'] ?? null;
        $_SERVER['PUBLIC_VERIFICATION_ENABLED'] = $_ENV['PUBLIC_VERIFICATION_ENABLED'] = '1';
        putenv('PUBLIC_VERIFICATION_ENABLED=1');
        parent::setUp();
    }

    protected function tearDown(): void
    {
        $value = $this->previousSwitch ?? '0';
        $_SERVER['PUBLIC_VERIFICATION_ENABLED'] = $_ENV['PUBLIC_VERIFICATION_ENABLED'] = $value;
        putenv('PUBLIC_VERIFICATION_ENABLED='.$value);
        parent::tearDown();
    }

    private function save2(string $name): void
    {
        $dir = \dirname(__DIR__, 3).'/var/recette/snapshots';
        @mkdir($dir, 0775, true);
        $html = (string) $this->client->getResponse()->getContent();
        $html = (string) preg_replace('/<head>/i', '<head><base href="http://civipass.cws.gregory.localhost/">', $html, 1);
        file_put_contents($dir.'/'.$name.'.html', $html);
    }

    public function testProduceTheRealScreensForTheMobileRendering(): void
    {
        $organizer = $this->em()->find(Municipality::class, $this->organizerParticipation->municipality()->id()) ?? self::fail();
        $partner = $this->em()->find(Municipality::class, $this->partnerParticipation->municipality()->id()) ?? self::fail();
        [$admin, $partnerAgent] = [UserBuilder::aUser()->build(), UserBuilder::aUser()->build()];
        $this->save($admin, $partnerAgent, MembershipBuilder::aMembership($admin, $organizer)->withRoles(Role::MunicipalityAdmin, Role::Instructor)->build(), MembershipBuilder::aMembership($partnerAgent, $partner)->withRoles(Role::Instructor)->build());
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->sql('UPDATE event_event SET starts_at = :s, ends_at = :e', ['s' => $now->modify('-1 day')->format('Y-m-d H:i:s'), 'e' => $now->modify('+10 days')->format('Y-m-d H:i:s')]);
        $this->sql('UPDATE event_zone SET label = :l WHERE id = :id', ['l' => 'Zone centre-ville nord', 'id' => $this->organizerZone->id()->toBinary()]);
        $this->sql('UPDATE event_zone SET label = :l WHERE id = :id', ['l' => 'Zone partenaire gare', 'id' => $this->partnerZone->id()->toBinary()]);
        $requestId = static::getContainer()->get(RequestSubmissionService::class)->submitPublic(
            new SubmitRequestCommand($this->event->id()->toRfc4122(), 'Martin', 'Camille', '12 rue des Lilas', 'camille@exemple.fr', '0611223344', 'private_person', 'home', null, TravelMode::Vehicle, ['GH-482-KT', 'AB-123-CD'], [$this->organizerZone->id()->toRfc4122(), $this->partnerZone->id()->toRfc4122()], true),
            sprintf('snapshot-%016d', random_int(1, 999999)),
        )->requestId;
        $instruction = static::getContainer()->get(MunicipalInstructionService::class);
        $instruction->approve($this->em()->find(User::class, $admin->id()), $organizer->id()->toRfc4122(), $requestId, ApprovalDuration::untilClosure());
        $instruction->approve($this->em()->find(User::class, $partnerAgent->id()), $partner->id()->toRfc4122(), $requestId, ApprovalDuration::untilClosure());
        $this->client->loginUser($this->em()->find(User::class, $admin->id()));
        $secret = static::getContainer()->get(PermitAccessService::class)->issue($this->em()->find(User::class, $admin->id()), $organizer->id()->toRfc4122(), $requestId)->secret;
        $base = '/communes/'.$organizer->id()->toRfc4122().'/demandes/'.$requestId.'/droits';
        $old = (string) $this->em()->getConnection()->fetchOne('SELECT token FROM permit_verification_token');

        $this->client->request('GET', '/v/'.$old);
        $this->save2('01-public-actif');
        $this->client->request('GET', '/laissez-passer/'.$secret);
        $this->save2('02-lien-beneficiaire');
        $this->client->request('GET', $base);
        $this->save2('03-ecran-droits');
        $this->client->request('GET', $base.'/vehicules');
        $this->save2('04-ecran-plaques');
        $plates = $this->client->request('GET', $base.'/vehicules');
        $this->client->request('POST', $base.'/vehicules', ['add' => ['EF-456-GH'], 'remove' => [(string) $this->em()->getConnection()->fetchOne('SELECT LOWER(CONCAT(SUBSTR(HEX(id),1,8),\'-\',SUBSTR(HEX(id),9,4),\'-\',SUBSTR(HEX(id),13,4),\'-\',SUBSTR(HEX(id),17,4),\'-\',SUBSTR(HEX(id),21))) FROM permit_vehicle WHERE registration = \'AB123CD\'')], 'remove_reason' => 'ENTRY_ERROR', 'step' => 'preview', '_token' => (string) $plates->filter('input[name=_token]')->attr('value')]);
        $this->save2('04b-ecran-plaques-apercu');
        $crawler = $this->client->request('GET', $base.'/qr');
        $this->save2('05-ecran-qr');
        $this->client->request('POST', $base.'/qr', ['reason' => 'LOST', 'confirm_global' => '1', '_token' => (string) $crawler->filter('input[name=_token]')->attr('value'), 'operation_id' => (string) $crawler->filter('input[name=operation_id]')->attr('value')]);
        $this->client->request('GET', '/v/'.$old);
        $this->save2('06-public-qr-remplace-410');
        $new = (string) $this->em()->getConnection()->fetchOne('SELECT token FROM permit_verification_token WHERE active_permit_id IS NOT NULL');
        $this->client->request('GET', $base.'/qr');
        $this->save2('07-ecran-qr-remplacement-rapproche');
        $this->sql("UPDATE event_event SET status = 'CLOSED', closed_at = UTC_TIMESTAMP()");
        $this->client->request('GET', '/v/'.$new);
        $this->save2('08-public-evenement-clos');
        $this->client->request('GET', '/v/'.str_repeat('a', 32));
        $this->save2('09-public-inconnu-404');

        self::assertFileExists(\dirname(__DIR__, 3).'/var/recette/snapshots/09-public-inconnu-404.html');
    }
}
