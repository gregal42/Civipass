<?php

declare(strict_types=1);

namespace App\Tests\Unit\Archive;

use App\Archive\Domain\ArchiveConsultation;
use App\Archive\Domain\ConsultationAction;
use App\Archive\Domain\ConsultationReason;
use App\Archive\Domain\EventManagerDesignation;
use App\Archive\Domain\LegalHoldReason;
use App\Archive\Domain\RequestLegalHold;
use App\Archive\Domain\RevocationCause;
use App\Event\Domain\EventParticipation;
use App\Identity\Domain\User;
use App\Request\Domain\PermitRequestMunicipality;
use App\Shared\Domain\DomainRuleViolation;
use PHPUnit\Framework\TestCase;

/** Invariants du domaine des archives (LOT 05.6.3) : révocation définitive, motif obligatoire, gel sans prolongation automatique. */
final class ArchiveDomainTest extends TestCase
{
    private static function stub(string $class): object
    {
        return (new \ReflectionClass($class))->newInstanceWithoutConstructor();
    }

    private static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-11-12 10:00:00 UTC');
    }

    public function testADesignationIsActiveUntilRevokedAndARevocationIsDefinitive(): void
    {
        /** @var EventParticipation $participation */
        $participation = self::stub(EventParticipation::class);
        /** @var User $user */
        $user = self::stub(User::class);
        $designation = new EventManagerDesignation($participation, $user, $user, self::now());

        self::assertTrue($designation->isActive());
        $designation->revoke(RevocationCause::RoleLost, null, self::now());
        self::assertFalse($designation->isActive());
        self::assertSame(RevocationCause::RoleLost, $designation->revocationCause());
        $this->expectException(DomainRuleViolation::class);
        $designation->revoke(RevocationCause::AdminRequest, $user, self::now()); // jamais réactivée ni révoquée deux fois
    }

    public function testAConsultationNeedsAMotifAndOtherNeedsAShortComment(): void
    {
        /** @var PermitRequestMunicipality $dossier */
        $dossier = self::stub(PermitRequestMunicipality::class);
        /** @var User $user */
        $user = self::stub(User::class);
        $ok = new ArchiveConsultation($dossier, $user, ConsultationAction::DossierOpened, ConsultationReason::Litigation, null, null, self::now());
        self::assertSame(ConsultationReason::Litigation, $ok->reason());

        foreach ([[ConsultationReason::Other, null], [ConsultationReason::Other, '   '], [ConsultationReason::Litigation, str_repeat('x', 201)]] as [$reason, $comment]) {
            try {
                new ArchiveConsultation($dossier, $user, ConsultationAction::DossierOpened, $reason, $comment, null, self::now());
                self::fail('Motif invalide accepté.');
            } catch (DomainRuleViolation) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testALegalHoldNeverRenewsItselfAndAReviewMustMoveTheDueDateForward(): void
    {
        /** @var PermitRequestMunicipality $dossier */
        $dossier = self::stub(PermitRequestMunicipality::class);
        /** @var User $user */
        $user = self::stub(User::class);
        $hold = new RequestLegalHold($dossier, LegalHoldReason::Litigation, null, $user, self::now(), self::now()->modify('+6 months'));

        self::assertFalse($hold->isOverdue(self::now()->modify('+5 months')));
        self::assertTrue($hold->isOverdue(self::now()->modify('+7 months')), 'en retard : signalé, mais jamais renouvelé ni levé par le temps');
        self::assertTrue($hold->isActive());
        $hold->review($user, self::now()->modify('+7 months'), self::now()->modify('+13 months'));
        self::assertSame(1, $hold->reviewCount());
        try {
            $hold->review($user, self::now(), self::now()->modify('-1 day'));
            self::fail('Une échéance passée est refusée.');
        } catch (DomainRuleViolation) {
            $this->addToAssertionCount(1);
        }
        $hold->lift($user, null, self::now()->modify('+8 months'));
        self::assertFalse($hold->isActive());
        $this->expectException(DomainRuleViolation::class);
        $hold->lift($user, null, self::now());
    }

    public function testALegalHoldForOtherNeedsAComment(): void
    {
        /** @var PermitRequestMunicipality $dossier */
        $dossier = self::stub(PermitRequestMunicipality::class);
        /** @var User $user */
        $user = self::stub(User::class);
        $this->expectException(DomainRuleViolation::class);

        new RequestLegalHold($dossier, LegalHoldReason::Other, null, $user, self::now(), self::now()->modify('+6 months'));
    }
}
