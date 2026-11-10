<?php

declare(strict_types=1);

namespace App\Tests\Unit\Permit;

use App\Event\Domain\EventStatus;
use App\Permit\Domain\LabelCorrectionReason;
use App\Permit\Domain\ZoneAddReason;
use App\Permit\Domain\ZoneCandidate;
use App\Permit\Domain\ZoneChangeKind;
use App\Permit\Domain\ZoneChangePlanner;
use App\Permit\Domain\ZoneReinstatementReason;
use App\Permit\Domain\ZoneRemovalReason;
use App\Permit\Domain\ZoneRowState;
use App\Shared\Domain\DomainRuleViolation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Règles de zones d'une autorisation (LOT 05.5.3, arbitrages du 10 novembre 2026) : état final jamais vide, opérations groupées atomiques, ajout et réintégration
 * refusés pendant une suspension, retrait et correction permis, zone suspendue rattachée, zone d'une autre commune ou non proposable refusée, une zone retirée se
 * réintègre (jamais « ajoutée »), correction par synchronisation seulement.
 */
final class ZoneChangePlannerTest extends TestCase
{
    private ZoneChangePlanner $planner;
    private Uuid $north;
    private Uuid $south;
    private Uuid $east;
    private Uuid $west;

    protected function setUp(): void
    {
        $this->planner = new ZoneChangePlanner();
        [$this->north, $this->south, $this->east, $this->west] = [Uuid::v7(), Uuid::v7(), Uuid::v7(), Uuid::v7()];
    }

    /** @return list<ZoneRowState> Nord et Sud rattachées, Est retirée */
    private function rows(): array
    {
        return [new ZoneRowState($this->north, 'Zone nord', false), new ZoneRowState($this->south, 'Zone sud', false), new ZoneRowState($this->east, 'Zone est', true)];
    }

    /** @return array<string, ZoneCandidate> */
    private function candidates(bool $own = true, bool $proposable = true): array
    {
        return [
            $this->east->toRfc4122() => new ZoneCandidate($this->east, 'Zone est (rénovée)', $own, $proposable),
            $this->west->toRfc4122() => new ZoneCandidate($this->west, 'Zone ouest', $own, $proposable),
        ];
    }

    private function refused(callable $operation, string $contains = ''): void
    {
        try {
            $operation();
            self::fail('Un refus (DomainRuleViolation) est attendu.');
        } catch (DomainRuleViolation $e) {
            self::addToAssertionCount(1);
            if ('' !== $contains) {
                self::assertStringContainsString($contains, $e->getMessage());
            }
        }
    }

    // ------------------------------------------------------------------ opérations simples

    public function testAddingAProposableNewZoneGivesTheCurrentLabelAndOneMoreAttachedZone(): void
    {
        $plan = $this->planner->change($this->rows(), [$this->west], [], [], $this->candidates(), false, EventStatus::Active);

        self::assertCount(1, $plan->changes);
        self::assertSame([ZoneChangeKind::Added, 'Zone ouest', null], [$plan->changes[0]->kind, $plan->changes[0]->label, $plan->changes[0]->previousLabel]);
        self::assertSame(3, $plan->attachedAfter);
        self::assertFalse($plan->hasRemoval());
    }

    public function testRemovingAZoneKeepsTheCopiedLabelAndMarksARemoval(): void
    {
        $plan = $this->planner->change($this->rows(), [], [$this->south], [], [], false, EventStatus::Active);

        self::assertSame([ZoneChangeKind::Removed, 'Zone sud'], [$plan->changes[0]->kind, $plan->changes[0]->label]);
        self::assertSame(1, $plan->attachedAfter);
        self::assertTrue($plan->hasRemoval());
    }

    public function testReinstatingARemovedZoneCopiesTheCurrentLabelAndKeepsThePreviousOne(): void
    {
        $plan = $this->planner->change($this->rows(), [], [], [$this->east], $this->candidates(), false, EventStatus::Active);

        self::assertSame([ZoneChangeKind::Reinstated, 'Zone est (rénovée)', 'Zone est'], [$plan->changes[0]->kind, $plan->changes[0]->label, $plan->changes[0]->previousLabel]);
        self::assertSame(3, $plan->attachedAfter);
    }

    // ------------------------------------------------------------------ dernière zone (D4) et état final

    public function testTheLastAttachedZoneCannotBeRemoved(): void
    {
        $rows = [new ZoneRowState($this->north, 'Zone nord', false), new ZoneRowState($this->east, 'Zone est', true)];

        $this->refused(fn () => $this->planner->change($rows, [], [$this->north], [], [], false, EventStatus::Active), 'révoquez');
    }

    public function testRemovingEveryZoneInOneGroupedOperationIsRefusedOnTheFinalState(): void
    {
        $this->refused(fn () => $this->planner->change($this->rows(), [], [$this->north, $this->south], [], [], false, EventStatus::Active), 'au moins une zone');
    }

    public function testAGroupedOperationThatRemovesAllButAddsOneIsAcceptedOnTheFinalState(): void
    {
        $plan = $this->planner->change($this->rows(), [$this->west], [$this->north, $this->south], [$this->east], $this->candidates(), false, EventStatus::Active);

        self::assertSame(2, $plan->attachedAfter, 'état final : Est réintégrée et Ouest ajoutée');
        self::assertSame(
            [ZoneChangeKind::Removed, ZoneChangeKind::Removed, ZoneChangeKind::Reinstated, ZoneChangeKind::Added],
            array_map(static fn ($c): ZoneChangeKind => $c->kind, $plan->changes),
            'ordre d\'écriture : retraits, réintégrations, ajouts',
        );
        self::assertSame(['Zone nord', 'Zone sud'], array_map(static fn ($c): string => $c->label, \array_slice($plan->changes, 0, 2)), 'chaque catégorie par libellé');
        self::assertTrue($plan->hasRemoval(), 'une opération mixte comporte un retrait');
    }

    public function testASuspendedZoneStaysAttachedSoItCountsForTheLastZoneRule(): void
    {
        // La zone « suspendue » est une ligne rattachée comme une autre : le planificateur ne connaît pas l'état opérationnel de la zone de l'événement.
        $rows = [new ZoneRowState($this->north, 'Zone nord (suspendue)', false), new ZoneRowState($this->south, 'Zone sud', false)];

        $plan = $this->planner->change($rows, [], [$this->south], [], [], false, EventStatus::Active);

        self::assertSame(1, $plan->attachedAfter, 'la zone suspendue compte comme rattachée : on peut retirer l\'autre');
    }

    public function testAnEmptyOperationAndADuplicatedZoneAreRefused(): void
    {
        $this->refused(fn () => $this->planner->change($this->rows(), [], [], [], [], false, EventStatus::Active), 'au moins une zone');
        $this->refused(fn () => $this->planner->change($this->rows(), [$this->west], [], [$this->west], $this->candidates(), false, EventStatus::Active), 'qu\'une fois');
        $this->refused(fn () => $this->planner->change($this->rows(), [], [$this->south, $this->south], [], [], false, EventStatus::Active), 'qu\'une fois');
    }

    // ------------------------------------------------------------------ zones non utilisables

    public function testAZoneOfAnotherCommuneOrNotProposableOrUnknownIsRefused(): void
    {
        $this->refused(fn () => $this->planner->change($this->rows(), [$this->west], [], [], $this->candidates(own: false), false, EventStatus::Active), 'n\'appartient pas à votre commune');
        $this->refused(fn () => $this->planner->change($this->rows(), [$this->west], [], [], $this->candidates(proposable: false), false, EventStatus::Active), 'pas ouverte');
        $this->refused(fn () => $this->planner->change($this->rows(), [Uuid::v7()], [], [], $this->candidates(), false, EventStatus::Active), 'introuvable');
        $this->refused(fn () => $this->planner->change($this->rows(), [], [], [$this->east], $this->candidates(proposable: false), false, EventStatus::Active), 'pas ouverte');
    }

    public function testAZoneAlreadyAttachedOrRemovedCannotBeAddedAndRemovedOnesAreReinstated(): void
    {
        $this->refused(fn () => $this->planner->change($this->rows(), [$this->north], [], [], $this->candidates(), false, EventStatus::Active), 'figure déjà');
        $this->refused(fn () => $this->planner->change($this->rows(), [$this->east], [], [], $this->candidates(), false, EventStatus::Active), 'réintégrez-la');
        $this->refused(fn () => $this->planner->change($this->rows(), [], [$this->east], [], [], false, EventStatus::Active), 'déjà retirée');
        $this->refused(fn () => $this->planner->change($this->rows(), [], [], [$this->north], $this->candidates(), false, EventStatus::Active), 'rien à réintégrer');
        $this->refused(fn () => $this->planner->change($this->rows(), [], [Uuid::v7()], [], [], false, EventStatus::Active), 'ne figure pas');
    }

    // ------------------------------------------------------------------ état de l'événement, révocation

    /**
     * @return iterable<string, array{EventStatus}>
     */
    public static function inactive(): iterable
    {
        yield 'suspendu' => [EventStatus::Suspended];
        yield 'brouillon' => [EventStatus::Draft];
    }

    #[DataProvider('inactive')]
    public function testAddingAndReinstatingAreRefusedWhenTheEventIsNotActiveButRemovingStaysPossible(EventStatus $status): void
    {
        $this->refused(fn () => $this->planner->change($this->rows(), [$this->west], [], [], $this->candidates(), false, $status), 'ajoutée ni réintégrée');
        $this->refused(fn () => $this->planner->change($this->rows(), [], [], [$this->east], $this->candidates(), false, $status), 'ajoutée ni réintégrée');
        $this->refused(fn () => $this->planner->change($this->rows(), [$this->west], [$this->south], [], $this->candidates(), false, $status), 'ajoutée ni réintégrée'); // opération mixte : refus total, aucun effet partiel
        self::assertTrue($this->planner->change($this->rows(), [], [$this->south], [], [], false, $status)->hasRemoval(), 'un retrait reste possible');
    }

    public function testAClosedEventAndARevokedAuthorizationRefuseEverything(): void
    {
        $this->refused(fn () => $this->planner->change($this->rows(), [], [$this->south], [], [], false, EventStatus::Closed), 'clos');
        $this->refused(fn () => $this->planner->change($this->rows(), [], [$this->south], [], [], true, EventStatus::Active), 'révoquée');
        $this->refused(fn () => $this->planner->relabel($this->rows(), [$this->east], $this->candidates(), true, EventStatus::Active), 'révoquée');
        $this->refused(fn () => $this->planner->relabel($this->rows(), [$this->east], $this->candidates(), false, EventStatus::Closed), 'clos');
    }

    // ------------------------------------------------------------------ correction de libellé

    public function testALabelIsCorrectedBySynchronizationWithTheCurrentLabelOnly(): void
    {
        $changes = $this->planner->relabel($this->rows(), [$this->east], $this->candidates(), false, EventStatus::Active);

        self::assertSame([ZoneChangeKind::LabelCorrected, 'Zone est (rénovée)', 'Zone est'], [$changes[0]->kind, $changes[0]->label, $changes[0]->previousLabel]);
        self::assertSame(\App\Permit\Domain\RevisionType::ZoneLabelCorrected, $changes[0]->kind->revisionType());
    }

    public function testALabelCorrectionIsPossibleDuringASuspensionAndForAnActiveZone(): void
    {
        $candidates = [$this->north->toRfc4122() => new ZoneCandidate($this->north, 'Zone nord renommée', true, false)];

        $changes = $this->planner->relabel($this->rows(), [$this->north], $candidates, false, EventStatus::Suspended);

        self::assertSame('Zone nord renommée', $changes[0]->label, 'ne modifie aucun droit : permis, même pour une zone non proposable');
    }

    public function testAnUpToDateLabelAnUnknownZoneAndAnEmptyOrDuplicatedListAreRefused(): void
    {
        $candidates = [$this->north->toRfc4122() => new ZoneCandidate($this->north, 'Zone nord', true, true)];

        $this->refused(fn () => $this->planner->relabel($this->rows(), [$this->north], $candidates, false, EventStatus::Active), 'déjà à jour');
        $this->refused(fn () => $this->planner->relabel($this->rows(), [Uuid::v7()], $candidates, false, EventStatus::Active), 'ne figure pas');
        $this->refused(fn () => $this->planner->relabel($this->rows(), [], $candidates, false, EventStatus::Active), 'au moins une zone');
        $this->refused(fn () => $this->planner->relabel($this->rows(), [$this->east, $this->east], $this->candidates(), false, EventStatus::Active), 'qu\'une fois');
        $this->refused(fn () => $this->planner->relabel($this->rows(), [$this->south], [], false, EventStatus::Active), 'introuvable');
    }

    // ------------------------------------------------------------------ catalogues de motifs

    public function testTheValidatedReasonCataloguesAreExact(): void
    {
        self::assertSame(['NEW_NEED', 'OMITTED_AT_DECISION', 'BENEFICIARY_REQUEST', 'OTHER'], array_map(static fn (ZoneAddReason $r): string => $r->value, ZoneAddReason::cases()));
        self::assertSame(['NEED_ENDED', 'ZONE_CLOSED_OR_UNSAFE', 'ISSUED_IN_ERROR', 'BENEFICIARY_REQUEST', 'OTHER'], array_map(static fn (ZoneRemovalReason $r): string => $r->value, ZoneRemovalReason::cases()));
        self::assertSame(['REMOVED_IN_ERROR', 'NEED_RETURNED', 'OTHER'], array_map(static fn (ZoneReinstatementReason $r): string => $r->value, ZoneReinstatementReason::cases()));
        self::assertSame(['TYPO', 'WORDING_CLARIFIED'], array_map(static fn (LabelCorrectionReason $r): string => $r->value, LabelCorrectionReason::cases()), 'code seul, pas de « Autre »');
        foreach ([...ZoneAddReason::cases(), ...ZoneRemovalReason::cases(), ...ZoneReinstatementReason::cases(), ...LabelCorrectionReason::cases()] as $reason) {
            self::assertDoesNotMatchRegularExpression('/^[A-Z_]+$/', $reason->label());
        }
        foreach ([ZoneAddReason::Other, ZoneRemovalReason::Other, ZoneReinstatementReason::Other] as $other) {
            self::assertTrue($other->needsComment());
        }
        self::assertFalse(ZoneRemovalReason::NeedEnded->needsComment());
    }
}
