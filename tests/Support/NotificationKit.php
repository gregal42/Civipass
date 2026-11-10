<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Notification\Application\KeyRingProvider;
use App\Notification\Application\MessageComposer;
use App\Notification\Application\NotificationDispatcher;
use App\Notification\Application\NotificationOrdering;
use App\Notification\Application\SecretCipher;
use App\Notification\Application\SecretProvisioner;
use App\Notification\Application\SecretPurger;
use App\Notification\Application\SecretVault;
use App\Notification\Application\SmtpFailureClassifier;
use App\Permit\Application\NotificationFactsReader;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\RouterInterface;
use Twig\Environment;
use App\Notification\Domain\NotificationOutboxRepository;
use App\Notification\Domain\NotificationSecretRepository;
use App\Permit\Application\PermitRightsEvaluator;
use App\Permit\Application\SystemAccessLinks;
use App\Permit\Domain\PermitAccessTokenRepository;
use App\Permit\Domain\PermitRepository;
use App\Shared\Application\AuditTrail;
use App\Shared\Application\TransactionRunner;
use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;

/** Assemble les services du 05.3.2 avec un trousseau, une horloge et un audit choisis (partagé avec les processus de test). */
final class NotificationKit
{
    /** @return array{string, string} trousseau `id:base64` et identifiant */
    public static function newKey(string $id): array
    {
        return [$id.':'.base64_encode(sodium_crypto_aead_xchacha20poly1305_ietf_keygen()), $id];
    }

    public static function provider(?string $keyring, ?string $active): KeyRingProvider
    {
        return new KeyRingProvider($keyring, $active);
    }

    public static function links(ContainerInterface $c, ?AuditTrail $audit = null): SystemAccessLinks
    {
        return new SystemAccessLinks($c->get(PermitRepository::class), $c->get(PermitAccessTokenRepository::class), $c->get(PermitRightsEvaluator::class), $audit ?? $c->get(AuditTrail::class));
    }

    public static function provisioner(ContainerInterface $c, KeyRingProvider $keys, ClockInterface $clock, ?AuditTrail $audit = null): SecretProvisioner
    {
        return new SecretProvisioner($c->get(TransactionRunner::class), $c->get(NotificationOutboxRepository::class), $c->get(NotificationSecretRepository::class), self::links($c, $audit), new SecretCipher(), $keys, $clock);
    }

    public static function vault(ContainerInterface $c, KeyRingProvider $keys): SecretVault
    {
        return new SecretVault($c->get(NotificationOutboxRepository::class), $c->get(NotificationSecretRepository::class), new SecretCipher(), $keys);
    }

    public static function purger(ContainerInterface $c, ClockInterface $clock, ?AuditTrail $audit = null): SecretPurger
    {
        return new SecretPurger($c->get(TransactionRunner::class), $c->get(NotificationOutboxRepository::class), $c->get(NotificationSecretRepository::class), self::links($c, $audit), $clock);
    }

    /**
     * @param string|null $baseUrl domaine des liens : `null` = le VRAI compositeur du conteneur (DEFAULT_URI de la configuration) ; une valeur explicite
     *                             n'est qu'une doublure de test et ne doit jamais servir à une recette
     */
    public static function dispatcher(ContainerInterface $c, KeyRingProvider $keys, ClockInterface $clock, MailerInterface $mailer, ?LoggerInterface $logger = null, ?AuditTrail $audit = null, ?string $baseUrl = 'http://civipass.test'): NotificationDispatcher
    {
        $links = self::links($c, $audit);
        $secrets = $c->get(NotificationSecretRepository::class);
        $outbox = $c->get(NotificationOutboxRepository::class);
        $transactions = $c->get(TransactionRunner::class);

        return new NotificationDispatcher(
            $transactions,
            $c->get(EntityManagerInterface::class),
            $outbox,
            $secrets,
            new NotificationOrdering($transactions, $outbox, $audit ?? $c->get(AuditTrail::class), $clock),
            $audit ?? $c->get(AuditTrail::class),
            self::provisioner($c, $keys, $clock, $audit),
            self::vault($c, $keys),
            self::purger($c, $clock, $audit),
            $links,
            new NotificationFactsReader($c->get(PermitRepository::class), $c->get(PermitRightsEvaluator::class), $links, $c->get(\App\Permit\Domain\PermitRevisionRepository::class)),
            null === $baseUrl ? $c->get(MessageComposer::class) : new MessageComposer($c->get(Environment::class), $c->get(RouterInterface::class), $baseUrl, 'no-reply@civipass.invalid'),
            $mailer,
            new SmtpFailureClassifier(),
            $keys,
            $clock,
            $logger ?? new \Psr\Log\NullLogger(),
        );
    }

    /** Service agent avec une horloge choisie (le conteneur utilise la vraie horloge, hors de la période de l'événement de test). */
    public static function access(ContainerInterface $c, ClockInterface $clock, ?AuditTrail $audit = null): \App\Permit\Application\PermitAccessService
    {
        return new \App\Permit\Application\PermitAccessService(
            $c->get(\App\Event\Application\Support\EventTransactions::class),
            $c->get(\App\Event\Application\Support\EventLocker::class),
            $clock,
            $c->get(\App\Municipality\Application\MunicipalityContext::class),
            $c->get(PermitRepository::class),
            $c->get(PermitAccessTokenRepository::class),
            $audit ?? $c->get(AuditTrail::class),
            $c->get(\App\Permit\Application\NotificationStatusProvider::class),
            $c->get(\App\Permit\Application\ManualNotificationRequester::class),
            self::links($c, $audit),
        );
    }

    public static function outboxPurger(ContainerInterface $c, ClockInterface $clock): \App\Notification\Application\OutboxPurger
    {
        return new \App\Notification\Application\OutboxPurger($c->get(TransactionRunner::class), $c->get(NotificationOutboxRepository::class), $c->get(NotificationSecretRepository::class), self::links($c), $clock);
    }
}
