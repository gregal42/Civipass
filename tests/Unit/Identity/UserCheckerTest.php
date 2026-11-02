<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity;

use App\Identity\Infrastructure\Security\UserChecker;
use App\Tests\Support\UserBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\InMemoryUser;

final class UserCheckerTest extends TestCase
{
    public function testActiveAccountPasses(): void
    {
        (new UserChecker())->checkPostAuth(UserBuilder::aUser()->build());
        $this->addToAssertionCount(1);
    }

    public function testDisabledAccountIsRefusedAfterAuthentication(): void
    {
        $this->expectException(CustomUserMessageAccountStatusException::class);
        $this->expectExceptionMessage('désactivé');
        (new UserChecker())->checkPostAuth(UserBuilder::aUser()->inactive()->build());
    }

    public function testNothingIsRevealedBeforeThePasswordIsChecked(): void
    {
        // checkPreAuth s'exécute avant le mot de passe : il ne doit jamais trahir l'état d'un compte.
        (new UserChecker())->checkPreAuth(UserBuilder::aUser()->inactive()->build());
        $this->addToAssertionCount(1);
    }

    public function testForeignUserTypesAreIgnored(): void
    {
        (new UserChecker())->checkPostAuth(new InMemoryUser('x', null));
        $this->addToAssertionCount(1);
    }
}
