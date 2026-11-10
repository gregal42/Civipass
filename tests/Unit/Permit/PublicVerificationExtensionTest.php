<?php

declare(strict_types=1);

namespace App\Tests\Unit\Permit;

use App\Permit\Application\Read\PublicAuthorization;
use App\Permit\Application\Read\PublicPeriod;
use App\Permit\Application\Read\PublicVerification;
use App\Permit\Application\Read\PublicZone;
use App\Permit\Domain\PublicVerdict;
use App\Permit\Domain\RightState;
use App\Permit\UI\Twig\PublicVerificationExtension;
use PHPUnit\Framework\TestCase;

/**
 * Présentation de la vérification publique : un texte ET une icône par statut, bandeau vert / ambre / neutre selon N et M, périodes situées
 * par rapport à l'instant de la vérification, aucun état de zone oublié.
 */
final class PublicVerificationExtensionTest extends TestCase
{
    private function at(string $utc): \DateTimeImmutable
    {
        return new \DateTimeImmutable($utc, new \DateTimeZone('UTC'));
    }

    private function verification(PublicVerdict $verdict, int $active, int $total): PublicVerification
    {
        return new PublicVerification('LP-2026-0001', 'Événement', 'Europe/Paris', 'Martin', 'Camille', [], $verdict, $active, $total, [], $this->at('2026-06-12 10:00:00'));
    }

    public function testEveryVerdictHasATitleATextAnIconAndATone(): void
    {
        $extension = new PublicVerificationExtension();
        $titles = [];
        foreach (PublicVerdict::cases() as $verdict) {
            $view = $extension->verdict($this->verification($verdict, PublicVerdict::ActiveRights === $verdict ? 2 : 0, 2));
            self::assertNotSame('', $view['title']);
            self::assertNotSame('', $view['text']);
            self::assertNotSame('', $view['icon'], 'jamais la couleur seule : une icône');
            self::assertContains($view['tone'], ['valid', 'uncertain', 'invalid', 'impossible']);
            $titles[$verdict->value] = $view['title'].'|'.$view['icon'];
        }
        self::assertCount(7, array_unique($titles), 'sept statuts distincts (titre et icône)');
    }

    public function testTheBannerIsGreenWhenAllZonesAreActiveAmberWhenOnlySomeAreAndNeverGreenWhenNoneIs(): void
    {
        $extension = new PublicVerificationExtension();

        self::assertSame('valid', $extension->verdict($this->verification(PublicVerdict::ActiveRights, 3, 3))['tone']);
        $partial = $extension->verdict($this->verification(PublicVerdict::ActiveRights, 1, 3));
        self::assertSame('uncertain', $partial['tone']);
        self::assertSame('Droits actifs', $partial['title'], 'le statut global reste « Droits actifs »');
        foreach ([PublicVerdict::Upcoming, PublicVerdict::Suspended, PublicVerdict::EventClosed, PublicVerdict::Revoked, PublicVerdict::Expired, PublicVerdict::NoActiveRights] as $verdict) {
            self::assertNotSame('valid', $extension->verdict($this->verification($verdict, 0, 2))['tone']);
        }
    }

    public function testTheCounterIsAlwaysWritten(): void
    {
        $extension = new PublicVerificationExtension();

        self::assertSame('1 zone active sur 3', $extension->verdict($this->verification(PublicVerdict::ActiveRights, 1, 3))['count']);
        self::assertSame('2 zones actives sur 2', $extension->verdict($this->verification(PublicVerdict::ActiveRights, 2, 2))['count']);
        self::assertSame('0 zones actives sur 2', $extension->verdict($this->verification(PublicVerdict::Expired, 0, 2))['count']);
        self::assertSame('Aucune zone associée', $extension->verdict($this->verification(PublicVerdict::NoActiveRights, 0, 0))['count']);
    }

    public function testEveryZoneStateHasALabelAndAStatus(): void
    {
        $extension = new PublicVerificationExtension();
        foreach (RightState::cases() as $state) {
            $view = $extension->zone(new PublicZone('Zone', $state, [], $this->at('2026-06-12 09:00:00')), $this->at('2026-06-12 10:00:00'), 'Europe/Paris');
            self::assertNotSame('', $view['label'], $state->value);
            self::assertNotSame('', $view['status'], $state->value);
        }
    }

    public function testRemovedIsDistinctFromRevokedAndCarriesItsDate(): void
    {
        $extension = new PublicVerificationExtension();
        $at = $this->at('2026-06-12 10:00:00');

        $removed = $extension->zone(new PublicZone('Zone', RightState::Removed, [], $this->at('2026-06-12 07:45:00')), $at, 'Europe/Paris');
        $revoked = $extension->zone(new PublicZone('Zone', RightState::Revoked, [], null), $at, 'Europe/Paris');

        self::assertSame('Retirée', $removed['label']);
        self::assertSame('Retirée le 12/06/2026 à 09:45', $removed['detail'], 'date du retrait dans le fuseau de l\'événement');
        self::assertSame('Révoquée', $revoked['label']);
        self::assertNotSame($removed['label'], $revoked['label']);
        self::assertSame([], $removed['periods'], 'aucune période pour une zone retirée');
    }

    public function testSeveralPeriodsAreSituatedAgainstTheVerificationInstant(): void
    {
        $extension = new PublicVerificationExtension();
        $periods = [
            new PublicPeriod($this->at('2026-06-10 07:00:00'), $this->at('2026-06-10 15:00:00')),
            new PublicPeriod($this->at('2026-06-12 07:00:00'), $this->at('2026-06-12 15:00:00')),
            new PublicPeriod($this->at('2026-06-14 07:00:00'), $this->at('2026-06-15 15:00:00')),
        ];

        $view = $extension->zone(new PublicZone('Zone', RightState::Valid, $periods, null), $this->at('2026-06-12 10:00:00'), 'Europe/Paris');

        self::assertSame('Valide actuellement', $view['label']);
        self::assertSame('Jusqu’au 12/06/2026 à 17:00', $view['detail']);
        self::assertSame(['past', 'current', 'future'], array_column($view['periods'], 'phase'));
        self::assertSame(['Passée', 'En cours', 'À venir'], array_column($view['periods'], 'phaseLabel'));
        self::assertSame('Le 12/06/2026 de 09:00 à 17:00', $view['periods'][1]['text']);
        self::assertSame('Du 14/06/2026 à 09:00 au 15/06/2026 à 17:00', $view['periods'][2]['text']);
    }

    public function testAPeriodOfAnInactiveRightIsNeverPresentedAsInProgress(): void
    {
        $extension = new PublicVerificationExtension();
        $periods = [new PublicPeriod($this->at('2026-06-12 07:00:00'), $this->at('2026-06-12 15:00:00'))];

        $view = $extension->zone(new PublicZone('Zone', RightState::Revoked, $periods, null), $this->at('2026-06-12 10:00:00'), 'Europe/Paris');

        self::assertSame('planned', $view['periods'][0]['phase']);
        self::assertStringNotContainsString('En cours', $view['periods'][0]['phaseLabel']);
        self::assertSame('Révoquée', $view['label']);
    }

    public function testTheNextPeriodIsAnnouncedForAnUpcomingZone(): void
    {
        $extension = new PublicVerificationExtension();
        $periods = [new PublicPeriod($this->at('2026-06-14 07:00:00'), null)];

        $view = $extension->zone(new PublicZone('Zone', RightState::NotYetValid, $periods, null), $this->at('2026-06-12 10:00:00'), 'Europe/Paris');

        self::assertSame('À partir du 14/06/2026 à 09:00', $view['detail']);
        self::assertSame('À partir du 14/06/2026 à 09:00, jusqu’à la clôture de l’événement', $view['periods'][0]['text']);
    }

    public function testTheAuthorizationWindowAndTimezoneAreWritten(): void
    {
        $extension = new PublicVerificationExtension();

        self::assertSame('Autorisation à partir du 12/06/2026 à 11:00, jusqu’à la clôture de l’événement', $extension->window($this->at('2026-06-12 09:00:00'), null, 'Europe/Paris'));
        self::assertSame('heure de Paris', $extension->timezone('Europe/Paris'));
        self::assertSame('heure Pacific/Noumea', $extension->timezone('Pacific/Noumea'));
        self::assertInstanceOf(PublicAuthorization::class, new PublicAuthorization('C', $this->at('2026-06-12 09:00:00'), null, null, []));
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function dstPeriods(): iterable
    {
        yield 'hiver (CET, +1)' => ['2026-01-15 08:00:00', '2026-01-15 16:00:00', 'Le 15/01/2026 de 09:00 à 17:00'];
        yield 'été (CEST, +2)' => ['2026-07-15 07:00:00', '2026-07-15 15:00:00', 'Le 15/07/2026 de 09:00 à 17:00'];
        yield 'jour du passage à l\'heure d\'été' => ['2026-03-29 07:00:00', '2026-03-29 15:00:00', 'Le 29/03/2026 de 09:00 à 17:00'];
        yield 'créneau enjambant le passage à l\'heure d\'été' => ['2026-03-28 23:00:00', '2026-03-29 02:00:00', 'Le 29/03/2026 de 00:00 à 04:00'];
        yield 'jour du retour à l\'heure d\'hiver' => ['2026-10-25 08:00:00', '2026-10-25 16:00:00', 'Le 25/10/2026 de 09:00 à 17:00'];
        yield 'créneau enjambant le retour à l\'heure d\'hiver' => ['2026-10-24 22:00:00', '2026-10-25 03:00:00', 'Le 25/10/2026 de 00:00 à 04:00'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('dstPeriods')]
    public function testPeriodsAreWrittenInTheEventLocalTimeWhateverTheSeasonOrDstChange(string $fromUtc, string $untilUtc, string $expected): void
    {
        $view = (new PublicVerificationExtension())->zone(
            new PublicZone('Zone', RightState::Valid, [new PublicPeriod($this->at($fromUtc), $this->at($untilUtc))], null),
            $this->at('2026-01-01 00:00:00'),
            'Europe/Paris',
        );

        self::assertSame($expected, $view['periods'][0]['text']);
    }

    public function testAnotherTimezoneWithoutDstIsRespected(): void
    {
        $view = (new PublicVerificationExtension())->zone(
            new PublicZone('Zone', RightState::Valid, [new PublicPeriod($this->at('2026-07-15 05:00:00'), $this->at('2026-07-15 13:00:00'))], null),
            $this->at('2026-01-01 00:00:00'),
            'Indian/Reunion',
        );

        self::assertSame('Le 15/07/2026 de 09:00 à 17:00', $view['periods'][0]['text'], 'La Réunion : UTC+4 toute l\'année');
    }
}
