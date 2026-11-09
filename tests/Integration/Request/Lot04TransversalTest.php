<?php

declare(strict_types=1);

namespace App\Tests\Integration\Request;

use App\Request\Application\Command\CorrectIdentityCommand;
use App\Request\Domain\ApprovalDuration;
use App\Request\Domain\DurationMode;
use App\Shared\Domain\OperationNotAllowed;
use App\Tests\Support\IdentityCorrectionFactory;
use App\Tests\Support\MunicipalDecisionTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Recette transversale du LOT 04 (04.5) : demande multicommunale, décisions indépendantes, correction d'identité APRÈS décision, participations
 * non actives. Chaque scénario enchaîne les services réels des incréments 04.1 à 04.4 et vérifie qu'ils fonctionnent ensemble sans effet croisé.
 */
#[Group('requires-request-schema')]
final class Lot04TransversalTest extends MunicipalDecisionTestCase
{
    private function correct(string $requestId, string $last, bool $partner = false): void
    {
        $service = IdentityCorrectionFactory::create(self::getContainer(), $this->clock);
        $version = (int) $this->em->getConnection()->fetchOne('SELECT identity_version FROM request_permit_request');
        $service->correct($partner ? $this->partnerInstructor : $this->adminOnly, ($partner ? $this->partner : $this->organizer)->id()->toRfc4122(), $requestId, new CorrectIdentityCommand($version, $last, 'Camille', '', 'camille@exemple.fr', '', true));
    }

    public function testApproveInOneCommuneRefuseInTheOtherThenCorrectTheIdentityLeavesBothDecisionsAndRightsUntouched(): void
    {
        $id = $this->submit();
        $this->instruction()->approve($this->instructor, $this->organizer->id()->toRfc4122(), $id, ApprovalDuration::fixed(DurationMode::H48));
        $this->instruction()->refuse($this->partnerInstructor, $this->partner->id()->toRfc4122(), $id, 'duplicate', null);
        $approved = $this->decisionRow($id, $this->organizer);
        $refused = $this->decisionRow($id, $this->partner);
        $references = $this->em->getConnection()->fetchOne('SELECT public_reference FROM request_permit_request');

        $this->correct($id, 'Corrigé');

        self::assertSame(['APPROVED', 'REFUSED'], [$this->partStatus($id, $this->organizer), $this->partStatus($id, $this->partner)]);
        self::assertSame($approved, $this->decisionRow($id, $this->organizer));
        self::assertSame($refused, $this->decisionRow($id, $this->partner));
        self::assertSame($references, $this->em->getConnection()->fetchOne('SELECT public_reference FROM request_permit_request'));
        self::assertSame('Corrigé', $this->em->getConnection()->fetchOne('SELECT last_name FROM request_permit_request'));
        self::assertSame(2, $this->rows('request_permit_request_decision'));
        self::assertSame(2, $this->rows('audit_log WHERE action = \'REQUEST_IDENTITY_CORRECTED\''), 'audit dans chaque commune');
    }

    public function testACommuneCanNeverDecideOrChangeTheDecisionOfAnother(): void
    {
        $id = $this->submit();
        $this->instruction()->refuse($this->partnerInstructor, $this->partner->id()->toRfc4122(), $id, 'duplicate', null);
        $refused = $this->decisionRow($id, $this->partner);

        foreach ([[$this->instructor, $this->partner], [$this->outsiderInstructor, $this->partner], [$this->outsiderInstructor, $this->organizer]] as [$agent, $commune]) {
            try {
                $this->instruction()->approve($agent, $commune->id()->toRfc4122(), $id, ApprovalDuration::fixed(DurationMode::H24));
                self::fail('Une commune ne décide pas pour une autre.');
            } catch (OperationNotAllowed) {
            }
        }

        self::assertSame($refused, $this->decisionRow($id, $this->partner));
        self::assertSame('PENDING', $this->partStatus($id, $this->organizer));
        self::assertSame(1, $this->rows('request_permit_request_decision'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function inactive(): iterable
    {
        yield 'suspendue' => ['SUSPENDED'];
        yield 'clôturée' => ['CLOSED'];
    }

    /**
     * Règle documentée (DM-111, §6) : l'identité est une donnée de la DEMANDE, pas de la participation. La correction par une commune dont la
     * participation est active reste possible et est auditée dans l'autre commune ; la décision de la commune non active n'est pas touchée et
     * sa propre instruction reste refusée (lecture seule, DM-107).
     */
    #[DataProvider('inactive')]
    public function testANonActiveParticipationOfTheOtherCommuneDoesNotBlockTheCorrectionNorChangeItsDecision(string $status): void
    {
        $id = $this->submit();
        $this->sql('UPDATE event_participation SET status = :s WHERE id = :id', ['s' => $status, 'id' => $this->partnerParticipation->id()->toBinary()]);

        $this->correct($id, 'Corrigé');

        self::assertSame('Corrigé', $this->em->getConnection()->fetchOne('SELECT last_name FROM request_permit_request'));
        self::assertSame('PENDING', $this->partStatus($id, $this->partner));
        self::assertSame(0, $this->rows('request_permit_request_decision'));
        self::assertSame(2, $this->rows('audit_log WHERE action = \'REQUEST_IDENTITY_CORRECTED\''));
        try {
            $this->instruction()->refuse($this->partnerInstructor, $this->partner->id()->toRfc4122(), $id, 'duplicate', null);
            self::fail('L\'instruction d\'une participation non active reste refusée.');
        } catch (OperationNotAllowed) {
        }
    }

    public function testTheRefusalStaysPossibleWhenNoDurationCouldApproveTheZones(): void
    {
        $id = $this->submit([$this->organizerZone]);
        $this->sql('UPDATE event_zone SET status = \'SUSPENDED\' WHERE id = :id', ['id' => $this->organizerZone->id()->toBinary()]);

        $this->instruction()->refuse($this->em->find(\App\Identity\Domain\User::class, $this->instructor->id()) ?? self::fail('Agent absent.'), $this->organizer->id()->toRfc4122(), $id, 'duplicate', null);

        self::assertSame('REFUSED', $this->partStatus($id, $this->organizer));
    }
}
