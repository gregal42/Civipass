<?php

declare(strict_types=1);

namespace App\Tests\Unit\Event;

use App\Event\Application\Read\SlotView;
use App\Event\Domain\Validity\ValidityKind;
use App\Event\UI\Twig\ValidityExtension;
use PHPUnit\Framework\TestCase;

final class ValidityExtensionTest extends TestCase
{
    private ValidityExtension $extension;

    protected function setUp(): void
    {
        $this->extension = new ValidityExtension();
    }

    public function testTheSummariesDescribeTheLocalTimes(): void
    {
        $continuous = new SlotView('a', ValidityKind::Continuous, '2026-12-01', '08:00', '2026-12-02', '18:00');
        $punctual = new SlotView('b', ValidityKind::Punctual, '2026-12-05', '09:30', null, '11:00');
        $someDays = new SlotView('c', ValidityKind::Recurring, '2026-12-07', '07:00', '2026-12-18', '19:00', [2]);
        $threeDays = new SlotView('d', ValidityKind::Recurring, '2026-12-07', '07:00', '2026-12-18', '19:00', [1, 3, 5]);
        $everyDay = new SlotView('e', ValidityKind::Recurring, '2026-12-07', '22:00', '2026-12-18', '06:00', [1, 2, 3, 4, 5, 6, 7]);

        self::assertSame('Du 01/12/2026 à 08:00 au 02/12/2026 à 18:00, sans interruption.', $this->extension->summary($continuous));
        self::assertSame('Le 05/12/2026, de 09:30 à 11:00.', $this->extension->summary($punctual));
        self::assertSame('Les mardis, de 07:00 à 19:00, du 07/12/2026 au 18/12/2026.', $this->extension->summary($someDays));
        self::assertSame('Les lundis, mercredis et vendredis, de 07:00 à 19:00, du 07/12/2026 au 18/12/2026.', $this->extension->summary($threeDays));
        self::assertSame('Tous les jours, de 22:00 à 06:00 (le lendemain), du 07/12/2026 au 18/12/2026.', $this->extension->summary($everyDay));
    }

    public function testTheModeMatchesTheSlotComponent(): void
    {
        self::assertSame('continuous', $this->extension->mode(new SlotView('a', ValidityKind::Continuous, '2026-12-01', '08:00', '2026-12-02', '18:00')));
        self::assertSame('single', $this->extension->mode(new SlotView('b', ValidityKind::Punctual, '2026-12-05', '09:30', null, '11:00')));
        self::assertSame('recurring', $this->extension->mode(new SlotView('c', ValidityKind::Recurring, '2026-12-07', '07:00', '2026-12-18', '19:00', [1])));
    }

    public function testTheNotesMentionMidnightAndTheChosenOccurrences(): void
    {
        $plain = new SlotView('a', ValidityKind::Punctual, '2026-12-05', '09:30', null, '11:00');
        self::assertSame('', $this->extension->note($plain));

        $overnight = new SlotView('b', ValidityKind::Recurring, '2026-12-07', '22:00', '2026-12-18', '06:00', [1]);
        self::assertStringContainsString('passe minuit', $this->extension->note($overnight));

        $dst = new SlotView('c', ValidityKind::Punctual, '2026-10-25', '02:30', null, '04:00', [], [['date' => '2026-10-25', 'field' => 'start', 'occurrence' => 'second']]);
        $note = $this->extension->note($dst);
        self::assertStringContainsString('le 25/10/2026, 02:30 (début)', $note);
        self::assertStringContainsString('la deuxième occurrence est retenue', $note);
    }
}
