<?php

declare(strict_types=1);

namespace App\Tests\Functional\Request;

use App\Request\UI\Http\PublicRequestDraft;
use App\Shared\Application\FileStorageInterface;
use App\Tests\Support\PublicRequestTestCase;
use App\Tests\Support\SampleFiles as S;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * Cycle de vie du brouillon public (LOT 10, O-2, M3 et M4) : expiration après 30 minutes d'inactivité, reprise avant l'échéance, navigateur
 * partagé, effacement volontaire, suppression RÉELLE côté serveur (clé de session et justificatifs temporaires), aucun effet sur les autres
 * brouillons ni sur le dépôt.
 */
#[Group('requires-request-schema')]
final class PublicDraftLifecycleTest extends PublicRequestTestCase
{
    private function sessionOfTheBrowser(): SessionInterface
    {
        $cookie = $this->client->getCookieJar()->get('MOCKSESSID') ?? self::fail('cookie de session absent');
        $session = static::getContainer()->get('session.factory')->createSession();
        $session->setId($cookie->getValue());
        $session->start();

        return $session;
    }

    private function draftKey(): string
    {
        return 'public_request.'.$this->event->publicReference()->value;
    }

    /** Recule la dernière activité du brouillon (secondes), comme si le citoyen était resté inactif. */
    private function ageTheDraft(int $seconds): void
    {
        $session = $this->sessionOfTheBrowser();
        $draft = $session->get($this->draftKey());
        self::assertIsArray($draft, 'le brouillon existe');
        $draft['__touched'] = time() - $seconds;
        $session->set($this->draftKey(), $draft);
        $session->save();
    }

    private function upload(): string
    {
        $this->upToTravel();
        $this->post('deplacement', ['mode' => 'foot_bike']);
        $this->post('justificatifs', [], ['files' => [new UploadedFile(S::temporaryFile(S::pdf()), 'attestation.pdf', 'application/pdf', null, true)]]);
        $files = $this->sessionOfTheBrowser()->get($this->draftKey())['files'] ?? [];
        self::assertCount(1, $files);
        self::assertTrue($this->storage()->exists($files[0]['key']));

        return $files[0]['key'];
    }

    private function storage(): FileStorageInterface
    {
        return static::getContainer()->get(FileStorageInterface::class);
    }

    private function clearForm(): void
    {
        $crawler = $this->client->request('GET', $this->base.'/identite');
        self::assertCount(1, $crawler->filter('form.cp-reqform__clear button[type=submit]'), 'bouton « Effacer ma saisie » présent');
        self::assertSelectorTextContains('form.cp-reqform__clear', 'Effacer ma saisie');
        $this->client->request('POST', $this->base.'/effacer', ['_token' => $crawler->filter('form.cp-reqform__clear input[name=_token]')->attr('value')]);
    }

    // ═══ M3 : expiration après 30 minutes d'inactivité ═══════════════════════
    public function testTheDraftExpiresAfterThirtyMinutesAndTheDataIsReallyRemovedFromTheServer(): void
    {
        $this->identity();
        self::assertSame('Martin', $this->sessionOfTheBrowser()->get($this->draftKey())['last']);

        $this->ageTheDraft(PublicRequestDraft::TTL_SECONDS + 60);
        $crawler = $this->client->request('GET', $this->base.'/identite');

        self::assertSame('', $crawler->filter('input[name=last]')->attr('value'), 'plus de saisie affichée');
        self::assertSelectorTextContains('.cp-alert', 'effacée après 30 minutes d’inactivité');
        $stored = $this->sessionOfTheBrowser()->get($this->draftKey());
        self::assertIsArray($stored);
        self::assertSame(['__touched'], array_keys($stored), 'aucune donnée personnelle ne reste dans la session (pas seulement masquée)');
        self::assertStringNotContainsString('Martin', serialize($this->sessionOfTheBrowser()->all()));
        self::assertStringNotContainsString('camille@exemple.fr', serialize($this->sessionOfTheBrowser()->all()));
    }

    public function testResumingBeforeTheDeadlineKeepsTheDraftAndRestartsTheClock(): void
    {
        $this->identity();
        $this->ageTheDraft(PublicRequestDraft::TTL_SECONDS - 120);

        $crawler = $this->client->request('GET', $this->base.'/identite');

        self::assertSame('Martin', $crawler->filter('input[name=last]')->attr('value'));
        self::assertSame(0, $crawler->filter('.cp-alert')->count(), 'aucun message d’effacement');
        self::assertEqualsWithDelta(time(), $this->sessionOfTheBrowser()->get($this->draftKey())['__touched'], 5, 'chaque visite prolonge la durée de vie');

        // Une activité répétée garde le brouillon bien au-delà de 30 minutes cumulées.
        $this->ageTheDraft(PublicRequestDraft::TTL_SECONDS - 60);
        $crawler = $this->client->request('GET', $this->base.'/identite');
        self::assertSame('Martin', $crawler->filter('input[name=last]')->attr('value'));
    }

    public function testASharedBrowserNeverShowsTheFirstCitizensInputToTheNextPerson(): void
    {
        $this->identity();
        $this->post('situation', ['category' => 'private_person', 'motif' => 'home']);
        $this->ageTheDraft(PublicRequestDraft::TTL_SECONDS + 1);

        // La personne suivante ouvre le formulaire dans le même navigateur.
        $crawler = $this->client->request('GET', $this->base.'/identite');

        self::assertSame('', $crawler->filter('input[name=last]')->attr('value'));
        self::assertSame('', $crawler->filter('input[name=first]')->attr('value'));
        self::assertSame('', $crawler->filter('input[name=email]')->attr('value'));
        // Et une étape avancée ramène à la première : rien du premier citoyen n'est resservi.
        $this->client->request('GET', $this->base.'/zones');
        self::assertResponseRedirects($this->base.'/identite');
    }

    public function testTheTemporaryDocumentsAreDeletedWhenTheDraftExpires(): void
    {
        $key = $this->upload();
        $this->ageTheDraft(PublicRequestDraft::TTL_SECONDS + 60);

        $this->client->request('GET', $this->base.'/justificatifs');

        self::assertFalse($this->storage()->exists($key), 'le justificatif temporaire est supprimé du stockage');
    }

    // ═══ M4 : « Effacer ma saisie » ═════════════════════════════════════════
    public function testTheClearButtonDeletesTheDraftItsDocumentsAndRedirectsToTheFirstStep(): void
    {
        $key = $this->upload();
        $this->clearForm();

        self::assertResponseRedirects($this->base.'/identite');
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('.cp-alert', 'Votre saisie a été effacée.');
        self::assertSame('', $crawler->filter('input[name=last]')->attr('value'));
        self::assertFalse($this->storage()->exists($key), 'le justificatif temporaire est supprimé');
        $stored = $this->sessionOfTheBrowser()->get($this->draftKey());
        self::assertSame(['__touched'], array_keys(\is_array($stored) ? $stored : []), 'la clé du brouillon ne contient plus aucune saisie');
        self::assertStringNotContainsString('camille@exemple.fr', serialize($this->sessionOfTheBrowser()->all()));
    }

    public function testClearingRequiresTheCsrfTokenAndLeavesTheDraftOtherwiseIntact(): void
    {
        $this->identity();

        $this->client->request('POST', $this->base.'/effacer', ['_token' => 'jeton-invalide']);

        self::assertResponseRedirects($this->base.'/identite');
        $crawler = $this->client->followRedirect();
        self::assertSame('Martin', $crawler->filter('input[name=last]')->attr('value'), 'rien n’est effacé sans jeton valide');
        self::assertSelectorTextContains('.cp-alert', 'n’a pas pu être effacée');
        $this->client->request('GET', $this->base.'/effacer');
        self::assertResponseStatusCodeSame(405, 'POST uniquement');
    }

    public function testClearingOnlyTouchesTheDraftOfThisEvent(): void
    {
        $this->identity();
        $session = $this->sessionOfTheBrowser();
        $session->set('public_request.EVT-2099-000001', ['last' => 'Autre', '__touched' => time()]);
        $session->set('mon_compte', 'conservé');
        $session->save();

        $this->clearForm();

        $after = $this->sessionOfTheBrowser();
        self::assertSame('Autre', $after->get('public_request.EVT-2099-000001')['last'], 'le brouillon d’un autre événement est intact');
        self::assertSame('conservé', $after->get('mon_compte'));
    }

    // ═══ M1 : information sur le cookie (texte validé, publié une fois M3 et M4 en place) ═══
    public function testTheCookieNoticeIsShownOnEveryStepOfThePublicFormWithTheValidatedWording(): void
    {
        $this->upToTravel();
        $this->post('deplacement', ['mode' => 'foot_bike']);
        $this->post('justificatifs', []);

        foreach (['identite', 'situation', 'zones', 'deplacement', 'justificatifs', 'recapitulatif'] as $step) {
            $crawler = $this->client->request('GET', $this->base.'/'.$step);
            $notice = $crawler->filter('#cookie-info');
            self::assertCount(1, $notice, $step);
            self::assertSame('Cookie technique', trim($notice->filter('h2')->text()), $step);
            $text = $notice->text();
            self::assertStringContainsString('Pour conserver votre saisie d’une étape à l’autre, ce formulaire utilise un cookie temporaire, sans finalité publicitaire ni de suivi.', $text);
            self::assertStringContainsString('Votre brouillon est automatiquement effacé après 30 minutes d’inactivité. Vous pouvez également supprimer votre saisie à tout moment avec le bouton “Effacer ma saisie”.', $text);
            self::assertStringContainsString('Si vous utilisez un ordinateur partagé, pensez à effacer votre saisie avant de le quitter.', $text);
        }
    }

    // ═══ Sessions authentifiées et dépôt public ═════════════════════════════
    public function testAnAuthenticatedAgentSessionAndItsDraftAreUntouchedByThePublicExpiry(): void
    {
        // Session d'un agent connecté qui a aussi ouvert le formulaire public : seule la clé du brouillon PUBLIC expire.
        $session = static::getContainer()->get('session.factory')->createSession();
        $session->start();
        $session->set('_security_main', 'jeton-agent');
        $session->set('public_request.agent.u.m.e', ['last' => 'Bénéficiaire', '__touched' => time() - 99999]);
        $reference = $this->event->publicReference()->value;
        $session->set('public_request.'.$reference, ['last' => 'Citoyen', '__touched' => time() - 99999]);
        $keeper = static::getContainer()->get(\App\Request\UI\Http\PublicDraftKeeper::class);

        $draft = $keeper->open($session, $reference);

        self::assertSame('', $draft->values()['last'], 'le brouillon public expiré est effacé');
        self::assertSame('jeton-agent', $session->get('_security_main'), 'la session authentifiée est intacte');
        self::assertSame('Bénéficiaire', $session->get('public_request.agent.u.m.e')['last'], 'le brouillon de délivrance directe n’expire pas ici');
    }

    public function testTheWholePublicDepositStillWorksAndTheDraftIsEmptiedAfterTheSubmission(): void
    {
        $this->upToTravel();
        $this->post('deplacement', ['mode' => 'foot_bike']);
        $this->post('justificatifs', []);
        $crawler = $this->client->request('GET', $this->base.'/recapitulatif');
        self::assertResponseIsSuccessful();
        $this->client->request('POST', $this->base.'/recapitulatif', ['_token' => $crawler->filter('input[name=_token]')->attr('value'), 'privacy' => '1']);

        self::assertResponseRedirects($this->base.'/confirmation');
        $stored = $this->sessionOfTheBrowser()->get($this->draftKey());
        self::assertArrayNotHasKey('last', $stored, 'les saisies ne subsistent pas après le dépôt');
        self::assertArrayNotHasKey('email', $stored);
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
    }
}
