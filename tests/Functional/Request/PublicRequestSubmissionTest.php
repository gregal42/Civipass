<?php

declare(strict_types=1);

namespace App\Tests\Functional\Request;

use App\Shared\Application\FileStorageInterface;
use App\Tests\Support\PublicRequestTestCase;
use App\Tests\Support\SampleFiles as S;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Soumission définitive du parcours public (incrément 3B) : dépôt, confirmation limitée à la session, idempotence, revalidation,
 * justificatifs expirés, échecs sans dossier partiel, accusé de réception.
 */
#[Group('requires-request-schema')]
final class PublicRequestSubmissionTest extends PublicRequestTestCase
{
    /**
     * Avance jusqu'au récapitulatif (étape atteinte, non soumise).
     *
     * @param array<string, string> $identity
     */
    private function toRecap(bool $withPdf = false, array $identity = ['last' => 'Martin', 'first' => 'Camille', 'email' => 'camille@exemple.fr']): void
    {
        $this->post('identite', $identity);
        $this->situation();
        $this->zones([$this->organizerZone]);
        $this->post('deplacement', ['mode' => 'vehicle', 'plates' => ['GH-482-KT']]);
        $files = $withPdf ? ['files' => [new UploadedFile(S::temporaryFile(S::pdf()), 'attestation.pdf', 'application/pdf', null, true)]] : [];
        $this->post('justificatifs', [], $files);
        self::assertResponseRedirects($this->base.'/recapitulatif');
    }

    private function submit(): void
    {
        $this->post('recapitulatif', ['privacy' => '1']);
    }

    private function storageRoot(): string
    {
        return static::getContainer()->getParameter('kernel.project_dir').'/var/storage_test';
    }

    public function testASuccessfulSubmissionCreatesTheWholeRequestAndRedirectsToTheConfirmation(): void
    {
        $this->toRecap(withPdf: true);
        $this->client->request('GET', $this->base.'/recapitulatif');
        self::assertSelectorTextContains('.cp-reqform__main', 'Bénéficiaire');
        self::assertSelectorTextContains('.cp-reqform__main', 'attestation.pdf (1 Ko)');
        $this->submit();

        self::assertResponseRedirects($this->base.'/confirmation');
        self::assertSame([1, 1, 1, 1, 1], [$this->rows('request_permit_request'), $this->rows('request_permit_request_municipality'), $this->rows('request_permit_request_zone'), $this->rows('request_permit_request_vehicle'), $this->rows('request_permit_request_attachment')]);
        $row = $this->em()->getConnection()->fetchAssociative('SELECT public_reference, origin, last_name, privacy_attested_at FROM request_permit_request');
        self::assertMatchesRegularExpression('/^DEM-\d{4}-0001$/', $row['public_reference']);
        self::assertSame(['PUBLIC', 'Martin'], [$row['origin'], $row['last_name']]);
        self::assertNotNull($row['privacy_attested_at']);
        $key = (string) $this->em()->getConnection()->fetchOne('SELECT storage_key FROM request_permit_request_attachment');
        self::assertTrue(static::getContainer()->get(FileStorageInterface::class)->exists($key));
        self::assertFileDoesNotExist(static::getContainer()->getParameter('kernel.project_dir').'/public/'.$key, 'aucune pièce accessible par le serveur web');
        self::assertSame(1, $this->rows('audit_log'));
    }

    public function testTheConfirmationShowsTheReferenceWithoutQrCodeNorTrackingAndIsNeverCached(): void
    {
        $this->toRecap();
        $this->submit();
        $reference = (string) $this->em()->getConnection()->fetchOne('SELECT public_reference FROM request_permit_request');

        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString($reference, $content);
        self::assertStringContainsString('Votre demande a bien été envoyée', $content);
        self::assertStringContainsString('examine la demande', $content);
        self::assertStringContainsString('ne vaut pas autorisation', $content);
        self::assertStringNotContainsStringIgnoringCase('QR', $content);
        self::assertCount(0, $this->client->getCrawler()->filter('main form, main a[href*="suivi"]'));
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));

        // Le brouillon est effacé : une nouvelle demande repart de zéro.
        $crawler = $this->client->request('GET', $this->base.'/identite');
        self::assertSame('', $crawler->filter('input[name=last]')->attr('value'));
        $this->client->request('GET', $this->base.'/recapitulatif');
        self::assertResponseRedirects($this->base.'/identite');
    }

    public function testTheConfirmationIsOnlyReadableByTheSessionThatSubmitted(): void
    {
        $this->toRecap();
        $this->submit();
        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertSame($this->base.'/confirmation', $location);
        self::assertDoesNotMatchRegularExpression('/DEM-|Martin|camille/i', $location, 'ni référence ni donnée personnelle dans l\'URL');

        $this->client->request('GET', $location);
        self::assertResponseIsSuccessful();
        $this->client->getCookieJar()->clear(); // un autre navigateur, même URL
        $this->client->request('GET', $location);
        self::assertResponseStatusCodeSame(404);
    }

    public function testAnAcknowledgementEmailIsSentWithoutQrCodeAndOnlyWhenAnAddressWasGiven(): void
    {
        $this->toRecap();
        $this->submit();
        $reference = (string) $this->em()->getConnection()->fetchOne('SELECT public_reference FROM request_permit_request');

        self::assertEmailCount(1);
        $email = self::emailAt(0);
        self::assertSame('camille@exemple.fr', $email->getTo()[0]->getAddress());
        self::assertStringContainsString($reference, (string) $email->getSubject());
        $body = self::bodiesOf($email);
        foreach ([$reference, 'Incendie du massif forestier', 'bien été transmise', 'examiner la demande'] as $text) {
            self::assertStringContainsString($text, $body);
        }
        self::assertStringNotContainsStringIgnoringCase('QR', $body);
        self::assertStringNotContainsString('Martin', $body, 'aucune donnée du dossier dans l\'email');
    }

    public function testNoEmailIsSentWithoutAnAddress(): void
    {
        $this->toRecap(identity: ['last' => 'Martin', 'first' => 'Camille', 'phone' => '06 12 34 56 78']);
        $this->submit();

        self::assertResponseRedirects($this->base.'/confirmation');
        self::assertEmailCount(0);
        self::assertSame(1, $this->rows('request_permit_request'));
    }

    public function testAFailingEmailTransportNeverCancelsASuccessfulDeposit(): void
    {
        $previous = $_ENV['MAILER_DSN'] ?? null;
        $_ENV['MAILER_DSN'] = $_SERVER['MAILER_DSN'] = 'smtp://127.0.0.1:9';
        try {
            $this->toRecap();
            $this->submit();
            self::assertResponseRedirects($this->base.'/confirmation');
            self::assertSame(1, $this->rows('request_permit_request'));
        } finally {
            $_ENV['MAILER_DSN'] = $_SERVER['MAILER_DSN'] = $previous ?? 'null://null';
        }
    }

    public function testADoubleSubmissionNeverCreatesTwoRequests(): void
    {
        $this->toRecap();
        $crawler = $this->client->request('GET', $this->base.'/recapitulatif');
        $token = $crawler->filter('input[name=_token]')->attr('value');

        $this->client->request('POST', $this->base.'/recapitulatif', ['_token' => $token, 'privacy' => '1']);
        self::assertResponseRedirects($this->base.'/confirmation');
        self::assertEmailCount(1);
        $this->client->request('POST', $this->base.'/recapitulatif', ['_token' => $token, 'privacy' => '1']); // double clic
        self::assertResponseRedirects($this->base.'/confirmation');
        self::assertEmailCount(0, message: 'aucun second accusé de réception');

        self::assertSame([1, 1, 1], [$this->rows('request_permit_request'), $this->rows('request_idempotency_key'), $this->rows('audit_log')]);
    }

    public function testTheAttestationAndTheTokenAreRequiredToSubmit(): void
    {
        $this->toRecap();
        $this->post('recapitulatif', []);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#privacy-error', 'politique de confidentialité');

        $this->client->request('GET', $this->base.'/recapitulatif');
        $this->client->request('POST', $this->base.'/recapitulatif', ['_token' => 'faux', 'privacy' => '1']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'session a expiré');
        self::assertSame(0, $this->rows('request_permit_request'));
    }

    public function testAZoneThatBecameUnavailableIsRevalidatedAndNothingIsCreated(): void
    {
        $this->toRecap();
        $token = $this->client->request('GET', $this->base.'/recapitulatif')->filter('input[name=_token]')->attr('value');
        $this->sql('UPDATE event_zone SET status = \'SUSPENDED\' WHERE id = UNHEX(:id)', ['id' => str_replace('-', '', $this->organizerZone->id()->toRfc4122())]);
        $this->client->request('POST', $this->base.'/recapitulatif', ['_token' => $token, 'privacy' => '1']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#zones-error', 'au moins une zone');
        self::assertSame(0, $this->rows('request_permit_request'));
        $crawler = $this->client->request('GET', $this->base.'/identite');
        self::assertSame('Martin', $crawler->filter('input[name=last]')->attr('value'), 'brouillon conservé');
    }

    public function testAnEventThatBecameUnavailableServesNoFormAndCreatesNothing(): void
    {
        $this->toRecap();
        $token = $this->client->request('GET', $this->base.'/recapitulatif')->filter('input[name=_token]')->attr('value');
        $this->sql('UPDATE event_event SET status = \'SUSPENDED\'');
        $this->client->request('POST', $this->base.'/recapitulatif', ['_token' => $token, 'privacy' => '1']);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Les demandes ne sont plus ouvertes');
        self::assertCount(0, $this->client->getCrawler()->filter('form'));
        self::assertSame(0, $this->rows('request_permit_request'));
    }

    public function testAnExpiredAttachmentIsReportedRemovedFromTheDraftAndTheUserCanRetry(): void
    {
        $this->toRecap(withPdf: true);
        $crawler = $this->client->request('GET', $this->base.'/justificatifs');
        static::getContainer()->get(FileStorageInterface::class)->delete($crawler->filter('button[name=remove]')->attr('value')); // purge de 24 h

        $this->submit();
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#files-error', 'attestation.pdf');
        self::assertSelectorTextContains('#files-error', 'n\'est plus disponible');
        self::assertCount(0, $this->client->getCrawler()->filter('.cp-files button[name=remove]'), 'retiré du brouillon');
        self::assertSame(0, $this->rows('request_permit_request'));

        $this->post('justificatifs', []);
        $this->submit();
        self::assertResponseRedirects($this->base.'/confirmation');
        self::assertSame([1, 0], [$this->rows('request_permit_request'), $this->rows('request_permit_request_attachment')]);
    }

    public function testAnAttachmentAlteredAfterUploadIsRefusedByTheServiceWithAMessageAndTheDraftIsKept(): void
    {
        $this->toRecap(withPdf: true);
        $crawler = $this->client->request('GET', $this->base.'/justificatifs');
        file_put_contents($this->storageRoot().'/'.$crawler->filter('button[name=remove]')->attr('value'), 'plus du tout un PDF');

        $this->submit();
        self::assertResponseRedirects($this->base.'/recapitulatif');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.cp-reqform__main', 'n’a pas pu être envoyée');
        self::assertSelectorTextContains('.cp-reqform__main', 'corrompu');
        self::assertSame([0, 0, 0, 0], [$this->rows('request_permit_request'), $this->rows('request_idempotency_key'), $this->rows('request_reference_counter'), $this->rows('audit_log')]);
        self::assertSame([], glob($this->storageRoot().'/files/*') ?: [], 'aucun fichier définitif orphelin');
        self::assertSelectorExists('a.cp-reqform__edit', 'le brouillon est conservé');
    }

    public function testATechnicalFailureCreatesNothingKeepsTheDraftAndALaterRetryWorks(): void
    {
        $this->toRecap(withPdf: true);
        // Le répertoire des fichiers définitifs ne peut pas être créé : échec technique APRÈS le début de la transaction.
        mkdir($this->storageRoot(), 0o700, true);
        file_put_contents($this->storageRoot().'/files', 'bloque');

        $this->submit();
        self::assertResponseRedirects($this->base.'/recapitulatif');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.cp-reqform__main', 'erreur technique');
        self::assertSame([0, 0, 0, 0], [$this->rows('request_permit_request'), $this->rows('request_idempotency_key'), $this->rows('request_reference_counter'), $this->rows('audit_log')]);

        unlink($this->storageRoot().'/files');
        $this->submit();
        self::assertResponseRedirects($this->base.'/confirmation');
        self::assertSame([1, 1], [$this->rows('request_permit_request'), $this->rows('request_permit_request_attachment')]);
        self::assertMatchesRegularExpression('/-0001$/', (string) $this->em()->getConnection()->fetchOne('SELECT public_reference FROM request_permit_request'), 'aucun numéro de référence perdu');
    }

    public function testThePrivacyPolicyLinkComesFromTheConfigurationAndIsAbsentWhenNotConfigured(): void
    {
        $this->toRecap();
        $this->client->request('GET', $this->base.'/recapitulatif');
        self::assertSelectorNotExists('#privacy a');

        $_ENV['PRIVACY_POLICY_URL'] = $_SERVER['PRIVACY_POLICY_URL'] = 'https://exemple.fr/confidentialite';
        try {
            $this->client->request('GET', $this->base.'/recapitulatif');
            self::assertSelectorExists('#privacy a[href="https://exemple.fr/confidentialite"][rel~="noopener"]');
        } finally {
            unset($_ENV['PRIVACY_POLICY_URL'], $_SERVER['PRIVACY_POLICY_URL']);
        }
    }
}
