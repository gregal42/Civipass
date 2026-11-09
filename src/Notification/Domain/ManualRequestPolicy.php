<?php

declare(strict_types=1);

namespace App\Notification\Domain;

use App\Permit\Domain\ManualNotificationOutcome;

/**
 * Limites des renvois manuels d'un laissez-passer (LOT 05.3.5), fonction PURE des dates des demandes déjà ACCEPTÉES : au plus une demande toutes
 * les 5 minutes et trois par période glissante de 24 heures (un message de mise à disposition en attente à la fois est vérifié ailleurs). Les
 * valeurs sont provisoires, à confirmer à l'usage. Le renvoi manuel d'un LIEN par un agent (LOT 05.2) n'est jamais limité : c'est la procédure exceptionnelle.
 */
final class ManualRequestPolicy
{
    public const MIN_INTERVAL = 'PT5M';
    public const WINDOW = 'PT24H';
    public const MAX_PER_WINDOW = 3;

    /**
     * @param list<\DateTimeImmutable> $acceptedAt dates des demandes déjà acceptées pour ce laissez-passer
     *
     * @return ManualNotificationOutcome|null le refus, ou `null` si la demande est acceptable
     */
    public static function refusal(array $acceptedAt, \DateTimeImmutable $now): ?ManualNotificationOutcome
    {
        $windowStart = $now->sub(new \DateInterval(self::WINDOW));
        $recent = array_values(array_filter($acceptedAt, static fn (\DateTimeImmutable $at): bool => $at > $windowStart));
        if ([] !== $recent && max($recent) > $now->sub(new \DateInterval(self::MIN_INTERVAL))) {
            return ManualNotificationOutcome::TooSoon;
        }

        return \count($recent) >= self::MAX_PER_WINDOW ? ManualNotificationOutcome::DailyLimitReached : null;
    }
}
