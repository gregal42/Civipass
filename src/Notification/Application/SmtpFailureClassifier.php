<?php

declare(strict_types=1);

namespace App\Notification\Application;

use App\Notification\Domain\FailureKind;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

/**
 * Distingue les erreurs d'envoi permanentes des temporaires (D6) sans jamais conserver le message de l'exception : il peut citer l'adresse du
 * destinataire. Seul le code de réponse SMTP est lu.
 *
 *  - adresse invalide (RFC) : jamais réessayée ;
 *  - 5xx : refus définitif, SAUF 530, 534, 535 et 538 (authentification ou chiffrement exigé : défaut de configuration à corriger, pas un
 *    défaut du message, donc réessayable une fois la configuration réparée) ;
 *  - 4xx, coupure réseau, délai dépassé, réponse inattendue : temporaire.
 */
final class SmtpFailureClassifier
{
    private const CONFIGURATION_CODES = [530, 534, 535, 538];

    public function classify(\Throwable $failure): FailureKind
    {
        if ($failure instanceof \Symfony\Component\Mime\Exception\RfcComplianceException || ($failure instanceof \InvalidArgumentException && !$failure instanceof TransportExceptionInterface)) {
            return FailureKind::InvalidAddress;
        }
        if (1 === preg_match('/got code "(\d{3})"/', $failure->getMessage(), $match)) {
            $code = (int) $match[1];

            return $code >= 500 && !\in_array($code, self::CONFIGURATION_CODES, true) ? FailureKind::Permanent : FailureKind::Temporary;
        }

        return FailureKind::Temporary;
    }
}
