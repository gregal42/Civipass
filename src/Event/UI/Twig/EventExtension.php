<?php

declare(strict_types=1);

namespace App\Event\UI\Twig;

use App\Event\Application\AccessDenialReason;
use Twig\Attribute\AsTwigFilter;
use Twig\Attribute\AsTwigFunction;

/**
 * Présentation des événements : dates dans le fuseau de l'événement, icône du type, phrase sûre pour un refus. Les
 * motifs de refus sont des énumérations ; la phrase ne révèle jamais pourquoi une ressource est inaccessible.
 */
final class EventExtension
{
    #[AsTwigFilter('cp_datetime')]
    public function datetime(?\DateTimeInterface $date, string $timezone = 'Europe/Paris'): string
    {
        return null === $date ? '' : \DateTimeImmutable::createFromInterface($date)->setTimezone(new \DateTimeZone($timezone))->format('d/m/Y \à H:i');
    }

    #[AsTwigFunction('cp_event_icon')]
    public function icon(string $typeCode): string
    {
        return match ($typeCode) {
            'fire' => 'fire',
            'flood' => 'wave',
            'accident' => 'car',
            default => 'flag',
        };
    }

    #[AsTwigFunction('cp_denial_message')]
    public function denialMessage(?AccessDenialReason $reason): string
    {
        return match ($reason) {
            AccessDenialReason::RoleNotAllowed => 'Votre rôle dans cette commune ne permet pas cette action.',
            AccessDenialReason::MunicipalityNotWritable => 'Votre commune est suspendue : cette action n’est pas disponible.',
            AccessDenialReason::NoUsageRight => 'Votre commune n’a pas de droit d’utilisation valide pour créer un événement.',
            AccessDenialReason::EventClosed => 'L’événement est clôturé.',
            AccessDenialReason::InvalidEventState, AccessDenialReason::InvalidParticipationState => 'Cette action n’est pas possible dans l’état actuel.',
            AccessDenialReason::OrganizerParticipationBoundToEvent => 'La participation de l’organisatrice se clôture avec l’événement.',
            default => 'Cette action n’est pas disponible.',
        };
    }
}
