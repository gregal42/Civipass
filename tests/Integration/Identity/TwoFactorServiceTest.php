<?php

declare(strict_types=1);

namespace App\Tests\Integration\Identity;

use App\Identity\Application\Totp;
use App\Identity\Application\TwoFactorOutcome;
use App\Identity\Application\TwoFactorSecretCipher;
use App\Identity\Application\TwoFactorService;
use App\Identity\Application\TwoFactorState;
use App\Identity\Domain\TwoFactorRepository;
use App\Identity\Domain\User;
use App\Shared\Application\AuditTrail;
use App\Shared\Application\TransactionRunner;
use App\Shared\Domain\DomainRuleViolation;
use App\Shared\Domain\OperationNotAllowed;
use App\Tests\Support\DatabaseTestCase;
use App\Tests\Support\UserBuilder;
use Symfony\Component\Clock\MockClock;

/**
 * Second facteur TOTP des comptes CWS (SEC-006) : inscription, vérification, rejeu, codes de secours, réinitialisation ; secrets jamais en clair ni dans l'audit.
 */
final class TwoFactorServiceTest extends DatabaseTestCase
{
    private MockClock $clock;
    private TwoFactorService $twoFactor;
    private User $cws;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = new MockClock('2026-11-19 10:00:00 UTC');
        $this->twoFactor = $this->makeService(true);
        $this->cws = UserBuilder::aUser()->withEmail('cws@civipass.example')->superAdmin()->build();
        $this->save($this->cws);
    }

    private function makeService(bool $required): TwoFactorService
    {
        return new TwoFactorService(
            $this->service(TwoFactorRepository::class),
            $this->service(TwoFactorSecretCipher::class),
            $this->service(TransactionRunner::class),
            $this->service(AuditTrail::class),
            $this->clock,
            $required,
        );
    }

    /**
     * Inscrit le compte et retourne [secret, codes de secours].
     *
     * @return array{string, list<string>}
     */
    private function enroll(User $user): array
    {
        $secret = $this->twoFactor->enrollmentChallenge($user)->secret;
        $codes = $this->twoFactor->confirmEnrollment($user, Totp::code($secret, Totp::stepAt($this->clock->now())));

        return [$secret, $codes];
    }

    public function testAPendingEnrollmentKeepsTheSameSecretAndNeverStoresItInClear(): void
    {
        self::assertSame(TwoFactorState::None, $this->twoFactor->state($this->cws));
        $first = $this->twoFactor->enrollmentChallenge($this->cws);
        $second = $this->twoFactor->enrollmentChallenge($this->cws);

        self::assertSame($first->secret, $second->secret, 'le rechargement de la page ne change pas le secret');
        self::assertSame(TwoFactorState::Pending, $this->twoFactor->state($this->cws));
        $stored = (string) $this->em->getConnection()->fetchOne('SELECT encrypted_secret FROM identity_two_factor');
        self::assertStringNotContainsString($first->secret, $stored);
        self::assertStringNotContainsString($first->secret, (string) base64_decode($stored, true));
        self::assertStringContainsString('secret='.$first->secret, $first->uri);
    }

    public function testAWrongCodeDoesNotConfirmAndABackupCodeCannotConfirmEither(): void
    {
        $this->twoFactor->enrollmentChallenge($this->cws);

        $this->expectException(DomainRuleViolation::class);
        $this->twoFactor->confirmEnrollment($this->cws, '000000');
    }

    public function testConfirmationGivesTenBackupCodesStoresOnlyTheirHashesAndAuditsWithoutSecrets(): void
    {
        [$secret, $codes] = $this->enroll($this->cws);

        self::assertSame(TwoFactorState::Active, $this->twoFactor->state($this->cws));
        self::assertCount(10, $codes);
        self::assertCount(10, array_unique($codes));
        foreach ($codes as $code) {
            self::assertMatchesRegularExpression('/^[a-z2-7]{5}-[a-z2-7]{5}$/', $code);
        }
        $hashes = $this->em->getConnection()->fetchFirstColumn('SELECT code_hash FROM identity_two_factor_backup_code');
        self::assertCount(10, $hashes);
        foreach ($codes as $code) {
            self::assertNotContains(str_replace('-', '', $code), $hashes);
            self::assertNotContains(hash('sha256', str_replace('-', '', $code)), $hashes);
        }
        $audit = implode("\n", $this->em->getConnection()->fetchFirstColumn("SELECT CONCAT(action, new_value) FROM audit_log WHERE action LIKE 'TWO_FACTOR%'"));
        self::assertStringContainsString('TWO_FACTOR_ENROLLED', $audit);
        self::assertStringNotContainsString($secret, $audit);
        foreach ($codes as $code) {
            self::assertStringNotContainsString(str_replace('-', '', $code), $audit);
        }
    }

    public function testACodeIsAcceptedOnceAndNeverReplayedNorAnOlderOne(): void
    {
        [$secret] = $this->enroll($this->cws); // le pas courant est consommé par la confirmation
        $current = Totp::stepAt($this->clock->now());

        self::assertSame(TwoFactorOutcome::Rejected, $this->twoFactor->verify($this->cws, Totp::code($secret, $current)), 'le code de la confirmation ne se rejoue pas');
        self::assertSame(TwoFactorOutcome::Accepted, $this->twoFactor->verify($this->cws, Totp::code($secret, $current + 1)), 'pas suivant, dans la tolérance');
        self::assertSame(TwoFactorOutcome::Rejected, $this->twoFactor->verify($this->cws, Totp::code($secret, $current + 1)), 'rejeu refusé');

        $this->clock->sleep(60);
        $later = Totp::stepAt($this->clock->now());
        self::assertSame(TwoFactorOutcome::Rejected, $this->twoFactor->verify($this->cws, Totp::code($secret, $later - 1)), 'un pas plus ancien que le dernier accepté est refusé');
        self::assertSame(TwoFactorOutcome::Accepted, $this->twoFactor->verify($this->cws, Totp::code($secret, $later)));
    }

    public function testAnotherAccountsSecretOrAGarbageCodeNeverVerifies(): void
    {
        [$secret] = $this->enroll($this->cws);
        $other = UserBuilder::aUser()->withEmail('autre@civipass.example')->superAdmin()->build();
        $this->save($other);
        [$otherSecret] = $this->enroll($other);
        $step = Totp::stepAt($this->clock->now()) + 1;

        self::assertSame(TwoFactorOutcome::Rejected, $this->twoFactor->verify($this->cws, Totp::code($otherSecret, $step)));
        self::assertSame(TwoFactorOutcome::Rejected, $this->twoFactor->verify($this->cws, 'pas un code'));
        self::assertSame(TwoFactorOutcome::Rejected, $this->twoFactor->verify($this->cws, ''));
        self::assertSame(TwoFactorOutcome::Accepted, $this->twoFactor->verify($this->cws, Totp::code($secret, $step)));
    }

    public function testABackupCodeWorksExactlyOnceAndIsAuditedWithoutTheCode(): void
    {
        [, $codes] = $this->enroll($this->cws);
        $code = $codes[3];

        self::assertSame(TwoFactorOutcome::AcceptedBackupCode, $this->twoFactor->verify($this->cws, strtoupper($code)), 'casse et tiret indifférents');
        self::assertSame(TwoFactorOutcome::Rejected, $this->twoFactor->verify($this->cws, $code), 'usage unique');
        self::assertSame(9, $this->twoFactor->remainingBackupCodes($this->cws));
        $audit = implode("\n", $this->em->getConnection()->fetchFirstColumn("SELECT CONCAT(action, new_value) FROM audit_log WHERE action = 'TWO_FACTOR_BACKUP_CODE_USED'"));
        self::assertStringContainsString('"remaining"', $audit);
        self::assertStringNotContainsString(str_replace('-', '', $code), $audit);
    }

    public function testOnlyAnActiveSuperAdministratorIsConcerned(): void
    {
        $agent = UserBuilder::aUser()->withEmail('agent@mairie.example')->build();
        $inactive = UserBuilder::aUser()->withEmail('ancien@civipass.example')->superAdmin()->inactive()->build();
        $this->save($agent, $inactive);

        self::assertTrue($this->twoFactor->isRequiredFor($this->cws));
        self::assertFalse($this->twoFactor->isRequiredFor($agent), 'un compte communal n\'est pas concerné');
        self::assertFalse($this->twoFactor->isRequiredFor($inactive));
        self::assertFalse($this->makeService(false)->isRequiredFor($this->cws), 'interrupteur de test');
        $this->expectException(OperationNotAllowed::class);
        $this->twoFactor->enrollmentChallenge($agent);
    }

    public function testAnActiveSecondFactorCannotBeReEnrolledWithoutAReset(): void
    {
        $this->enroll($this->cws);

        $this->expectException(OperationNotAllowed::class);
        $this->twoFactor->enrollmentChallenge($this->cws);
    }

    public function testResetNeedsAnotherActiveSuperAdministratorAndAReasonThenTheAccountReEnrolls(): void
    {
        [$secret] = $this->enroll($this->cws);
        $operator = UserBuilder::aUser()->withEmail('second@civipass.example')->superAdmin()->build();
        $agent = UserBuilder::aUser()->withEmail('agent@mairie.example')->build();
        $this->save($operator, $agent);

        foreach ([
            'soi-même' => fn () => $this->twoFactor->reset($this->cws, $this->cws, 'perte'),
            'un compte communal' => fn () => $this->twoFactor->reset($this->cws, $agent, 'perte'),
            'sans motif' => fn () => $this->twoFactor->reset($this->cws, $operator, '  '),
            'un compte non CWS' => fn () => $this->twoFactor->reset($agent, $operator, 'perte'),
        ] as $label => $attempt) {
            try {
                $attempt();
                self::fail($label.' devrait être refusé');
            } catch (OperationNotAllowed) {
                self::assertSame(TwoFactorState::Active, $this->twoFactor->state($this->cws), $label);
            }
        }

        self::assertTrue($this->twoFactor->reset($this->cws, $operator, 'téléphone perdu'));
        self::assertSame(TwoFactorState::None, $this->twoFactor->state($this->cws));
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM identity_two_factor_backup_code'));
        self::assertSame(TwoFactorOutcome::Rejected, $this->twoFactor->verify($this->cws, Totp::code($secret, Totp::stepAt($this->clock->now()) + 1)));
        $audit = $this->em->getConnection()->fetchAssociative("SELECT action, new_value FROM audit_log WHERE action = 'TWO_FACTOR_RESET'");
        self::assertIsArray($audit);
        self::assertStringContainsString('téléphone perdu', (string) $audit['new_value']);
        self::assertNotSame($secret, $this->twoFactor->enrollmentChallenge($this->cws)->secret, 'nouveau secret après réinitialisation');
    }

    public function testTheConsoleCanResetWithoutAnOperatorButStillNeedsAReason(): void
    {
        $this->enroll($this->cws);

        try {
            $this->twoFactor->reset($this->cws, null, '');
            self::fail('motif obligatoire');
        } catch (OperationNotAllowed) {
        }
        self::assertTrue($this->twoFactor->reset($this->cws, null, 'dernier recours, accès serveur'));
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM audit_log WHERE action = 'TWO_FACTOR_RESET'"));
    }
}
