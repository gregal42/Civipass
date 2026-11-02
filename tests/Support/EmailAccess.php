<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\Mime\Email;

/**
 * Accès typé aux emails capturés par le transport de test.
 */
trait EmailAccess
{
    protected static function emailAt(int $index): Email
    {
        $message = self::getMailerMessage($index);
        self::assertInstanceOf(Email::class, $message);

        return $message;
    }

    /**
     * @return list<Email>
     */
    protected static function emails(): array
    {
        $emails = [];
        foreach (self::getMailerMessages() as $message) {
            self::assertInstanceOf(Email::class, $message);
            $emails[] = $message;
        }

        return $emails;
    }

    protected static function bodiesOf(Email $email): string
    {
        return (string) $email->getTextBody().(string) $email->getHtmlBody();
    }
}
