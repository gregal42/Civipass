<?php

declare(strict_types=1);

namespace App\Tests\Unit\Event;

use App\Event\Domain\EventType;
use App\Shared\Domain\DomainRuleViolation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EventTypeTest extends TestCase
{
    public function testConstruction(): void
    {
        $type = new EventType('storm', ' Tempête / événement climatique ', 30);

        self::assertSame('storm', $type->code());
        self::assertSame('Tempête / événement climatique', $type->label());
        self::assertSame(30, $type->sortOrder());
        self::assertTrue($type->isActive());
        self::assertNotEmpty($type->id()->toRfc4122());
    }

    #[DataProvider('invalidCodes')]
    public function testCodeIsStrict(string $code): void
    {
        $this->expectException(DomainRuleViolation::class);
        new EventType($code, 'Libellé');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidCodes(): iterable
    {
        yield 'vide' => [''];
        yield 'majuscules' => ['Fire'];
        yield 'espace' => ['fire fighting'];
        yield 'accent' => ['incendié'];
        yield 'tiret' => ['fire-works'];
        yield 'trop long' => [str_repeat('a', 41)];
    }

    public function testCodeIsImmutable(): void
    {
        $methods = array_map(static fn (\ReflectionMethod $m): string => $m->getName(), (new \ReflectionClass(EventType::class))->getMethods(\ReflectionMethod::IS_PUBLIC));

        foreach ($methods as $method) {
            self::assertDoesNotMatchRegularExpression('/(set|change|rename)Code|^recode$/i', $method);
        }
    }

    public function testLabelOrderAndActivationEvolve(): void
    {
        $type = new EventType('other', 'Autre');

        $type->relabel(' Autre événement ');
        $type->reorder(90);
        $type->deactivate();

        self::assertSame('Autre événement', $type->label());
        self::assertSame(90, $type->sortOrder());
        self::assertFalse($type->isActive());
        $type->activate();
        self::assertTrue($type->isActive());
    }

    public function testRefusesBlankLabelAndOutOfRangeOrder(): void
    {
        foreach ([fn () => new EventType('fire', '  '), fn () => new EventType('fire', str_repeat('x', 101)), fn () => new EventType('fire', 'Incendie', -1), fn () => new EventType('fire', 'Incendie', 40000)] as $build) {
            try {
                $build();
                self::fail('Valeur invalide acceptée.');
            } catch (DomainRuleViolation) {
                self::addToAssertionCount(1);
            }
        }
    }
}
