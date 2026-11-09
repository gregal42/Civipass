<?php

declare(strict_types=1);

namespace App\Notification\Domain;

/**
 * Réessais des erreurs SMTP temporaires (D6) : 1, 5, 15, 60 puis 240 minutes ; abandon après six tentatives ou 24 heures à compter de la
 * PREMIÈRE tentative (jamais pendant une attente de clé). Fonction pure : le même état donne toujours la même décision.
 */
final class RetryPolicy
{
    /** Minutes d'attente après l'échec de la tentative n (1 à 5). */
    public const DELAYS_MINUTES = [1, 5, 15, 60, 240];
    public const MAX_ATTEMPTS = 6;
    public const WINDOW = 'PT24H';

    /**
     * @param int $attemptsMade tentatives déjà faites, celle qui vient d'échouer comprise
     *
     * @return \DateTimeImmutable prochaine date d'essai, ou l'`OutboxErrorCode` d'abandon
     */
    public static function decide(int $attemptsMade, \DateTimeImmutable $firstAttemptAt, \DateTimeImmutable $now): \DateTimeImmutable|OutboxErrorCode
    {
        $deadline = $firstAttemptAt->add(new \DateInterval(self::WINDOW));
        if ($attemptsMade >= self::MAX_ATTEMPTS) {
            return OutboxErrorCode::SmtpExhausted;
        }
        if ($now >= $deadline) {
            return OutboxErrorCode::SmtpWindowExpired;
        }
        $next = $now->add(new \DateInterval('PT'.self::DELAYS_MINUTES[max(1, $attemptsMade) - 1].'M'));

        // Un essai planifié au-delà des 24 heures n'aurait jamais lieu : on l'annonce tout de suite.
        return $next >= $deadline ? OutboxErrorCode::SmtpWindowExpired : $next;
    }

    /** Une réservation ne doit pas être prise si les plafonds sont déjà atteints (reprise après arrêt brutal, par exemple). */
    public static function exhausted(int $attemptsMade, ?\DateTimeImmutable $firstAttemptAt, \DateTimeImmutable $now): ?OutboxErrorCode
    {
        if ($attemptsMade >= self::MAX_ATTEMPTS) {
            return OutboxErrorCode::SmtpExhausted;
        }
        if (null !== $firstAttemptAt && $now >= $firstAttemptAt->add(new \DateInterval(self::WINDOW))) {
            return OutboxErrorCode::SmtpWindowExpired;
        }

        return null;
    }
}
