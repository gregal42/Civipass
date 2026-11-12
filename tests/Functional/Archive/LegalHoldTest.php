<?php

declare(strict_types=1);

namespace App\Tests\Functional\Archive;

use App\Archive\Application\LegalHoldService;
use App\Shared\Domain\DomainRuleViolation;
use App\Shared\Domain\OperationNotAllowed;
use App\Tests\Support\ArchiveScenarioTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Uid\Uuid;

/**
 * Gel de conservation par dossier communal (LOT 05.6.3, D3, arbitrage A2) : réservé à l'administrateur de la commune du dossier, motif obligatoire, un seul gel actif,
 * historique conservé, revue explicite sans prolongation automatique, gel en retard maintenu et signalé, isolation entre communes.
 */
final class LegalHoldTest extends ArchiveScenarioTestCase
{
    private function holds(): LegalHoldService
    {
        return static::getContainer()->get(LegalHoldService::class);
    }

    private function active(string $dossier): int
    {
        return (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM archive_legal_hold WHERE request_municipality_id = UNHEX(:d) AND active_marker = 1', ['d' => str_replace('-', '', $dossier)]);
    }

    public function testOnlyTheAdministratorOfTheDossierMunicipalityPlacesReviewsAndLiftsAHold(): void
    {
        $this->close();
        $mine = $this->dossierOf($this->organizerId);
        $theirs = $this->dossierOf($this->partnerId);

        foreach ([[$this->manager, $this->organizerId, $mine], [$this->instructor, $this->organizerId, $mine], [$this->outsider, $this->organizerId, $mine], [$this->superAdmin, $this->organizerId, $mine], [$this->admin, $this->organizerId, $theirs], [$this->partnerAdmin, $this->organizerId, $mine], [$this->admin, $this->partnerId, $theirs]] as [$actor, $commune, $dossier]) {
            try {
                $this->holds()->place($this->managed($actor), $commune, $dossier, 'LITIGE', null);
                self::fail('Gel posé par un utilisateur non habilité ou sur un dossier d\'autrui.');
            } catch (OperationNotAllowed) {
                $this->addToAssertionCount(1);
            }
        }
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM archive_legal_hold'));
        $this->designate($this->manager, $this->organizerId, $this->admin);
        try {
            $this->holds()->place($this->managed($this->manager), $this->organizerId, $mine, 'LITIGE', null);
            self::fail('Un gestionnaire désigné ne gèle pas.');
        } catch (OperationNotAllowed) {
            $this->addToAssertionCount(1);
        }

        $this->holds()->place($this->managed($this->admin), $this->organizerId, $mine, 'LITIGE', 'Procédure en cours');
        self::assertSame(1, $this->active($mine));
        self::assertSame(0, $this->active($theirs), 'un gel de A n\'atteint pas le dossier de B');
    }

    public function testTheReasonIsMandatoryOnlyOneHoldIsActiveAndEveryEpisodeIsKept(): void
    {
        $this->close();
        $dossier = $this->dossierOf($this->organizerId);
        $admin = $this->managed($this->admin);

        foreach (['', 'MOTIF_INVENTE'] as $bad) {
            try {
                $this->holds()->place($admin, $this->organizerId, $dossier, $bad, null);
                self::fail('Motif obligatoire.');
            } catch (DomainRuleViolation) {
                $this->addToAssertionCount(1);
            }
        }
        try {
            $this->holds()->place($admin, $this->organizerId, $dossier, 'AUTRE', '  ');
            self::fail('« autre » exige un commentaire.');
        } catch (DomainRuleViolation) {
            $this->addToAssertionCount(1);
        }
        $this->holds()->place($admin, $this->organizerId, $dossier, 'RECOURS', null);
        try {
            $this->holds()->place($this->managed($this->admin), $this->organizerId, $dossier, 'LITIGE', null);
            self::fail('Un seul gel actif par dossier.');
        } catch (DomainRuleViolation) {
            $this->addToAssertionCount(1);
        }

        $this->holds()->lift($this->managed($this->admin), $this->organizerId, $dossier, 'Affaire classée');
        $this->holds()->place($this->managed($this->admin), $this->organizerId, $dossier, 'LITIGE', null);

        $rows = $this->em()->getConnection()->fetchAllAssociative('SELECT reason, active_marker, lifted_at IS NOT NULL AS lifted, HEX(placed_by_user_id) AS who FROM archive_legal_hold ORDER BY placed_at, id');
        self::assertCount(2, $rows, 'historique : l\'épisode levé est conservé');
        self::assertSame(['RECOURS', null, '1'], [$rows[0]['reason'], $rows[0]['active_marker'], (string) $rows[0]['lifted']]);
        self::assertSame(['LITIGE', '1'], [$rows[1]['reason'], (string) $rows[1]['active_marker']]);
        self::assertSame(strtoupper(str_replace('-', '', $this->admin->id()->toRfc4122())), $rows[0]['who'], 'auteur enregistré');
        try {
            $this->holds()->lift($this->managed($this->admin), $this->organizerId, Uuid::v7()->toRfc4122(), null);
            self::fail('Dossier inconnu.');
        } catch (OperationNotAllowed) {
            $this->addToAssertionCount(1);
        }
    }

    public function testTheReviewMovesTheDueDateOnlyWhenAnAdministratorConfirmsAndNothingRenewsItAutomatically(): void
    {
        $this->close();
        $dossier = $this->dossierOf($this->organizerId);
        $this->holds()->place($this->managed($this->admin), $this->organizerId, $dossier, 'LITIGE', null);
        $due = (string) $this->em()->getConnection()->fetchOne('SELECT review_due_at FROM archive_legal_hold');
        self::assertGreaterThan(time() + 150 * 86400, strtotime($due.' UTC'), 'revue à environ 6 mois (hypothèse)');

        // Échéance dépassée : le gel est signalé, mais RIEN ne le renouvelle ni ne le lève.
        $this->sql('UPDATE archive_legal_hold SET review_due_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 3 DAY)');
        $overdue = $this->holds()->overdue();
        self::assertCount(1, $overdue);
        $this->as($this->admin);
        $this->client->request('GET', $this->archiveUrl($this->organizerId, '/evenements/'.$this->eventId()));
        $this->client->request('GET', $this->archiveUrl($this->organizerId));
        self::assertSame(1, $this->active($dossier), 'toujours actif');
        self::assertLessThan(time(), strtotime((string) $this->em()->getConnection()->fetchOne('SELECT review_due_at FROM archive_legal_hold').' UTC'), 'échéance inchangée par la simple consultation');

        $tester = new CommandTester((new \Symfony\Bundle\FrameworkBundle\Console\Application(static::getContainer()->get('kernel')))->find('app:archive:holds-report'));
        $tester->execute([]);
        self::assertStringContainsString('1 gel(s) en retard de revue', $tester->getDisplay());
        self::assertStringContainsString($dossier, $tester->getDisplay());
        foreach (self::SENTINELS as $sentinel) {
            self::assertStringNotContainsStringIgnoringCase($sentinel, $tester->getDisplay(), 'rapport sans donnée personnelle');
        }

        $this->holds()->review($this->managed($this->admin), $this->organizerId, $dossier);
        self::assertSame([], $this->holds()->overdue());
        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne('SELECT review_count FROM archive_legal_hold'));
        $this->holds()->lift($this->managed($this->admin), $this->organizerId, $dossier, null);
        $this->expectException(DomainRuleViolation::class);
        $this->holds()->review($this->managed($this->admin), $this->organizerId, $dossier);
    }

    public function testAnOverdueHoldKeepsTheDossierProtectedAndAccessibleAndALiftedHoldClosesAnExpiredArchive(): void
    {
        $this->closeAndAge(14);
        $dossier = $this->dossierOf($this->organizerId);
        $this->holds()->place($this->managed($this->admin), $this->organizerId, $dossier, 'LITIGE', null);
        $this->sql('UPDATE archive_legal_hold SET review_due_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 40 DAY)');

        self::assertNotNull($this->openDossier($this->admin, $this->organizerId, $dossier), 'gel en retard de revue : accès maintenu (arbitrage A2)');
        $this->holds()->lift($this->managed($this->admin), $this->organizerId, $dossier, null);
        self::assertNull($this->openDossier($this->admin, $this->organizerId, $dossier), 'gel levé et archive échue : accès fermé');
    }

    public function testAHoldOpensNothingToAnotherMunicipalityAndAuditCarriesNoComment(): void
    {
        $this->close();
        $mine = $this->dossierOf($this->organizerId);
        $theirs = $this->dossierOf($this->partnerId);
        $this->holds()->place($this->managed($this->admin), $this->organizerId, $mine, 'AUTRE', 'Commentaire confidentiel Durand');

        self::assertNull($this->openDossier($this->partnerAdmin, $this->organizerId, $mine), 'le gel de A n\'ouvre rien à B');
        self::assertNull($this->openDossier($this->partnerAdmin, $this->partnerId, $mine), 'dossier de A dans l\'URL de B');
        self::assertNotNull($this->openDossier($this->partnerAdmin, $this->partnerId, $theirs), 'B lit son propre dossier (non gelé, archive ouverte)');
        $audit = $this->em()->getConnection()->fetchFirstColumn('SELECT new_value FROM audit_log WHERE action = \'LEGAL_HOLD_PLACED\'');
        self::assertCount(1, $audit);
        self::assertStringNotContainsString('Durand', (string) $audit[0]);
        self::assertStringNotContainsString('confidentiel', (string) $audit[0]);
    }
}
