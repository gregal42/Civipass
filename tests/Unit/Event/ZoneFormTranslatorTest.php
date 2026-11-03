<?php

declare(strict_types=1);

namespace App\Tests\Unit\Event;

use App\Event\Domain\EventZone;
use App\Event\UI\Http\Form\ZoneFormData;
use App\Event\UI\Http\Form\ZoneFormTranslator;
use PHPUnit\Framework\TestCase;

final class ZoneFormTranslatorTest extends TestCase
{
    private ZoneFormTranslator $translator;

    protected function setUp(): void
    {
        $this->translator = new ZoneFormTranslator();
    }

    /**
     * @param array<string, mixed> $override
     */
    private function data(array $override = []): ZoneFormData
    {
        return ZoneFormData::fromPost($override + ['label' => ' Quartier du port ', 'type' => 'DISTRICT', 'description' => 'Desc']);
    }

    public function testACreationIsTranslatedWithATrimmedLabel(): void
    {
        [$command, $errors] = $this->translator->forCreate($this->data());

        self::assertSame([], $errors);
        self::assertNotNull($command);
        self::assertSame('Quartier du port', $command->label);
        self::assertSame('DISTRICT', $command->type);
        self::assertSame('Desc', $command->description);
        self::assertFalse($command->wholeMunicipalityConfirmed);
    }

    public function testTheLabelIsRequiredAndBounded(): void
    {
        [$command, $errors] = $this->translator->forCreate($this->data(['label' => '   ']));
        self::assertNull($command);
        self::assertArrayHasKey('label', $errors);

        [$command, $errors] = $this->translator->forCreate($this->data(['label' => str_repeat('a', EventZone::LABEL_MAX_LENGTH + 1)]));
        self::assertNull($command);
        self::assertArrayHasKey('label', $errors);

        [$command] = $this->translator->forCreate($this->data(['label' => str_repeat('a', EventZone::LABEL_MAX_LENGTH)]));
        self::assertNotNull($command);
    }

    public function testTheTypeMustBeKnown(): void
    {
        foreach (['', 'POLYGON', 'district'] as $type) {
            [$command, $errors] = $this->translator->forCreate($this->data(['type' => $type]));
            self::assertNull($command, $type);
            self::assertArrayHasKey('type', $errors, $type);
        }
    }

    public function testTheWholeMunicipalityNeedsAnExplicitConfirmation(): void
    {
        [$command, $errors] = $this->translator->forCreate($this->data(['type' => 'WHOLE_MUNICIPALITY']));
        self::assertNull($command);
        self::assertArrayHasKey('whole_confirmed', $errors);

        [$command, $errors] = $this->translator->forCreate($this->data(['type' => 'WHOLE_MUNICIPALITY', 'whole_confirmed' => '1']));
        self::assertSame([], $errors);
        self::assertTrue($command?->wholeMunicipalityConfirmed);
    }

    public function testAnUpdateOnlyCarriesTheLabelAndTheDescription(): void
    {
        [$command, $errors] = $this->translator->forUpdate($this->data(['type' => 'STREET', 'status' => 'SUSPENDED']));

        self::assertSame([], $errors);
        self::assertSame('Quartier du port', $command->label);
        self::assertSame('Desc', $command->description);
        $properties = array_map(static fn (\ReflectionProperty $p): string => $p->getName(), (new \ReflectionObject($command))->getProperties());
        self::assertSame(['label', 'description'], $properties, 'ni type (DM-080) ni état : seuls libellé et description');
    }

    public function testAnUpdateWithoutLabelIsRefused(): void
    {
        [$command, $errors] = $this->translator->forUpdate($this->data(['label' => '']));

        self::assertNull($command);
        self::assertArrayHasKey('label', $errors);
    }
}
