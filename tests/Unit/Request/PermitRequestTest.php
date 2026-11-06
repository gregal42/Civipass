<?php

declare(strict_types=1);

namespace App\Tests\Unit\Request;

use App\Event\Domain\Event;
use App\Event\Domain\EventParticipation;
use App\Event\Domain\EventZone;
use App\Identity\Domain\EmailAddress;
use App\Municipality\Domain\PhoneNumber;
use App\Request\Domain\BeneficiaryCategory;
use App\Request\Domain\MunicipalRequestStatus;
use App\Request\Domain\PermitRequest;
use App\Request\Domain\PermitRequestAttachment;
use App\Request\Domain\PlateFormat;
use App\Request\Domain\RequestedTerritory;
use App\Request\Domain\RequestMotif;
use App\Request\Domain\RequestOrigin;
use App\Request\Domain\RequestReference;
use App\Request\Domain\TravelMode;
use App\Shared\Domain\DomainRuleViolation;
use App\Tests\Support\EventFixtures;
use App\Tests\Support\UserBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Invariants du dossier (docs/lot-03-schema.md §6) : aucune base, uniquement des objets de domaine.
 */
final class PermitRequestTest extends TestCase
{
    private Event $event;
    private EventParticipation $organizer;
    private EventParticipation $partner;
    private EventZone $organizerZone;
    private EventZone $partnerZone;

    protected function setUp(): void
    {
        $this->event = EventFixtures::active();
        $this->organizer = EventFixtures::organizerParticipation($this->event);
        $this->partner = EventFixtures::accepted($this->event);
        $this->organizerZone = EventFixtures::readyZone($this->organizer);
        $this->partnerZone = EventFixtures::readyZone($this->partner);
    }

    /**
     * @param array<string, mixed> $overrides arguments nommés de PermitRequest::submit()
     */
    private function submit(array $overrides = []): PermitRequest
    {
        $arguments = array_merge([
            'reference' => RequestReference::of(2026, 1),
            'event' => $this->event,
            'origin' => RequestOrigin::Public,
            'lastName' => ' Martin ',
            'firstName' => 'Camille',
            'address' => null,
            'email' => EmailAddress::fromString('camille@exemple.fr'),
            'phone' => null,
            'category' => new BeneficiaryCategory('private_person', 'Particuliers', 10),
            'motif' => new RequestMotif('home', 'Accès au domicile', 10),
            'motifDetail' => null,
            'travelMode' => TravelMode::FootBike,
            'plates' => [],
            'territories' => [new RequestedTerritory($this->organizer, [$this->organizerZone])],
            'privacyAttested' => true,
            'privacyNoticeVersion' => null,
            'enteredByUser' => null,
            'enteredByMunicipality' => null,
            'now' => EventFixtures::now(),
        ], $overrides);

        return PermitRequest::submit(...$arguments);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function assertRefused(array $overrides, string $messagePart): void
    {
        try {
            $this->submit($overrides);
            self::fail('Le dossier aurait dû être refusé.');
        } catch (DomainRuleViolation $e) {
            self::assertStringContainsString($messagePart, $e->getMessage());
        }
    }

    public function testMinimalPublicRequest(): void
    {
        $request = $this->submit();

        self::assertSame('Martin', $request->lastName());
        self::assertSame('DEM-2026-0001', $request->reference()->value);
        self::assertSame(RequestOrigin::Public, $request->origin());
        self::assertNull($request->address());
        self::assertNull($request->enteredByUser());
        self::assertSame('Particuliers', $request->categoryLabel());
        self::assertSame('Accès au domicile', $request->motifLabel());
        self::assertSame([], $request->vehicles());
        self::assertCount(1, $request->municipalities());
        self::assertSame(MunicipalRequestStatus::Pending, $request->municipalities()[0]->status());
        self::assertSame('Route départementale 107', $request->municipalities()[0]->zones()[0]->zoneLabel());
        self::assertSame($this->organizer->municipality(), $request->municipalities()[0]->municipality());
    }

    public function testRequestWithAPhoneOnlyAndAnAddress(): void
    {
        $request = $this->submit(['email' => null, 'phone' => PhoneNumber::fromE164('+33612345678'), 'address' => ' 12 chemin des Pins ']);

        self::assertNull($request->email());
        self::assertSame('+33612345678', $request->phone());
        self::assertSame('12 chemin des Pins', $request->address());
    }

    public function testAtLeastOneContactIsRequired(): void
    {
        $this->assertRefused(['email' => null, 'phone' => null], 'au moins un moyen de contact');
    }

    public function testNamesAreRequired(): void
    {
        $this->assertRefused(['lastName' => '  '], 'nom est obligatoire');
        $this->assertRefused(['firstName' => ''], 'prénom est obligatoire');
    }

    public function testInactiveReferentialEntriesAreRefusedAtCreation(): void
    {
        $category = new BeneficiaryCategory('volunteer', 'Bénévoles', 50);
        $category->deactivate();
        $motif = new RequestMotif('home', 'Accès au domicile', 10);
        $motif->deactivate();

        $this->assertRefused(['category' => $category], 'catégorie');
        $this->assertRefused(['motif' => $motif], 'motif');
    }

    public function testDetailIsMandatoryOnlyWhenTheMotifRequiresIt(): void
    {
        $other = new RequestMotif('other', 'Autre', 70, true);

        $this->assertRefused(['motif' => $other], 'précision est obligatoire');
        $this->assertRefused(['motif' => $other, 'motifDetail' => '   '], 'précision est obligatoire');
        self::assertSame('Livraison de fioul', $this->submit(['motif' => $other, 'motifDetail' => ' Livraison de fioul '])->motifDetail());
        self::assertSame('Je rentre chez moi', $this->submit(['motifDetail' => 'Je rentre chez moi'])->motifDetail());
        $this->assertRefused(['motifDetail' => str_repeat('x', 501)], '500 caractères');
    }

    public function testFootOrBikeTravelHasNoPlate(): void
    {
        $this->assertRefused(['travelMode' => TravelMode::FootBike, 'plates' => ['AB-123-CD']], 'Aucune plaque');
    }

    public function testVehicleTravelNeedsOneToTenFrenchPlatesWithoutDuplicates(): void
    {
        $request = $this->submit(['travelMode' => TravelMode::Vehicle, 'plates' => ['ab-123-cd', '1234 AB 56']]);

        self::assertSame(['AB123CD', '1234AB56'], array_map(static fn ($v): string => $v->registration(), $request->vehicles()));
        self::assertSame([PlateFormat::Siv, PlateFormat::Fni], array_map(static fn ($v): PlateFormat => $v->format(), $request->vehicles()));
        self::assertSame([0, 1], array_map(static fn ($v): int => $v->position(), $request->vehicles()));

        $ten = array_map(static fn (int $i): string => sprintf('AB-%03d-CD', $i), range(1, 10));
        self::assertCount(10, $this->submit(['travelMode' => TravelMode::Vehicle, 'plates' => $ten])->vehicles());

        $this->assertRefused(['travelMode' => TravelMode::Vehicle, 'plates' => []], 'de 1 à 10 plaques');
        $this->assertRefused(['travelMode' => TravelMode::Vehicle, 'plates' => [...$ten, 'AB-011-CD']], 'de 1 à 10 plaques');
        $this->assertRefused(['travelMode' => TravelMode::Vehicle, 'plates' => ['AB-123-CD', 'ab 123 cd']], 'plusieurs fois');
        $this->assertRefused(['travelMode' => TravelMode::Vehicle, 'plates' => ['B-XY 1234']], 'plaque');
    }

    public function testPrivacyAttestationIsMandatoryAndAdaptedToTheOrigin(): void
    {
        $this->assertRefused(['privacyAttested' => false], 'pris connaissance');

        $agent = UserBuilder::aUser()->build();
        $this->assertRefused(['origin' => RequestOrigin::Agent, 'enteredByUser' => $agent, 'enteredByMunicipality' => $this->organizer->municipality(), 'privacyAttested' => false], 'bénéficiaire a été informé');

        $request = $this->submit(['privacyNoticeVersion' => 'v0-provisoire']);
        self::assertEquals(EventFixtures::now(), $request->privacyAttestedAt());
        self::assertSame('v0-provisoire', $request->privacyNoticeVersion());
    }

    public function testOriginAndCreatorAreConsistent(): void
    {
        $agent = UserBuilder::aUser()->build();
        $municipality = $this->organizer->municipality();

        $request = $this->submit(['origin' => RequestOrigin::Agent, 'enteredByUser' => $agent, 'enteredByMunicipality' => $municipality]);
        self::assertSame($agent, $request->enteredByUser());
        self::assertSame($municipality, $request->enteredByMunicipality());

        $this->assertRefused(['origin' => RequestOrigin::Agent], 'créateur et sa commune');
        $this->assertRefused(['origin' => RequestOrigin::Agent, 'enteredByUser' => $agent], 'créateur et sa commune');
        $this->assertRefused(['origin' => RequestOrigin::Public, 'enteredByUser' => $agent], 'ni agent créateur');
        $this->assertRefused(['origin' => RequestOrigin::Public, 'enteredByMunicipality' => $municipality], 'ni agent créateur');
    }

    public function testAnAgentMayRequestSeveralMunicipalitiesWithoutAnyRightOnTheOthers(): void
    {
        $agent = UserBuilder::aUser()->build();
        $request = $this->submit([
            'origin' => RequestOrigin::Agent,
            'enteredByUser' => $agent,
            'enteredByMunicipality' => $this->organizer->municipality(),
            'territories' => [new RequestedTerritory($this->organizer, [$this->organizerZone]), new RequestedTerritory($this->partner, [$this->partnerZone])],
        ]);

        self::assertCount(2, $request->municipalities());
        self::assertSame([MunicipalRequestStatus::Pending, MunicipalRequestStatus::Pending], array_map(static fn ($m): MunicipalRequestStatus => $m->status(), $request->municipalities()));
    }

    public function testAtLeastOneMunicipalityAndOneZone(): void
    {
        $this->assertRefused(['territories' => []], 'au moins une commune et une zone');
        $this->assertRefused(['territories' => [new RequestedTerritory($this->organizer, [])]], 'au moins une zone');
    }

    public function testNoDuplicateMunicipalityNorZone(): void
    {
        $this->assertRefused(['territories' => [new RequestedTerritory($this->organizer, [$this->organizerZone]), new RequestedTerritory($this->organizer, [$this->organizerZone])]], 'qu\'une seule fois');
        $this->assertRefused(['territories' => [new RequestedTerritory($this->organizer, [$this->organizerZone, $this->organizerZone])]], 'Une zone ne peut être demandée qu\'une seule fois');
    }

    public function testEveryZoneBelongsToItsMunicipality(): void
    {
        $this->assertRefused(['territories' => [new RequestedTerritory($this->organizer, [$this->partnerZone])]], 'n\'appartient pas à la commune');
    }

    public function testEveryMunicipalityParticipatesInTheEventOfTheRequest(): void
    {
        $otherEvent = EventFixtures::active();
        $foreign = EventFixtures::organizerParticipation($otherEvent);

        $this->assertRefused(['territories' => [new RequestedTerritory($foreign, [EventFixtures::readyZone($foreign)])]], 'ne participe pas à cet événement');
    }

    /**
     * @return iterable<string, array{callable(self): EventZone, string}>
     */
    public static function nonProposableZones(): iterable
    {
        yield 'validité à configurer' => [static fn (self $t): EventZone => EventFixtures::zone($t->organizer), 'n\'est pas proposée'];
        yield 'suspendue' => [static function (self $t): EventZone {
            $zone = EventFixtures::readyZone($t->organizer);
            $zone->suspend(EventFixtures::now());

            return $zone;
        }, 'n\'est pas proposée'];
        yield 'supprimée' => [static function (self $t): EventZone {
            $zone = EventFixtures::readyZone($t->organizer);
            $zone->markDeleted(EventFixtures::now());

            return $zone;
        }, 'n\'est pas proposée'];
    }

    /**
     * @param callable(self): EventZone $zone
     */
    #[DataProvider('nonProposableZones')]
    public function testOnlyZonesThatMayOpenRightsAreProposable(callable $zone, string $message): void
    {
        $this->assertRefused(['territories' => [new RequestedTerritory($this->organizer, [$zone($this)])]], $message);
    }

    public function testAZoneOfANonOperationalParticipationIsRefused(): void
    {
        $this->partner->suspend('Test', EventFixtures::now());

        self::assertFalse($this->partner->isOperational());
        $this->assertRefused(['territories' => [new RequestedTerritory($this->partner, [$this->partnerZone])]], 'n\'est pas proposée');
    }

    public function testAttachmentsAreLimitedToFiveWithTheValidatedFormats(): void
    {
        $request = $this->submit();
        foreach (range(1, 5) as $i) {
            $request->attach('justificatif'.$i.'.pdf', 'application/pdf', 1024, 'requests/'.$i, EventFixtures::now());
        }
        self::assertCount(5, $request->attachments());

        try {
            $request->attach('sixieme.pdf', 'application/pdf', 1024, 'requests/6', EventFixtures::now());
            self::fail();
        } catch (DomainRuleViolation $e) {
            self::assertStringContainsString('au plus 5', $e->getMessage());
        }
        self::assertCount(5, $request->attachments());
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function invalidAttachments(): iterable
    {
        yield 'DOCX exclu' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 1024];
        yield 'type inconnu' => ['text/html', 1024];
        yield 'au-dessus de 10 Mo' => ['application/pdf', PermitRequestAttachment::MAX_SIZE_BYTES + 1];
        yield 'vide' => ['application/pdf', 0];
    }

    #[DataProvider('invalidAttachments')]
    public function testInvalidAttachmentsAreRefused(string $mime, int $size): void
    {
        $this->expectException(DomainRuleViolation::class);

        $this->submit()->attach('fichier', $mime, $size, 'requests/x', EventFixtures::now());
    }

    public function testValidatedFormatsAndExactMaximumSizeAreAccepted(): void
    {
        $request = $this->submit();
        foreach (['application/pdf', 'image/jpeg', 'image/png', 'application/msword'] as $i => $mime) {
            $request->attach('piece', $mime, PermitRequestAttachment::MAX_SIZE_BYTES, 'requests/'.$i, EventFixtures::now());
        }

        self::assertCount(4, $request->attachments());
    }
}
