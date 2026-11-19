<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Onboarding\Application\AdministratorValidationNotifier;
use Symfony\Component\Uid\Uuid;

/** Notifier d'enregistrement (SEC-016) : aucun e-mail réel ; conserve les alertes demandées. */
final class RecordingAdministratorValidationNotifier implements AdministratorValidationNotifier
{
    /** @var list<array{id: string, commune: string}> */
    public array $sent = [];

    public function notifyPending(Uuid $validationId, string $communeName, \DateTimeImmutable $requestedAt): bool
    {
        $this->sent[] = ['id' => $validationId->toRfc4122(), 'commune' => $communeName];

        return true;
    }
}
