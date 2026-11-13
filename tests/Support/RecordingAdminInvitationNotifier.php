<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Onboarding\Application\AdminInvitationNotifier;
use App\Onboarding\Application\InvitationDeliveryFailed;
use App\Onboarding\Domain\MunicipalityAdminInvitation;

/** Notifier de test : n'envoie rien, mémorise le jeton EN CLAIR (seule trace hors e-mail) ; peut simuler une panne SMTP. */
final class RecordingAdminInvitationNotifier implements AdminInvitationNotifier
{
    /** @var list<array{to: string, token: string, inviter: ?string, roles: list<string>}> */
    public array $sent = [];
    public bool $failing = false;

    public function send(MunicipalityAdminInvitation $invitation, #[\SensitiveParameter] string $plainToken, ?string $inviterName = null): void
    {
        if ($this->failing) {
            throw new InvitationDeliveryFailed('Envoi impossible (test).');
        }
        $this->sent[] = ['to' => $invitation->recipientEmail(), 'token' => $plainToken, 'inviter' => $inviterName, 'roles' => array_map(static fn ($r): string => $r->value, $invitation->roles())];
    }

    public function lastToken(): string
    {
        return [] === $this->sent ? throw new \LogicException('Aucun e-mail enregistré.') : $this->sent[array_key_last($this->sent)]['token'];
    }
}
