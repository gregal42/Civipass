<?php

declare(strict_types=1);

namespace App\Tests\Unit\Permit;

use App\Permit\Domain\PublicVerdict;
use App\Permit\Domain\RightState as S;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Statut global de la vérification publique (LOT 05.4.1) : dérivé des états de zone, « Droits actifs » dès qu'une zone est valide.
 */
final class PublicVerdictTest extends TestCase
{
    /**
     * @return iterable<string, array{list<S>, PublicVerdict}>
     */
    public static function cases(): iterable
    {
        yield 'une zone valide' => [[S::Valid], PublicVerdict::ActiveRights];
        yield 'valide et révoquée : les droits actifs ne sont pas masqués' => [[S::Revoked, S::Valid], PublicVerdict::ActiveRights];
        yield 'valide malgré suspension, expiration et événement futur' => [[S::ZoneSuspended, S::Expired, S::NotYetValid, S::Valid], PublicVerdict::ActiveRights];
        yield 'à venir' => [[S::NotYetValid], PublicVerdict::Upcoming];
        yield 'hors créneau' => [[S::OutsidePeriod], PublicVerdict::Upcoming];
        yield 'à venir avant révoquée ou suspendue' => [[S::Revoked, S::CommuneInactive, S::NotYetValid], PublicVerdict::Upcoming];
        yield 'zone suspendue' => [[S::ZoneSuspended], PublicVerdict::Suspended];
        yield 'participation inactive' => [[S::ParticipationInactive], PublicVerdict::Suspended];
        yield 'commune inactive' => [[S::CommuneInactive], PublicVerdict::Suspended];
        yield 'événement suspendu' => [[S::EventNotActive], PublicVerdict::Suspended];
        yield 'suspendue avant expirée ou révoquée' => [[S::Expired, S::Revoked, S::ZoneSuspended], PublicVerdict::Suspended];
        yield 'événement clôturé' => [[S::EventClosed, S::EventClosed], PublicVerdict::EventClosed];
        yield 'toutes révoquées' => [[S::Revoked, S::Revoked], PublicVerdict::Revoked];
        yield 'toutes expirées' => [[S::Expired, S::Expired], PublicVerdict::Expired];
        yield 'états définitifs mélangés' => [[S::Expired, S::Revoked], PublicVerdict::NoActiveRights];
        yield 'clôturée et révoquée' => [[S::EventClosed, S::Revoked], PublicVerdict::NoActiveRights];
        yield 'aucune zone' => [[], PublicVerdict::NoActiveRights];
        yield 'zones retirées seulement' => [[S::Removed, S::Removed], PublicVerdict::NoActiveRights];
        yield 'retirée ignorée : expirées seulement' => [[S::Removed, S::Expired], PublicVerdict::Expired];
        yield 'retirée ignorée : valide' => [[S::Removed, S::Valid], PublicVerdict::ActiveRights];
    }

    /**
     * @param list<S> $states
     */
    #[DataProvider('cases')]
    public function testTheGlobalStatusIsDerivedFromTheZoneStates(array $states, PublicVerdict $expected): void
    {
        self::assertSame($expected, PublicVerdict::derive($states));
    }

    public function testThereAreExactlySevenStatuses(): void
    {
        self::assertCount(7, PublicVerdict::cases());
    }

    public function testNoOrderingOfTheZonesCanHideAnActiveRight(): void
    {
        $states = [S::Revoked, S::Expired, S::EventClosed, S::ZoneSuspended, S::NotYetValid, S::Valid];
        for ($i = 0; $i < \count($states); ++$i) {
            self::assertSame(PublicVerdict::ActiveRights, PublicVerdict::derive(array_merge(\array_slice($states, $i), \array_slice($states, 0, $i))));
        }
    }
}
