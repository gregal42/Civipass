<?php

declare(strict_types=1);

namespace App\Tests\Functional\Request;

use App\Tests\Support\ControlEnv;
use App\Tests\Support\PublicRequestTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * SEC-003 : le dépôt public est limité par adresse réseau et par événement ; le dépassement répond 429, ne crée rien et conserve la saisie.
 */
#[Group('requires-request-schema')]
final class PublicDepositThrottleFlowTest extends PublicRequestTestCase
{
    use ControlEnv;

    protected function setUp(): void
    {
        $this->controlEnv('PUBLIC_REQUEST_SUBMIT_LIMIT', '1');
        parent::setUp();
    }

    protected function tearDown(): void
    {
        $this->restoreControlEnv();
        parent::tearDown();
    }

    private function toRecap(string $last): void
    {
        $this->post('identite', ['last' => $last, 'first' => 'Camille', 'email' => strtolower($last).'@exemple.fr']);
        $this->situation();
        $this->zones([$this->organizerZone]);
        $this->post('deplacement', ['mode' => 'vehicle', 'plates' => ['GH-482-KT']]);
        $this->post('justificatifs', []);
        self::assertResponseRedirects($this->base.'/recapitulatif');
    }

    public function testASecondDepositFromTheSameAddressIsRefusedWithoutCreatingAnythingAndKeepsTheDraft(): void
    {
        $this->toRecap('Martin');
        $this->post('recapitulatif', ['privacy' => '1']);
        self::assertResponseRedirects($this->base.'/confirmation');

        $this->toRecap('Durand');
        $this->post('recapitulatif', ['privacy' => '1']);

        self::assertResponseStatusCodeSame(429);
        self::assertNotNull($this->client->getResponse()->headers->get('Retry-After'));
        self::assertSame(1, $this->rows('request_permit_request'), 'aucune seconde demande créée');
        $this->client->request('GET', $this->base.'/recapitulatif');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Durand', (string) $this->client->getResponse()->getContent(), 'la saisie est conservée');
    }

    public function testAnotherAddressIsNotAffected(): void
    {
        $this->toRecap('Martin');
        $this->post('recapitulatif', ['privacy' => '1']);
        self::assertResponseRedirects($this->base.'/confirmation');

        $this->client->setServerParameter('REMOTE_ADDR', '203.0.113.9');
        $this->toRecap('Durand');
        $this->post('recapitulatif', ['privacy' => '1']);

        self::assertResponseRedirects($this->base.'/confirmation');
        self::assertSame(2, $this->rows('request_permit_request'));
    }
}
