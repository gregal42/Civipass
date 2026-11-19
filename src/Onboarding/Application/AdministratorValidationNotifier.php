<?php

declare(strict_types=1);

namespace App\Onboarding\Application;

use Symfony\Component\Uid\Uuid;

/** Avertit CWS qu'une demande de premier administrateur communal attend sa validation (SEC-016). Jamais d'effet sur les droits ; l'échec d'envoi ne bloque rien. */
interface AdministratorValidationNotifier
{
    /** @return bool faux si aucun destinataire n'est configuré ou si l'envoi a échoué (la demande reste visible dans la liste des demandes en attente) */
    public function notifyPending(Uuid $validationId, string $communeName, \DateTimeImmutable $requestedAt): bool;
}
