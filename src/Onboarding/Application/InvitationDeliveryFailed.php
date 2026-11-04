<?php

declare(strict_types=1);

namespace App\Onboarding\Application;

/** L'e-mail d'invitation n'a pas pu être envoyé : l'invitation reste enregistrée et peut être renvoyée. */
final class InvitationDeliveryFailed extends \RuntimeException
{
}
