<?php

declare(strict_types=1);

namespace App\Tests\Unit\Event;

use App\Event\Application\ParticipantListVisibility;
use App\Event\Application\Read\EventDetail;
use App\Event\Application\Read\EventRelation;
use App\Event\Application\Read\ParticipantList;
use App\Event\Domain\EventStatus;
use App\Event\Domain\Time\EventTimezone;
use App\Event\UI\Http\Form\EventFormData;
use App\Event\UI\Http\Form\EventFormTranslator;
use PHPUnit\Framework\TestCase;

final class EventFormTranslatorTest extends TestCase
{
    private const TYPES = ['fire', 'flood'];

    /**
     * @param array<string, mixed> $override
     */
    private function data(array $override = []): EventFormData
    {
        return EventFormData::fromPost($override + ['name' => ' Incendie ', 'type' => 'fire', 'description' => 'Desc', 'instructions' => 'Cons', 'start_date' => '2026-12-01', 'start_time' => '09:00', 'end_date' => '', 'end_time' => '']);
    }

    private function detail(EventStatus $status = EventStatus::Draft, string $timezone = 'Europe/Paris', string $start = '2026-12-01 08:00:00', ?string $end = null): EventDetail
    {
        return new EventDetail('e', 'EVT-2026-000001', 'Incendie', 'fire', 'Incendie', $status, 'o', 'Org', new \DateTimeImmutable($start, new \DateTimeZone('UTC')), null === $end ? null : new \DateTimeImmutable($end, new \DateTimeZone('UTC')), $timezone, EventRelation::Organizer, null, 'Desc', 'Cons', null, new ParticipantList(ParticipantListVisibility::None), [], null);
    }

    public function testCreateConvertsLocalTimesToUtcInTheMunicipalityTimezone(): void
    {
        $translator = new EventFormTranslator();

        $winter = $translator->forCreate($this->data(), EventTimezone::fromIdentifier('Europe/Paris'), self::TYPES);
        self::assertTrue($winter->isValid());
        self::assertSame('2026-12-01T08:00:00+00:00', $winter->command?->startsAt->format('c'));
        self::assertSame('Incendie', $winter->command->name, 'nom nettoyé');
        self::assertNull($winter->command->timezone, 'déduit : jamais transmis par le client');

        $summer = $translator->forCreate($this->data(['start_date' => '2027-07-14']), EventTimezone::fromIdentifier('Europe/Paris'), self::TYPES);
        self::assertSame('2027-07-14T07:00:00+00:00', $summer->command?->startsAt->format('c'));

        $island = $translator->forCreate($this->data(['end_date' => '2026-12-02', 'end_time' => '20:00']), EventTimezone::fromIdentifier('America/Martinique'), self::TYPES);
        self::assertSame('2026-12-01T13:00:00+00:00', $island->command?->startsAt->format('c'));
        self::assertSame('2026-12-03T00:00:00+00:00', $island->command->endsAt?->format('c'));
    }

    public function testCreateWithoutADeducedTimezoneRequiresAKnownOne(): void
    {
        $translator = new EventFormTranslator();

        self::assertArrayHasKey('timezone', $translator->forCreate($this->data(), null, self::TYPES)->errors);
        self::assertArrayHasKey('timezone', $translator->forCreate($this->data(['timezone' => 'Mars/Olympus']), null, self::TYPES)->errors);
        $ok = $translator->forCreate($this->data(['timezone' => 'Pacific/Tahiti']), null, self::TYPES);
        self::assertTrue($ok->isValid());
        self::assertSame('Pacific/Tahiti', $ok->command?->timezone);
        self::assertSame('2026-12-01T19:00:00+00:00', $ok->command->startsAt->format('c'));
        // Un fuseau fourni alors qu'il est déduit est ignoré.
        self::assertNull($translator->forCreate($this->data(['timezone' => 'Pacific/Tahiti']), EventTimezone::fromIdentifier('Europe/Paris'), self::TYPES)->command?->timezone);
    }

    public function testCreateChecksRequiredFieldsAndFormats(): void
    {
        $translator = new EventFormTranslator();
        $paris = EventTimezone::fromIdentifier('Europe/Paris');

        $empty = $translator->forCreate(EventFormData::fromPost([]), $paris, self::TYPES);
        self::assertSame(['name', 'type', 'start_date', 'start_time'], array_keys($empty->errors));
        self::assertNull($empty->command);

        self::assertArrayHasKey('name', $translator->forCreate($this->data(['name' => str_repeat('x', 201)]), $paris, self::TYPES)->errors);
        self::assertArrayHasKey('type', $translator->forCreate($this->data(['type' => 'meteorite']), $paris, self::TYPES)->errors);
        self::assertArrayHasKey('start_date', $translator->forCreate($this->data(['start_date' => '2026-02-30']), $paris, self::TYPES)->errors);
        self::assertArrayHasKey('start_time', $translator->forCreate($this->data(['start_time' => '24:00']), $paris, self::TYPES)->errors);
        self::assertArrayHasKey('end_time', $translator->forCreate($this->data(['end_date' => '2026-12-02']), $paris, self::TYPES)->errors, 'fin incomplète');
        self::assertArrayHasKey('end_date', $translator->forCreate($this->data(['end_time' => '10:00']), $paris, self::TYPES)->errors);
        self::assertSame('', EventFormData::fromPost(['name' => ['x']])->name, 'valeur non chaîne : chaîne vide');
    }

    public function testDstNonexistentIsRefusedAndRepeatedRequiresAnExplicitChoice(): void
    {
        $translator = new EventFormTranslator();
        $paris = EventTimezone::fromIdentifier('Europe/Paris');

        $gap = $translator->forCreate($this->data(['start_date' => '2027-03-28', 'start_time' => '02:30']), $paris, self::TYPES);
        self::assertArrayHasKey('start_time', $gap->errors);
        self::assertStringContainsString('02:30', $gap->errors['start_time']);
        self::assertSame([], $gap->choices);

        $repeated = $translator->forCreate($this->data(['start_date' => '2026-10-25', 'start_time' => '02:30']), $paris, self::TYPES);
        self::assertArrayHasKey('start_occurrence', $repeated->errors);
        self::assertSame(['first', 'second'], array_column($repeated->choices['start'], 'value'));
        self::assertSame(['Première occurrence (UTC+02:00)', 'Seconde occurrence (UTC+01:00)'], array_column($repeated->choices['start'], 'label'));

        $first = $translator->forCreate($this->data(['start_date' => '2026-10-25', 'start_time' => '02:30', 'start_occurrence' => 'first']), $paris, self::TYPES);
        $second = $translator->forCreate($this->data(['start_date' => '2026-10-25', 'start_time' => '02:30', 'start_occurrence' => 'second']), $paris, self::TYPES);
        self::assertSame('2026-10-25T00:30:00+00:00', $first->command?->startsAt->format('c'));
        self::assertSame('2026-10-25T01:30:00+00:00', $second->command?->startsAt->format('c'));
        self::assertArrayHasKey('start_occurrence', $translator->forCreate($this->data(['start_date' => '2026-10-25', 'start_time' => '02:30', 'start_occurrence' => 'maybe']), $paris, self::TYPES)->errors);
    }

    public function testPrefillShowsTheEventInItsOwnTimezoneAndRecoversTheOccurrence(): void
    {
        $translator = new EventFormTranslator();

        $normal = $translator->prefill($this->detail(end: '2026-12-03 17:30:00'));
        self::assertSame(['2026-12-01', '09:00', '2026-12-03', '18:30', 'Europe/Paris', '', ''], [$normal->startDate, $normal->startTime, $normal->endDate, $normal->endTime, $normal->timezone, $normal->startOccurrence, $normal->endOccurrence]);
        self::assertSame('Cons', $normal->instructions);

        $second = $translator->prefill($this->detail(start: '2026-10-25 01:30:00'));
        self::assertSame(['2026-10-25', '02:30', 'second'], [$second->startDate, $second->startTime, $second->startOccurrence]);
        $first = $translator->prefill($this->detail(start: '2026-10-25 00:30:00'));
        self::assertSame('first', $first->startOccurrence);

        $island = $translator->prefill($this->detail(timezone: 'America/Martinique', start: '2026-12-01 13:00:00'));
        self::assertSame(['2026-12-01', '09:00'], [$island->startDate, $island->startTime]);
    }

    public function testUpdateForwardsLockedFieldsSoThatTheDomainCanRefuseThem(): void
    {
        $translator = new EventFormTranslator();
        $active = $this->detail(EventStatus::Active);

        $forged = $translator->forUpdate(EventFormData::fromPost(['name' => 'Nom', 'type' => 'flood', 'timezone' => 'America/Martinique', 'start_date' => '2026-12-02', 'start_time' => '09:00']), $active, self::TYPES);

        self::assertTrue($forged->isValid());
        self::assertSame('flood', $forged->command?->typeCode, 'transmis tel quel : le domaine refuse (jamais ignoré en silence)');
        self::assertSame('America/Martinique', $forged->command->timezone);
        self::assertSame('2026-12-02T08:00:00+00:00', $forged->command->startsAt?->format('c'), 'horaire converti dans le fuseau ACTUEL (verrouillé)');
    }

    public function testUpdateWithoutLockedFieldsLeavesThemUnchangedAndClearsAnEmptyEnd(): void
    {
        $translator = new EventFormTranslator();

        $command = $translator->forUpdate(EventFormData::fromPost(['name' => 'Nom', 'description' => '', 'instructions' => 'X', 'end_date' => '', 'end_time' => '']), $this->detail(EventStatus::Active, end: '2026-12-03 17:30:00'), self::TYPES)->command;

        self::assertNotNull($command);
        self::assertNull($command->typeCode);
        self::assertNull($command->timezone);
        self::assertNull($command->startsAt);
        self::assertTrue($command->clearEndsAt, 'fin laissée vide : effacée');
        self::assertSame('', $command->description, 'description vidée explicitement');

        $withEnd = $translator->forUpdate(EventFormData::fromPost(['name' => 'Nom', 'end_date' => '2026-12-05', 'end_time' => '10:00']), $this->detail(EventStatus::Active), self::TYPES)->command;
        self::assertFalse($withEnd?->clearEndsAt);
        self::assertSame('2026-12-05T09:00:00+00:00', $withEnd->endsAt?->format('c'));
    }

    public function testUpdateInDraftConvertsInTheNewlyChosenTimezone(): void
    {
        $command = (new EventFormTranslator())->forUpdate(EventFormData::fromPost(['name' => 'Nom', 'timezone' => 'America/Martinique', 'start_date' => '2026-12-01', 'start_time' => '09:00']), $this->detail(), self::TYPES)->command;

        self::assertSame('2026-12-01T13:00:00+00:00', $command?->startsAt?->format('c'));
        self::assertSame('America/Martinique', $command->timezone);
    }

    public function testUpdateRejectsMalformedInput(): void
    {
        $translator = new EventFormTranslator();
        $current = $this->detail();

        self::assertArrayHasKey('name', $translator->forUpdate(EventFormData::fromPost(['name' => '']), $current, self::TYPES)->errors);
        self::assertArrayHasKey('type', $translator->forUpdate(EventFormData::fromPost(['name' => 'N', 'type' => 'inconnu']), $current, self::TYPES)->errors);
        self::assertArrayHasKey('timezone', $translator->forUpdate(EventFormData::fromPost(['name' => 'N', 'timezone' => 'Mars/Olympus']), $current, self::TYPES)->errors);
        self::assertArrayHasKey('start_time', $translator->forUpdate(EventFormData::fromPost(['name' => 'N', 'start_date' => '2026-12-01']), $current, self::TYPES)->errors);
        self::assertArrayHasKey('start_date', $translator->forUpdate(EventFormData::fromPost(['name' => 'N', 'start_date' => '31/12/2026', 'start_time' => '09:00']), $current, self::TYPES)->errors);
    }
}
