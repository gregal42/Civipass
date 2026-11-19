<?php

declare(strict_types=1);

namespace App\Tests\Unit\Onboarding;

use App\Onboarding\Infrastructure\MailAdministratorValidationNotifier;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;
use Symfony\Component\Uid\Uuid;

/** SEC-016 : l'alerte à CWS ne contient que le nécessaire, a un destinataire configurable et ne bloque jamais en cas d'échec. */
final class MailAdministratorValidationNotifierTest extends TestCase
{
    private function mailer(?\Throwable $fail = null): object
    {
        return new class ($fail) implements MailerInterface {
            /** @var list<RawMessage> */
            public array $sent = [];

            public function __construct(private readonly ?\Throwable $fail)
            {
            }

            public function send(RawMessage $message, ?\Symfony\Component\Mailer\Envelope $envelope = null): void
            {
                if (null !== $this->fail) {
                    throw $this->fail;
                }
                $this->sent[] = $message;
            }
        };
    }

    public function testTheAlertCarriesOnlyWhatIsNeededToProcessTheRequest(): void
    {
        $mailer = $this->mailer();
        $id = Uuid::v7();

        $ok = (new MailAdministratorValidationNotifier($mailer, new NullLogger(), 'no-reply@civipass.example', 'validation@cws.example', 'ops@cws.example'))
            ->notifyPending($id, "Ville\nde Test", new \DateTimeImmutable('2026-11-19 10:30:00', new \DateTimeZone('UTC')));

        self::assertTrue($ok);
        $mail = $mailer->sent[0];
        self::assertInstanceOf(Email::class, $mail);
        self::assertSame('validation@cws.example', $mail->getTo()[0]->getAddress(), 'destinataire configurable');
        $body = (string) $mail->getTextBody();
        self::assertStringContainsString('Ville de Test', $body);
        self::assertStringContainsString($id->toRfc4122(), $body);
        self::assertStringContainsString('2026-11-19 10:30 UTC', $body);
        self::assertStringContainsString('Aucun droit n\'est attribué avant validation', $body);
        self::assertStringNotContainsString('http', $body, 'aucun lien d\'action');
        self::assertStringNotContainsString('@mairie', $body, 'aucune adresse de demandeur');
    }

    public function testTheOpsAddressIsTheFallbackAndNoRecipientMeansNoMail(): void
    {
        $mailer = $this->mailer();
        (new MailAdministratorValidationNotifier($mailer, new NullLogger(), 'no-reply@civipass.example', '', 'ops@cws.example'))->notifyPending(Uuid::v7(), 'X', new \DateTimeImmutable());
        self::assertSame('ops@cws.example', $mailer->sent[0]->getTo()[0]->getAddress());

        $none = $this->mailer();
        self::assertFalse((new MailAdministratorValidationNotifier($none, new NullLogger(), 'no-reply@civipass.example', ' ', ''))->notifyPending(Uuid::v7(), 'X', new \DateTimeImmutable()));
        self::assertSame([], $none->sent);
    }

    public function testASendFailureNeverPropagates(): void
    {
        $failing = $this->mailer(new \RuntimeException('SMTP indisponible'));

        self::assertFalse((new MailAdministratorValidationNotifier($failing, new NullLogger(), 'no-reply@civipass.example', 'v@cws.example', ''))->notifyPending(Uuid::v7(), 'X', new \DateTimeImmutable()));
    }
}
