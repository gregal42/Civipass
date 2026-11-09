<?php

declare(strict_types=1);

namespace App\Notification\Domain;

/**
 * Politique de regroupement (D5, LOT 05.3.4), fonction PURE et déterministe des dates d'événement : le même ensemble de changements donne
 * toujours la même échéance, quel que soit l'ordre d'arrivée.
 *
 *  - première mise à disposition et révocation : aucune attente ;
 *  - changement ordinaire : 10 minutes de silence après le DERNIER changement du groupe, sans jamais dépasser 60 minutes après le PREMIER ;
 *  - un groupe = les changements ordinaires encore en attente d'un même laissez-passer (un message envoyé ferme le groupe).
 */
final class SchedulePolicy
{
    public const SILENCE = 'PT10M';
    public const MAX_WAIT = 'PT60M';

    /** Échéance d'un changement isolé. */
    public static function availableAt(NotificationType $type, \DateTimeImmutable $occurredAt): \DateTimeImmutable
    {
        return match ($type->category()) {
            ChangeCategory::FirstIssuance, ChangeCategory::Revocation => $occurredAt,
            ChangeCategory::Ordinary => $occurredAt->add(new \DateInterval(self::SILENCE)),
        };
    }

    /** Échéance COMMUNE d'un groupe de changements ordinaires. */
    public static function groupAvailableAt(\DateTimeImmutable $firstOccurredAt, \DateTimeImmutable $lastOccurredAt): \DateTimeImmutable
    {
        return min($lastOccurredAt->add(new \DateInterval(self::SILENCE)), self::deadline($firstOccurredAt));
    }

    /** Date limite au-delà de laquelle le regroupement ne doit plus retarder le message. */
    public static function deadline(\DateTimeImmutable $firstOccurredAt): \DateTimeImmutable
    {
        return $firstOccurredAt->add(new \DateInterval(self::MAX_WAIT));
    }
}
