<?php

declare(strict_types=1);

namespace App\Permit\Domain;

use App\Event\Domain\EventStatus;
use App\Shared\Domain\DomainRuleViolation;

/**
 * Règles pures du remplacement du QR code (LOT 05.5.5, arbitrages du 10 novembre 2026). Les protections contre les abus ne BLOQUENT jamais une opération légitime :
 * elles exigent une confirmation renforcée (référence ressaisie) et signalent (journal, audit) ; seules les conditions d'état refusent.
 *
 *  - Autorisation révoquée : refusé. Événement CLOS : refusé. Événement suspendu ou période expirée : AUTORISÉ (la compromission d'un QR ne dépend pas des droits).
 *  - Second remplacement dans l'heure : la référence `LP-` du laissez-passer doit être ressaisie ; le motif réel reste obligatoire. Jamais un refus autre que l'absence de cette confirmation.
 *  - Signalement « inhabituel » : au moins 3 remplacements en 24 h sur le laissez-passer, ou 10 par agent en 24 h (l'opération courante comprise). Aucun blocage.
 */
final class TokenReplacementRules
{
    public const CLOSE_WINDOW = 'PT1H';
    public const WINDOW = 'PT24H';
    public const PERMIT_THRESHOLD = 3;
    public const AGENT_THRESHOLD = 10;

    /** @throws DomainRuleViolation autorisation révoquée ou événement clos */
    public static function assertAllowed(bool $revoked, EventStatus $eventStatus): void
    {
        if ($revoked) {
            throw new DomainRuleViolation('Cette autorisation est révoquée : votre commune ne peut plus remplacer le QR code de ce laissez-passer.');
        }
        if (EventStatus::Closed === $eventStatus) {
            throw new DomainRuleViolation('L\'événement est clos : le QR code ne peut plus être remplacé.');
        }
    }

    /** Le dernier remplacement date-t-il de moins d'une heure ? */
    public static function isClose(?\DateTimeImmutable $lastReplacement, \DateTimeImmutable $now): bool
    {
        return null !== $lastReplacement && $now < $lastReplacement->add(new \DateInterval(self::CLOSE_WINDOW));
    }

    public static function isUnusual(int $permitCountIncludingThis, int $agentCountIncludingThis): bool
    {
        return $permitCountIncludingThis >= self::PERMIT_THRESHOLD || $agentCountIncludingThis >= self::AGENT_THRESHOLD;
    }

    /**
     * Confirmation renforcée d'un remplacement rapproché : la référence ressaisie doit être celle du laissez-passer (casse et espaces ignorés). Le message n'écrit jamais la saisie.
     *
     * @throws DomainRuleViolation référence absente ou différente
     */
    public static function assertReference(bool $close, string $typed, string $reference): void
    {
        if ($close && 0 !== strcasecmp(trim($typed), $reference)) {
            throw new DomainRuleViolation('Un remplacement a déjà eu lieu il y a moins d\'une heure : ressaisissez la référence du laissez-passer pour confirmer.');
        }
    }

    /** Un commentaire ne contient jamais un jeton (32 caractères base32, avec ou sans séparateurs) ni une adresse de QR code. */
    public static function containsToken(string $comment): bool
    {
        $compact = (string) preg_replace('/[\s\-_.]+/', '', $comment);

        return 1 === preg_match('/[a-z2-7]{'.VerificationToken::LENGTH.'}/i', $compact) || 1 === preg_match('#/v/#i', $comment);
    }
}
