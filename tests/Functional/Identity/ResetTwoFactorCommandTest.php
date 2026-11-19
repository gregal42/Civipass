<?php

declare(strict_types=1);

namespace App\Tests\Functional\Identity;

use App\Identity\Application\Totp;
use App\Identity\Application\TwoFactorService;
use App\Identity\Application\TwoFactorState;
use App\Tests\Support\ControlEnv;
use App\Tests\Support\DatabaseTestCase;
use App\Tests\Support\UserBuilder;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Réinitialisation du second facteur par la console (SEC-006) : confirmation explicite, motif obligatoire, acteur tracé, jamais pour un compte non CWS.
 */
final class ResetTwoFactorCommandTest extends DatabaseTestCase
{
    use ControlEnv;

    protected function setUp(): void
    {
        $this->controlEnv('TWO_FACTOR_REQUIRED', '1');
        parent::setUp();
    }

    protected function tearDown(): void
    {
        $this->restoreControlEnv();
        parent::tearDown();
    }

    private function tester(): CommandTester
    {
        return new CommandTester((new Application(self::$kernel))->find('app:super-admin:reset-two-factor'));
    }

    public function testItNeedsConfirmationAndAReasonThenReEnrollsTheAccount(): void
    {
        $user = UserBuilder::aUser()->withEmail('cws@civipass.example')->superAdmin()->build();
        $this->save($user);
        $service = $this->service(TwoFactorService::class);
        $secret = $service->enrollmentChallenge($user)->secret;
        $service->confirmEnrollment($user, Totp::code($secret, Totp::stepAt(new \DateTimeImmutable())));

        $tester = $this->tester();
        self::assertSame(1, $tester->execute(['email' => 'cws@civipass.example', '--reason' => 'perte']), 'sans --yes : refus');
        self::assertSame(TwoFactorState::Active, $service->state($user));
        self::assertSame(1, $tester->execute(['email' => 'cws@civipass.example', '--yes' => true]), 'sans motif : refus');
        self::assertStringContainsString('motif', $tester->getDisplay());
        self::assertSame(TwoFactorState::Active, $service->state($user));

        self::assertSame(0, $tester->execute(['email' => 'cws@civipass.example', '--yes' => true, '--reason' => 'téléphone perdu, accès serveur']));
        self::assertSame(TwoFactorState::None, $service->state($user));
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM audit_log WHERE action = 'TWO_FACTOR_RESET'"));
    }

    public function testItRefusesAnUnknownAccountAnAgentAndTheSameOperatorAsTarget(): void
    {
        $cws = UserBuilder::aUser()->withEmail('cws@civipass.example')->superAdmin()->build();
        $agent = UserBuilder::aUser()->withEmail('agent@mairie.example')->build();
        $this->save($cws, $agent);
        $tester = $this->tester();

        self::assertSame(1, $tester->execute(['email' => 'inconnu@civipass.example', '--yes' => true, '--reason' => 'x']));
        self::assertSame(1, $tester->execute(['email' => 'agent@mairie.example', '--yes' => true, '--reason' => 'x']));
        self::assertSame(1, $tester->execute(['email' => 'cws@civipass.example', '--yes' => true, '--reason' => 'x', '--operator' => $cws->id()->toRfc4122()]), 'on ne se réinitialise pas soi-même par un opérateur');
        self::assertSame(1, $tester->execute(['email' => 'cws@civipass.example', '--yes' => true, '--reason' => 'x', '--operator' => 'pas-un-uuid']));
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM audit_log WHERE action = 'TWO_FACTOR_RESET'"));
    }
}
