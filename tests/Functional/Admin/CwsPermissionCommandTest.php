<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Tests\Support\WebTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/** LOT 08.6 : attribution, révocation et liste des habilitations CWS par la console. */
final class CwsPermissionCommandTest extends WebTestCase
{
    /** @param array<string, string> $input */
    private function console(array $input): CommandTester
    {
        $application = new Application(static::$kernel);
        $tester = new CommandTester($application->find('app:cws:permission'));
        $tester->execute($input, ['interactive' => false]);

        return $tester;
    }

    protected function setUp(): void
    {
        $this->freshClient();
        $this->createAccount('marie@cws.example', superAdmin: true);
        $this->createAccount('paul@cws.example', superAdmin: true);
    }

    public function testGrantListAndRevoke(): void
    {
        $granted = $this->console(['action' => 'grant', 'email' => 'marie@cws.example', 'permission' => 'CWS_FORCE_CLOSE_EVENT', '--by' => 'paul@cws.example', '--reason' => 'Astreinte de crise 2026']);
        self::assertSame(0, $granted->getStatusCode(), $granted->getDisplay());

        $list = $this->console(['action' => 'list']);
        self::assertStringContainsString('marie@cws.example', $list->getDisplay());
        self::assertStringContainsString('active', $list->getDisplay());

        self::assertSame(0, $this->console(['action' => 'revoke', 'email' => 'marie@cws.example', 'permission' => 'CWS_FORCE_CLOSE_EVENT', '--by' => 'paul@cws.example'])->getStatusCode());
        self::assertStringContainsString('révoquée', $this->console(['action' => 'list'])->getDisplay());
        self::assertSame(1, $this->console(['action' => 'revoke', 'email' => 'marie@cws.example', 'permission' => 'CWS_FORCE_CLOSE_EVENT', '--by' => 'paul@cws.example'])->getStatusCode(), 'rien à révoquer');
    }

    public function testSelfGrantMissingReasonAndUnknownAccountAreRefused(): void
    {
        self::assertSame(1, $this->console(['action' => 'grant', 'email' => 'marie@cws.example', 'permission' => 'CWS_FORCE_CLOSE_EVENT', '--by' => 'marie@cws.example', '--reason' => 'Astreinte de crise 2026'])->getStatusCode());
        self::assertSame(1, $this->console(['action' => 'grant', 'email' => 'marie@cws.example', 'permission' => 'CWS_FORCE_CLOSE_EVENT', '--by' => 'paul@cws.example'])->getStatusCode());
        self::assertSame(1, $this->console(['action' => 'grant', 'email' => 'inconnu@cws.example', 'permission' => 'CWS_FORCE_CLOSE_EVENT', '--by' => 'paul@cws.example', '--reason' => 'Astreinte de crise 2026'])->getStatusCode());
        self::assertSame(2, $this->console(['action' => 'grant', 'email' => 'marie@cws.example', 'permission' => 'INCONNUE', '--by' => 'paul@cws.example'])->getStatusCode());
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM identity_cws_permission'));
    }
}
