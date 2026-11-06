<?php

declare(strict_types=1);

namespace App\Tests\Integration\Request;

use App\Event\Domain\Event;
use App\Event\Domain\EventParticipation;
use App\Event\Domain\EventZone;
use App\Identity\Domain\EmailAddress;
use App\Request\Domain\BeneficiaryCategory;
use App\Request\Domain\MunicipalRequestStatus;
use App\Request\Domain\PermitRequest;
use App\Request\Domain\PlateFormat;
use App\Request\Domain\RequestedTerritory;
use App\Request\Domain\RequestMotif;
use App\Request\Domain\RequestOrigin;
use App\Request\Domain\RequestReference;
use App\Request\Domain\TravelMode;
use App\Tests\Support\EventFixtures as F;
use App\Tests\Support\MunicipalityBuilder;
use App\Tests\Support\RequestSchemaTestCase;
use App\Tests\Support\UserBuilder;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use PHPUnit\Framework\Attributes\Group;

#[Group('requires-request-schema')]
final class RequestPersistenceTest extends RequestSchemaTestCase
{
    private Event $event;
    private EventParticipation $organizer;
    private EventParticipation $partner;
    private EventZone $organizerZone;
    private EventZone $partnerZone;
    private BeneficiaryCategory $category;
    private RequestMotif $motif;

    protected function setUp(): void
    {
        parent::setUp();
        $organizerMunicipality = MunicipalityBuilder::aMunicipality()->build();
        $creator = UserBuilder::aUser()->build();
        $type = F::type();
        $this->event = F::draft($organizerMunicipality, $creator, $type);
        $this->event->activate(F::now());
        $this->organizer = F::organizerParticipation($this->event);
        $partnerMunicipality = MunicipalityBuilder::aMunicipality()->withInseeCode('33527')->build();
        $inviter = UserBuilder::aUser()->build();
        $this->partner = F::invited($this->event, $partnerMunicipality, $inviter);
        $this->partner->accept(F::now());
        $this->organizerZone = F::readyZone($this->organizer);
        $this->partnerZone = F::readyZone($this->partner);
        $this->category = new BeneficiaryCategory('private_person', 'Particuliers', 10);
        $this->motif = new RequestMotif('home', 'Accès au domicile', 10);
        $this->save(
            $organizerMunicipality,
            $partnerMunicipality,
            $creator,
            $inviter,
            $type,
            $this->event,
            $this->organizer,
            $this->partner,
            $this->organizerZone->createdBy(),
            $this->organizerZone,
            $this->partnerZone->createdBy(),
            $this->partnerZone,
            $this->category,
            $this->motif,
        );
    }

    private function submit(string $reference = 'DEM-2026-0001'): PermitRequest
    {
        $agent = UserBuilder::aUser()->build();
        $this->em->persist($agent);
        $request = PermitRequest::submit(
            RequestReference::fromString($reference),
            $this->event,
            RequestOrigin::Agent,
            'Fabre',
            'Lucie',
            null,
            EmailAddress::fromString('lucie@exemple.fr'),
            null,
            $this->category,
            $this->motif,
            null,
            TravelMode::Vehicle,
            ['GH-482-KT', '4512 TB 33'],
            [new RequestedTerritory($this->organizer, [$this->organizerZone]), new RequestedTerritory($this->partner, [$this->partnerZone])],
            true,
            null,
            $agent,
            $this->organizer->municipality(),
            F::now(),
        );
        $request->attach('attestation.pdf', 'application/pdf', 2048, 'requests/abc123', F::now());
        $this->em->persist($request);
        $this->em->flush();

        return $request;
    }

    public function testTheWholeRequestIsPersistedAndReloaded(): void
    {
        $id = $this->submit()->id();
        $this->em->clear();

        $request = $this->em->find(PermitRequest::class, $id);
        self::assertNotNull($request);
        self::assertSame('DEM-2026-0001', $request->reference()->value);
        self::assertSame(RequestOrigin::Agent, $request->origin());
        self::assertNotNull($request->enteredByUser());
        self::assertSame('Particuliers', $request->categoryLabel());
        self::assertSame(TravelMode::Vehicle, $request->travelMode());
        self::assertEquals(F::now(), $request->privacyAttestedAt());
        self::assertSame(['GH482KT', '4512TB33'], array_map(static fn ($v): string => $v->registration(), $request->vehicles()));
        self::assertSame([PlateFormat::Siv, PlateFormat::Fni], array_map(static fn ($v): PlateFormat => $v->format(), $request->vehicles()));
        self::assertCount(1, $request->attachments());

        $parts = $request->municipalities();
        self::assertCount(2, $parts);
        foreach ($parts as $part) {
            self::assertSame(MunicipalRequestStatus::Pending, $part->status());
            self::assertCount(1, $part->zones());
            self::assertSame($part->participation()->municipality()->id()->toRfc4122(), $part->municipality()->id()->toRfc4122());
        }
    }

    public function testReferenceIsUnique(): void
    {
        $this->submit('DEM-2026-0007');

        $this->expectException(UniqueConstraintViolationException::class);
        $this->submit('DEM-2026-0007');
    }

    public function testAReferentialEntryUsedByARequestCannotBeDeleted(): void
    {
        $this->submit();

        $this->expectException(ForeignKeyConstraintViolationException::class);
        $this->em->getConnection()->executeStatement('DELETE FROM request_motif');
    }

    public function testAnEventWithRequestsCannotBeDeleted(): void
    {
        $this->submit();

        $this->expectException(ForeignKeyConstraintViolationException::class);
        $this->em->getConnection()->executeStatement('DELETE FROM event_event');
    }
}
