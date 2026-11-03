<?php

declare(strict_types=1);

namespace App\Tests\Unit\Event;

use App\Event\Application\AccessDenialReason;
use App\Event\Application\ParticipantListVisibility;
use App\Event\Application\Read\ActionAvailability;
use App\Event\Application\Read\EventAction;
use App\Event\Application\Read\EventDetail;
use App\Event\Application\Read\EventParticipantHistoryEntry;
use App\Event\Application\Read\EventParticipationDetails;
use App\Event\Application\Read\EventParticipationSummary;
use App\Event\Application\Read\EventRelation;
use App\Event\Application\Read\ParticipantList;
use App\Event\Domain\EventStatus;
use App\Event\Domain\ParticipationStatus;
use PHPUnit\Framework\TestCase;

final class EventDetailProjectionTest extends TestCase
{
    private function detail(ActionAvailability ...$actions): EventDetail
    {
        return new EventDetail('e', 'EVT-2026-000001', 'Nom', 'fire', 'Incendie', EventStatus::Draft, 'o', 'Organisatrice', new \DateTimeImmutable('2026-12-01'), null, 'Europe/Paris', EventRelation::Organizer, null, null, null, null, new ParticipantList(ParticipantListVisibility::None), array_values($actions), null);
    }

    public function testReadOnlyReasonIsSetOnlyWhenEveryActionIsDeniedForRoleOrMunicipality(): void
    {
        $role = new ActionAvailability(EventAction::Update, false, AccessDenialReason::RoleNotAllowed);
        $suspended = new ActionAvailability(EventAction::Invite, false, AccessDenialReason::MunicipalityNotWritable);
        $allowed = new ActionAvailability(EventAction::Activate, true);
        $state = new ActionAvailability(EventAction::Close, false, AccessDenialReason::InvalidEventState);

        self::assertSame(AccessDenialReason::RoleNotAllowed, $this->detail($role, $suspended)->readOnlyReason());
        self::assertNull($this->detail($role, $allowed)->readOnlyReason(), 'une action permise : pas de consultation seule');
        self::assertNull($this->detail($role, $state)->readOnlyReason(), 'un état de l\'événement n\'est pas une consultation seule');
        self::assertNull($this->detail()->readOnlyReason(), 'aucune action pertinente (événement clôturé)');
    }

    public function testActionLookup(): void
    {
        $update = new ActionAvailability(EventAction::Update, true);
        $detail = $this->detail($update);

        self::assertSame($update, $detail->action(EventAction::Update));
        self::assertNull($detail->action(EventAction::Close));
    }

    public function testParticipatingCountFollowsTheVisibilityLevel(): void
    {
        $detail = static fn (string $id, ParticipationStatus $status, bool $organizer): EventParticipationDetails => new EventParticipationDetails($id, $id, 'C'.$id, $status, $organizer, null, null, null, null, null, null, null);

        $full = new ParticipantList(ParticipantListVisibility::Full, details: [
            $detail('1', ParticipationStatus::Active, true),
            $detail('2', ParticipationStatus::Suspended, false),
            $detail('3', ParticipationStatus::Invited, false),
            $detail('4', ParticipationStatus::Closed, false),
        ]);
        self::assertSame(2, $full->participatingCount(), 'organisatrice + suspendue ; ni invitation en attente, ni clôturée');

        $coordination = new ParticipantList(ParticipantListVisibility::Coordination, summaries: [new EventParticipationSummary('1', 'A', ParticipationStatus::Active, true), new EventParticipationSummary('2', 'B', ParticipationStatus::Active, false)]);
        self::assertSame(2, $coordination->participatingCount());

        $history = new ParticipantList(ParticipantListVisibility::History, history: [new EventParticipantHistoryEntry('1', 'A', true)]);
        self::assertSame(1, $history->participatingCount());

        self::assertNull((new ParticipantList(ParticipantListVisibility::OwnInvitationOnly))->participatingCount(), 'une simple invitation ne connaît pas les autres communes');
    }
}
