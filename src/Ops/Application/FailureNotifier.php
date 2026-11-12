<?php

declare(strict_types=1);

namespace App\Ops\Application;

use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

/** Alerte d'exploitation sur échec d'une unité (LOT 05.6.7) : nom de l'unité, hôte et date seulement, jamais une donnée personnelle. */
final class FailureNotifier
{
    public const UNIT_PATTERN = '/^[A-Za-z0-9@._:-]{1,80}$/';

    public function __construct(
        private readonly OpsSettings $settings,
        private readonly MailerInterface $mailer,
        private readonly ClockInterface $clock,
        #[Autowire('%env(MAILER_FROM)%')] private readonly string $from,
    ) {
    }

    /** @return bool faux si aucune adresse d'alerte n'est configurée (l'échec reste dans le journal du système) */
    public function notify(string $unit): bool
    {
        if (1 !== preg_match(self::UNIT_PATTERN, $unit) || '' === $this->settings->alertEmail()) {
            return false;
        }
        $this->mailer->send((new Email())->from($this->from)->to($this->settings->alertEmail())
            ->subject('Civipass — échec de la tâche '.$unit)
            ->text(sprintf("La tâche d'exploitation « %s » a échoué sur %s le %s.\nConsultez : journalctl -u %s\nAucune donnée personnelle n'est jointe à cette alerte.\n", $unit, (string) gethostname(), $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i \U\T\C'), $unit)));

        return true;
    }
}
