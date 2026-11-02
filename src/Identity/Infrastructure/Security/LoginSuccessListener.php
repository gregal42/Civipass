<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use App\Identity\Application\LoginRecorder;
use App\Identity\Domain\User;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\PasswordCredentials;

#[AsEventListener(event: LoginSuccessEvent::class)]
final readonly class LoginSuccessListener
{
    public function __construct(private LoginRecorder $recorder)
    {
    }

    public function __invoke(LoginSuccessEvent $event): void
    {
        $user = $event->getUser();
        // lastLoginAt : authentification COMPLÈTE par mot de passe uniquement, pas la reconnexion par cookie remember-me.
        if ('main' === $event->getFirewallName() && $user instanceof User && $event->getPassport()->hasBadge(PasswordCredentials::class)) {
            $this->recorder->record($user);
        }
    }
}
