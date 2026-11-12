<?php

declare(strict_types=1);

namespace App\Tests\Functional\Retention;

use App\Tests\Support\RetentionScenarioTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Registre et suppression d'événement dans le navigateur simulé (LOT 05.6.4) : administrateur de la commune seulement, 404 uniforme pour tout autre (identifiants
 * forgés compris), isolation entre communes, jeton CSRF vérifié après le droit, aucune donnée personnelle.
 */
final class RetentionAccessTest extends RetentionScenarioTestCase
{
    private function url(string $municipalityId, string $suffix = ''): string
    {
        return '/communes/'.$municipalityId.'/retention'.$suffix;
    }

    public function testOnlyTheMunicipalAdministratorSeesItsOwnRegisterAndNothingPersonal(): void
    {
        $this->dueForPurge();
        $this->as($this->admin);
        $this->client->request('GET', $this->url($this->organizerId));
        self::assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        foreach (self::SENTINELS as $sentinel) {
            self::assertStringNotContainsStringIgnoringCase($sentinel, $body);
        }
        self::assertStringContainsString('NOTICE', $body);
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));

        $organizerBody = $body;
        $this->as($this->partnerAdmin);
        $this->client->request('GET', $this->url($this->partnerId));
        self::assertResponseIsSuccessful();
        self::assertSame(1, substr_count((string) $this->client->getResponse()->getContent(), '<td>NOTICE</td>'), 'seulement l\'avis de SA commune');
        self::assertSame(1, substr_count($organizerBody, '<td>NOTICE</td>'));

        $answers = [];
        foreach ([[$this->manager, $this->organizerId], [$this->instructor, $this->organizerId], [$this->partnerAdmin, $this->organizerId], [$this->outsider, $this->organizerId], [$this->admin, $this->partnerId], [$this->admin, Uuid::v7()->toRfc4122()]] as [$user, $commune]) {
            $this->as($user);
            $this->client->request('GET', $this->url($commune));
            self::assertSame(404, $this->client->getResponse()->getStatusCode());
            $answers[] = (string) $this->client->getResponse()->getContent();
        }
        self::assertCount(1, array_unique($answers), 'réponse uniforme');
        $this->client->request('GET', $this->url($this->organizerId));
        $this->client->getCookieJar()->clear();
        $this->client->request('GET', $this->url($this->organizerId));
        self::assertResponseRedirects();
    }

    public function testEventDeletionThroughTheFormNeedsRightThenTokenThenEmptyDossiersThenTheReference(): void
    {
        $this->dueForPurge();
        $eventId = $this->eventId();
        $reference = $this->event->publicReference()->value;
        $post = $this->url($this->organizerId, '/evenements/'.$eventId.'/supprimer');

        foreach ([[$this->manager, $this->organizerId], [$this->partnerAdmin, $this->organizerId], [$this->outsider, $this->organizerId], [$this->partnerAdmin, $this->partnerId]] as [$user, $commune]) {
            $this->as($user);
            $this->client->request('POST', $this->url($commune, '/evenements/'.$eventId.'/supprimer'), ['_token' => 'x', 'reference' => $reference]);
            self::assertSame(404, $this->client->getResponse()->getStatusCode(), 'refus avant même le jeton CSRF');
        }
        $this->as($this->admin);
        $this->client->request('POST', $this->url($this->organizerId, '/evenements/'.Uuid::v7()->toRfc4122().'/supprimer'), ['_token' => 'x', 'reference' => $reference]);
        self::assertSame(404, $this->client->getResponse()->getStatusCode(), 'événement forgé');

        $this->client->request('POST', $post, ['_token' => 'invalide', 'reference' => $reference]);
        self::assertResponseRedirects();
        self::assertSame(1, $this->rows('event_event'), 'jeton invalide : rien n\'est supprimé');

        $crawler = $this->client->request('GET', $this->url($this->organizerId));
        self::assertStringContainsString('Suppression impossible', (string) $this->client->getResponse()->getContent(), 'des dossiers subsistent');
        self::assertSame(0, $crawler->filter('form[action="'.$post.'"]')->count());

        $this->runAll();
        $crawler = $this->client->request('GET', $this->url($this->organizerId));
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getStatusCode().substr(strip_tags((string) $this->client->getResponse()->getContent()), 0, 300));
        $token = (string) $crawler->filter('form[action="'.$post.'"] input[name=_token]')->attr('value');
        $this->client->request('POST', $post, ['_token' => $token, 'reference' => 'ERRONEE']);
        self::assertSame(1, $this->rows('event_event'), 'référence erronée : rien n\'est supprimé');
        $this->client->request('POST', $post, ['_token' => $token, 'reference' => $reference]);
        self::assertResponseRedirects();
        self::assertSame(0, $this->rows('event_event'));
        $this->client->request('POST', $post, ['_token' => $token, 'reference' => $reference]);
        self::assertSame(404, $this->client->getResponse()->getStatusCode(), 'événement disparu : 404 uniforme');
    }

    private function runAll(): void
    {
        static::getContainer()->get(\App\Retention\Application\RetentionPurgeService::class)->run(true, 100, null, null, $this->managed($this->admin));
        $this->em()->clear();
    }
}
