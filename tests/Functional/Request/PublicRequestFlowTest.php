<?php

declare(strict_types=1);

namespace App\Tests\Functional\Request;

use App\Tests\Support\EventFixtures as F;
use App\Tests\Support\MunicipalityBuilder;
use App\Tests\Support\SampleFiles as S;
use App\Tests\Support\UserBuilder;
use PHPUnit\Framework\Attributes\Group;
use App\Tests\Support\PublicRequestTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Parcours public en six étapes (incrément 3A) : accès, navigation, conservation du brouillon, validation par étape, filtrage
 * des zones, justificatifs. Aucune demande n'est créée en base.
 */
/**
 * Parcours public en six étapes (incrément 3A) : accès, navigation, conservation du brouillon, validation par étape, filtrage
 * des zones, justificatifs.
 */
#[Group('requires-request-schema')]
final class PublicRequestFlowTest extends PublicRequestTestCase
{
    public function testUnknownMalformedAndDraftEventsDoNotExist(): void
    {
        foreach (['/demandes/EVT-2026-999999/identite', '/demandes/pas-une-reference/identite'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseStatusCodeSame(404);
        }
        $type = F::type('storm', 'Tempête');
        $draft = F::draft(MunicipalityBuilder::aMunicipality()->withInseeCode('44109')->build(), UserBuilder::aUser()->build(), $type);
        $this->save($draft->organizerMunicipality(), $draft->createdBy(), $type, $draft);
        $this->client->request('GET', '/demandes/'.$draft->publicReference()->value.'/identite');
        self::assertResponseStatusCodeSame(404);
    }

    public function testASuspendedClosedOrZoneLessEventServesNoForm(): void
    {
        foreach (['UPDATE event_event SET status = \'SUSPENDED\'', 'UPDATE event_event SET status = \'CLOSED\'', 'UPDATE event_zone SET validity_mode = \'UNCONFIGURED\''] as $sql) {
            $this->sql($sql);
            $crawler = $this->client->request('GET', $this->base.'/identite');
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('body', 'Les demandes ne sont plus ouvertes');
            self::assertCount(0, $crawler->filter('form'), $sql);
            $this->client->request('POST', $this->base.'/identite', ['last' => 'X']);
            self::assertCount(0, $this->client->getCrawler()->filter('form'));
            $this->sql('UPDATE event_event SET status = \'ACTIVE\'');
            $this->sql('UPDATE event_zone SET validity_mode = \'EVENT_DURATION\' WHERE label <> \'Zone à configurer\'');
        }
        self::assertSame(0, $this->rows('request_permit_request'));
    }

    public function testTheStartPageLeadsToTheFirstStepOfTheSixStepForm(): void
    {
        $this->client->request('GET', $this->base);
        self::assertResponseRedirects($this->base.'/identite');

        $crawler = $this->client->request('GET', $this->base.'/identite');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form input[name=last]');
        self::assertSelectorExists('form input[name=_token]');
        self::assertSelectorTextContains('.cp-stepper', 'Identité');
        self::assertSelectorTextContains('.cp-reqform__aside', $this->organizerParticipation->municipality()->name());
        self::assertSame('noindex, nofollow', $crawler->filter('meta[name=robots]')->attr('content'));
    }

    public function testAStepIsNotReachableBeforeThePreviousOnesAreValid(): void
    {
        foreach (['situation', 'zones', 'deplacement', 'justificatifs', 'recapitulatif'] as $step) {
            $this->client->request('GET', $this->base.'/'.$step);
            self::assertResponseRedirects($this->base.'/identite', null, $step);
        }
    }

    public function testIdentityIsValidatedKeptAndNavigable(): void
    {
        $this->post('identite', ['last' => '', 'first' => '', 'email' => 'pas-un-email', 'phone' => '']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.cp-error-summary', 'Indiquez votre nom');
        self::assertPageTitleContains('Erreur — Demande de laissez-passer');
        self::assertSelectorTextSame('h1', 'Demande de laissez-passer', 'le titre visible est inchangé');
        self::assertSelectorTextContains('body', 'Cette adresse e-mail ne semble pas valide');
        self::assertSame('pas-un-email', $this->client->getCrawler()->filter('input[name=email]')->attr('value'), 'les saisies sont conservées');

        $this->post('identite', ['last' => 'Martin', 'first' => 'Camille']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#contact-error', 'au moins une adresse e-mail ou un numéro de téléphone');

        $this->post('identite', ['last' => 'Martin', 'first' => 'Camille', 'phone' => '06 12 34 56 78', 'address' => '12 chemin des Pins']);
        self::assertResponseRedirects($this->base.'/situation');

        $crawler = $this->client->request('GET', $this->base.'/identite');
        self::assertSame('Martin', $crawler->filter('input[name=last]')->attr('value'), 'retour arrière sans perte');
        self::assertSame('12 chemin des Pins', $crawler->filter('input[name=address]')->attr('value'));
        $this->client->request('GET', $this->base.'/situation');
        self::assertSelectorExists('a[href="'.$this->base.'/identite"]');
    }

    public function testSituationOnlyOffersActiveEntriesAndRequiresADetailForOther(): void
    {
        $this->sql('UPDATE request_motif SET active = 0 WHERE code = \'workplace\'');
        $this->identity();
        $crawler = $this->client->request('GET', $this->base.'/situation');
        self::assertCount(0, $crawler->filter('input[name=motif][value=workplace]'), 'motif désactivé non proposé');
        self::assertCount(1, $crawler->filter('input[name=motif][value=home]'));
        self::assertCount(5, $crawler->filter('input[name=category]'));

        $this->post('situation', ['category' => 'volunteer', 'motif' => 'other']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'obligatoire pour « Autre »');
        $this->post('situation', ['category' => 'nope', 'motif' => 'workplace']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.cp-error-summary', 'Choisissez une catégorie');
        $this->post('situation', ['category' => 'volunteer', 'motif' => 'other', 'detail' => 'Distribution de repas']);
        self::assertResponseRedirects($this->base.'/zones');
    }

    public function testOnlyProposableZonesOfActiveMunicipalitiesAreOffered(): void
    {
        $this->identity();
        $this->situation();
        $crawler = $this->client->request('GET', $this->base.'/zones');
        self::assertResponseIsSuccessful();
        $offered = $crawler->filter('input[name$="[zones][]"]')->each(static fn ($n): string => (string) $n->attr('value'));
        sort($offered);
        $expected = [$this->organizerZone->id()->toRfc4122(), $this->partnerZone->id()->toRfc4122()];
        sort($expected);
        self::assertSame($expected, $offered, 'ni zone à configurer, ni commune invitée');
        self::assertStringNotContainsString('Zone à configurer', (string) $this->client->getResponse()->getContent());
    }

    public function testZonesAreRevalidatedOnTheServerAndSeveralMunicipalitiesAreAllowed(): void
    {
        $this->identity();
        $this->situation();
        $this->post('zones', ['zones' => []]);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#zones-error', 'au moins une zone');

        $forged = '0198d2f0-0000-7000-8000-000000000000';
        $this->post('zones', ['zones' => ['x' => ['selected' => '1', 'zones' => [$forged]]]]);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#zones-error', 'n\'est plus proposée');

        $this->post('zones', ['zones' => [
            $this->organizerParticipation->id()->toRfc4122() => ['selected' => '1', 'zones' => [$this->organizerZone->id()->toRfc4122()]],
            $this->partnerParticipation->id()->toRfc4122() => ['selected' => '1', 'zones' => [$this->partnerZone->id()->toRfc4122()]],
        ]]);
        self::assertResponseRedirects($this->base.'/deplacement');

        $crawler = $this->client->request('GET', $this->base.'/zones');
        self::assertCount(2, $crawler->filter('input[name$="[zones][]"][checked]'), 'sélection conservée');
    }

    public function testAZoneThatStopsBeingProposableIsDroppedFromTheDraft(): void
    {
        $this->upToTravel();
        $this->sql('UPDATE event_zone SET status = \'SUSPENDED\' WHERE id = UNHEX(:id)', ['id' => str_replace('-', '', $this->organizerZone->id()->toRfc4122())]);

        $this->client->request('GET', $this->base.'/deplacement');
        self::assertResponseRedirects($this->base.'/zones');
    }

    public function testTravelModeIsExclusiveAndPlatesAreFrenchSivOrFni(): void
    {
        $this->upToTravel();
        $this->post('deplacement', []);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#mode-error', 'Choisissez comment');

        $this->post('deplacement', ['mode' => 'vehicle', 'plates' => ['']]);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#plates-error', 'au moins une immatriculation');
        $this->post('deplacement', ['mode' => 'vehicle', 'plates' => ['B-XY 1234']]);
        self::assertSelectorTextContains('#plates-error', 'plaques françaises');
        $this->post('deplacement', ['mode' => 'vehicle', 'plates' => ['AB-123-CD', 'ab 123 cd']]);
        self::assertSelectorTextContains('#plates-error', 'plusieurs fois');
        $this->post('deplacement', ['mode' => 'vehicle', 'plates' => array_map(static fn (int $i): string => sprintf('AB-%03d-CD', $i), range(1, 11))]);
        self::assertSelectorTextContains('#plates-error', '10 immatriculations au maximum');

        $this->post('deplacement', ['mode' => 'vehicle', 'plates' => ['AB-123-CD', '1234 AB 56']]);
        self::assertResponseRedirects($this->base.'/justificatifs');
        // Mode exclusif : à pied ou à vélo ne conserve aucune plaque.
        $this->post('deplacement', ['mode' => 'foot_bike', 'plates' => ['AB-123-CD']]);
        self::assertResponseRedirects($this->base.'/justificatifs');
    }

    public function testDocumentsAreOptionalCheckedOnTheirRealContentLimitedToFiveAndRemovable(): void
    {
        $this->upToTravel();
        $this->post('deplacement', ['mode' => 'foot_bike']);
        $upload = static fn (string $content, string $name): UploadedFile => new UploadedFile(S::temporaryFile($content), $name, 'application/pdf', null, true);

        $this->post('justificatifs', [], ['files' => [$upload(S::pdf(), 'attestation.pdf'), $upload(S::zip(), 'lettre.docx'), $upload(S::png(), 'faux.pdf')]]);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#files-error', 'format DOCX');
        self::assertSelectorTextContains('#files-error', 'corrompu');

        $crawler = $this->client->request('GET', $this->base.'/justificatifs');
        self::assertCount(1, $crawler->filter('.cp-files button[name=remove]'), 'seul le fichier conforme est conservé');
        // La touche Entrée valide le formulaire principal (« Continuer ») : « Retirer » appartient à un formulaire distinct.
        self::assertSame('remove-files', $crawler->filter('.cp-files button[name=remove]')->attr('form'));
        self::assertCount(1, $crawler->filter('form#remove-files input[name=_token]'));
        self::assertCount(0, $crawler->filter('form.cp-reqform__form')->reduce(static fn ($f): bool => 0 === \count($f->filter('.cp-reqform__nav button[type=submit]'))), 'le formulaire principal garde son bouton Continuer');
        self::assertSelectorTextContains('.cp-files', 'attestation.pdf');

        $this->post('justificatifs', [], ['files' => [$upload(S::pdf(), 'b.pdf'), $upload(S::pdf(), 'c.pdf'), $upload(S::pdf(), 'd.pdf'), $upload(S::pdf(), 'e.pdf'), $upload(S::pdf(), 'f.pdf')]]);
        self::assertSelectorTextContains('#files-error', '5 justificatifs au maximum');
        $crawler = $this->client->request('GET', $this->base.'/justificatifs');
        self::assertCount(5, $crawler->filter('.cp-files button[name=remove]'));

        $key = $crawler->filter('.cp-files button[name=remove]')->first()->attr('value');
        $this->post('justificatifs', ['remove' => $key]);
        self::assertResponseRedirects($this->base.'/justificatifs');
        $crawler = $this->client->request('GET', $this->base.'/justificatifs');
        self::assertCount(4, $crawler->filter('.cp-files button[name=remove]'));
        self::assertFalse(static::getContainer()->get(\App\Shared\Application\FileStorageInterface::class)->exists($key), 'le fichier retiré est supprimé du stockage');

        // Une clé qui n'appartient pas au brouillon ne supprime rien.
        $other = static::getContainer()->get(\App\Shared\Application\FileStorageInterface::class)->putTemporary(S::temporaryFile(S::pdf()));
        $this->post('justificatifs', ['remove' => $other]);
        self::assertTrue(static::getContainer()->get(\App\Shared\Application\FileStorageInterface::class)->exists($other));
    }

    public function testDocumentsStepCanBeSkippedAndTheRecapRequiresThePrivacyAttestation(): void
    {
        $this->upToTravel();
        $this->post('deplacement', ['mode' => 'vehicle', 'plates' => ['GH-482-KT']]);
        $this->post('justificatifs', []);
        self::assertResponseRedirects($this->base.'/recapitulatif');

        $crawler = $this->client->request('GET', $this->base.'/recapitulatif');
        self::assertResponseIsSuccessful();
        foreach (['Martin Camille', 'camille@exemple.fr', 'Particuliers', 'Accès au domicile', 'GH482KT'] as $text) {
            self::assertSelectorTextContains('.cp-reqform__main', 'GH482KT' === $text ? 'GH-482-KT' : $text);
        }
        self::assertSelectorTextContains('.cp-reqform__main', 'Route départementale 107');
        self::assertSelectorTextContains('.cp-reqform__main', 'Aucun justificatif joint');
        self::assertCount(5, $crawler->filter('a.cp-reqform__edit'), 'chaque section est modifiable');

        $this->post('recapitulatif', []);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#privacy-error', 'politique de confidentialité');
        self::assertSame(0, $this->rows('request_permit_request'));
    }

    public function testAPostWithoutAValidTokenIsRefused(): void
    {
        $this->client->request('GET', $this->base.'/identite');
        $this->client->request('POST', $this->base.'/identite', ['_token' => 'faux', 'last' => 'Martin', 'first' => 'Camille', 'email' => 'camille@exemple.fr']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'session a expiré');
        $this->client->request('GET', $this->base.'/situation');
        self::assertResponseRedirects($this->base.'/identite', null, 'rien n\'a été enregistré');
    }
}
