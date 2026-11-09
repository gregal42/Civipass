<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notification;

use App\Notification\Application\SmtpFailureClassifier;
use App\Notification\Domain\FailureKind;
use App\Tests\Support\RecordingMailer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mime\Address;

/** Erreurs permanentes et temporaires (D6) : seul le code de réponse SMTP est lu, jamais le message. */
final class SmtpFailureClassifierTest extends TestCase
{
    /** @return iterable<string, array{int, FailureKind}> */
    public static function codes(): iterable
    {
        foreach ([421, 450, 451, 452] as $code) {
            yield 'temporaire '.$code => [$code, FailureKind::Temporary];
        }
        foreach ([550, 551, 552, 553, 554] as $code) {
            yield 'permanente '.$code => [$code, FailureKind::Permanent];
        }
        foreach ([530, 534, 535, 538] as $code) {
            yield 'configuration '.$code => [$code, FailureKind::Temporary];
        }
    }

    #[DataProvider('codes')]
    public function testSmtpCodesAreClassified(int $code, FailureKind $expected): void
    {
        self::assertSame($expected, (new SmtpFailureClassifier())->classify(RecordingMailer::smtpFailure($code)));
    }

    public function testNetworkFailuresAndUnknownErrorsAreTemporary(): void
    {
        $classifier = new SmtpFailureClassifier();

        self::assertSame(FailureKind::Temporary, $classifier->classify(new TransportException('Connection could not be established with host "mail:25"')));
        self::assertSame(FailureKind::Temporary, $classifier->classify(new \RuntimeException('autre')));
    }

    public function testAnInvalidAddressIsNeverRetried(): void
    {
        $failure = null;
        try {
            new Address('pas une adresse');
        } catch (\InvalidArgumentException $e) {
            $failure = $e;
        }

        self::assertNotNull($failure);
        self::assertSame(FailureKind::InvalidAddress, (new SmtpFailureClassifier())->classify($failure));
    }
}
