<?php

declare(strict_types=1);

namespace App\Tests\Functional\Control;

use App\Tests\Support\ControlTestCase;

/** LOT 08.2 : entrée « Contrôle » : consigne en lecture pour les membres de la commune, jamais de donnée de laissez-passer, isolation par commune. */
final class ControlGuideTest extends ControlTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->client->disableReboot();
    }

    private function guide(string $municipalityId): void
    {
        $this->client->request('GET', '/communes/'.$municipalityId.'/controle');
    }

    public function testAnonymousIsRedirectedToLogin(): void
    {
        $this->guide($this->org->id()->toRfc4122());
        self::assertResponseRedirects('/login');
    }

    public function testEveryCommunalRoleReadsTheGuide(): void
    {
        foreach ([$this->adminO, $this->instructorO] as $user) {
            $this->client->loginUser($this->user($user));
            $this->guide($this->org->id()->toRfc4122());
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('h1', 'Contrôle sur le terrain');
            self::assertSelectorTextContains('main', 'Orange « valide selon la dernière synchronisation »');
            self::assertSelectorTextContains('main', 'ni un refus ni une validation');
            self::assertSelectorTextContains('main', 'Perte ou vol');
            self::assertSelectorExists('#guide-draft');
            self::assertStringContainsString('no-cache', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        }
    }

    public function testSessionWarningIsInjectedInMunicipalPagesOnly(): void
    {
        $this->client->loginUser($this->user($this->instructorO));
        $this->guide($this->org->id()->toRfc4122());
        self::assertSelectorExists('[data-controller="session-guard"]');
        self::assertStringContainsString('/session/prolonger', (string) $this->client->getResponse()->getContent());
        $this->client->request('GET', '/login');
        self::assertSelectorNotExists('[data-controller="session-guard"]');
    }

    public function testNavigationShowsTheControlEntry(): void
    {
        $this->client->loginUser($this->user($this->instructorO));
        $this->client->request('GET', '/communes/'.$this->org->id()->toRfc4122().'/evenements');
        self::assertSelectorExists('a[href="/communes/'.$this->org->id()->toRfc4122().'/controle"]');
    }

    public function testAdministrationBlockIsReservedToAdministratorsAndDoesNotEnableAnything(): void
    {
        $this->client->loginUser($this->user($this->instructorO));
        $this->guide($this->org->id()->toRfc4122());
        self::assertSelectorNotExists('a:contains("Choisir un événement")');
        self::assertStringNotContainsString('Administration', (string) $this->client->getResponse()->getContent());

        $this->client->loginUser($this->user($this->adminO));
        $this->guide($this->org->id()->toRfc4122());
        self::assertStringContainsString('Administration', (string) $this->client->getResponse()->getContent());
        self::assertStringContainsString('pas encore activé', (string) $this->client->getResponse()->getContent(), 'Interrupteur à 0 : la page le dit sans rien activer.');
        self::assertStringNotContainsString('/controle/#a=', (string) $this->client->getResponse()->getContent(), 'aucun code d\'activation');
    }

    public function testForeignCommuneAndForgedIdsAreRefusedUniformly(): void
    {
        $this->client->loginUser($this->user($this->adminX));
        $this->guide($this->org->id()->toRfc4122());
        $foreignStatus = $this->client->getResponse()->getStatusCode();
        $this->guide('00000000-0000-4000-8000-000000000000');
        self::assertSame($foreignStatus, $this->client->getResponse()->getStatusCode(), 'même refus pour inexistante et étrangère');
        self::assertContains($foreignStatus, [403, 404]);
    }

    public function testGuideContainsNoPermitData(): void
    {
        $this->makePermit(['org']);
        $this->client->loginUser($this->user($this->adminO));
        $this->guide($this->org->id()->toRfc4122());
        $html = (string) $this->client->getResponse()->getContent();
        foreach (['MARTIN', 'Camille', 'GH-482-KT', self::EMAIL, self::PHONE] as $secret) {
            self::assertStringNotContainsString($secret, $html);
        }
    }
}
