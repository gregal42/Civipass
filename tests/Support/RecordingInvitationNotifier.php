<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Onboarding\Application\InvitationNotifier;
use App\Onboarding\Domain\InvitationEmailContext;
use App\Onboarding\Domain\InvitationLink;

/** Notifier de test : n'envoie rien, mémorise chaque lien émis et son jeton EN CLAIR (la seule trace du jeton hors e-mail). */
final class RecordingInvitationNotifier implements InvitationNotifier
{
    /** @var list<array{kind: string, to: string, token: string, context: InvitationEmailContext, maskedContact: ?string}> */
    public array $sent = [];

    public function sendContactInvitation(InvitationLink $link, string $plainToken, InvitationEmailContext $context): void
    {
        $this->sent[] = ['kind' => 'CONTACT', 'to' => $link->recipientEmail(), 'token' => $plainToken, 'context' => $context, 'maskedContact' => null];
    }

    public function sendAdministratorInvitation(InvitationLink $link, string $plainToken, InvitationEmailContext $context, string $maskedContact): void
    {
        $this->sent[] = ['kind' => 'ADMINISTRATOR', 'to' => $link->recipientEmail(), 'token' => $plainToken, 'context' => $context, 'maskedContact' => $maskedContact];
    }

    public function last(string $kind): string
    {
        foreach (array_reverse($this->sent) as $mail) {
            if ($kind === $mail['kind']) {
                return $mail['token'];
            }
        }
        throw new \LogicException('Aucun e-mail '.$kind.' enregistré.');
    }

    /** @return list<array{kind: string, to: string, token: string, context: InvitationEmailContext, maskedContact: ?string}> */
    public function ofKind(string $kind): array
    {
        return array_values(array_filter($this->sent, static fn (array $m): bool => $kind === $m['kind']));
    }
}
