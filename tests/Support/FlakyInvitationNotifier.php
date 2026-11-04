<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Onboarding\Application\InvitationDeliveryFailed;
use App\Onboarding\Application\InvitationNotifier;
use App\Onboarding\Domain\InvitationEmailContext;
use App\Onboarding\Domain\InvitationLink;

/** Notifier de test : échoue tant que `$fail` est vrai, sinon délègue au vrai notifier (e-mail capturé). */
final class FlakyInvitationNotifier implements InvitationNotifier
{
    public bool $fail = true;

    public function __construct(private readonly InvitationNotifier $real)
    {
    }

    public function sendContactInvitation(InvitationLink $link, string $plainToken, InvitationEmailContext $context): void
    {
        $this->fail ? throw new InvitationDeliveryFailed('SMTP indisponible') : $this->real->sendContactInvitation($link, $plainToken, $context);
    }

    public function sendAdministratorInvitation(InvitationLink $link, string $plainToken, InvitationEmailContext $context, string $maskedContact): void
    {
        $this->fail ? throw new InvitationDeliveryFailed('SMTP indisponible') : $this->real->sendAdministratorInvitation($link, $plainToken, $context, $maskedContact);
    }
}
