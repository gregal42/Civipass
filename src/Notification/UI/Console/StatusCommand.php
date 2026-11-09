<?php

declare(strict_types=1);

namespace App\Notification\UI\Console;

use App\Notification\Application\KeyRingProvider;
use App\Notification\Domain\NotificationOutboxRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Supervision des notifications (LOT 05.3.3, D14) : volumes par état, échecs, ancienneté, configuration cryptographique, signes de blocage du
 * distributeur. Aucune adresse, aucun secret, aucune donnée personnelle, aucun identifiant de message.
 *
 * Code de sortie, au format des sondes de supervision : 0 = OK ; 1 = AVERTISSEMENT (messages abandonnés récemment) ;
 * 2 = CRITIQUE (clé indisponible, distributeur bloqué ou en retard, secrets échus non purgés).
 */
#[AsCommand(name: 'app:notifications:status', description: 'Affiche l\'état de l\'outbox et signale les anomalies (code de sortie 0 / 1 / 2)')]
final class StatusCommand extends Command
{
    public const OK = 0;
    public const WARNING = 1;
    public const CRITICAL = 2;

    public function __construct(
        private readonly NotificationOutboxRepository $outbox,
        private readonly KeyRingProvider $keys,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('max-due-age', null, InputOption::VALUE_REQUIRED, 'Retard maximal (secondes) d\'un message échu avant alerte critique', '900')
            ->addOption('max-waiting-age', null, InputOption::VALUE_REQUIRED, 'Ancienneté maximale (secondes) d\'un message en attente avant alerte critique', '86400')
            ->addOption('max-failed', null, InputOption::VALUE_REQUIRED, 'Nombre de messages abandonnés tolérés dans la fenêtre avant avertissement', '0')
            ->addOption('failed-window', null, InputOption::VALUE_REQUIRED, 'Fenêtre (secondes) des messages abandonnés pris en compte', '86400')
            ->addOption('lease-grace', null, InputOption::VALUE_REQUIRED, 'Délai (secondes) après l\'expiration d\'un bail avant de parler de réservation bloquée', '300');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $stats = $this->outbox->statistics(
            $this->clock->now(),
            new \DateInterval('PT'.max(1, (int) $input->getOption('failed-window')).'S'),
            new \DateInterval('PT'.max(0, (int) $input->getOption('lease-grace')).'S'),
        );
        $ring = $this->keys->keyRing();

        $io->section('Messages par état');
        $io->definitionList(...array_map(static fn (string $status, int $n): array => [$status => (string) $n], array_keys($stats->byStatus), $stats->byStatus) ?: [['(aucun message)' => '0']]);
        if ([] !== $stats->byErrorCode) {
            $io->section('Codes d\'anomalie');
            $io->definitionList(...array_map(static fn (string $code, int $n): array => [$code => (string) $n], array_keys($stats->byErrorCode), $stats->byErrorCode));
        }
        $io->definitionList(
            ['Plus ancien message en attente' => null === $stats->oldestWaitingSeconds ? '—' : $stats->oldestWaitingSeconds.' s'],
            ['Plus ancien message échu non traité' => null === $stats->oldestDueSeconds ? '—' : $stats->oldestDueSeconds.' s'],
            ['Réservations bloquées' => (string) $stats->stuckReservations],
            ['Messages abandonnés dans la fenêtre' => (string) $stats->failedRecently],
            ['Secrets échus non purgés' => (string) $stats->overdueSecrets],
            ['Messages en attente de clé' => (string) $stats->cryptoWaiting],
            ['Configuration cryptographique' => $ring->isUsable() ? 'OK (clé active : '.$ring->activeId().')' : 'ANOMALIE ('.$ring->problem().')'],
        );

        $critical = [];
        $warning = [];
        if (!$ring->isUsable()) {
            $critical[] = 'configuration cryptographique inutilisable : '.$ring->problem();
        }
        if ($stats->cryptoWaiting > 0) {
            $critical[] = sprintf('%d message(s) en attente de clé (CRYPTO_UNAVAILABLE)', $stats->cryptoWaiting);
        }
        if ($stats->stuckReservations > 0) {
            $critical[] = sprintf('%d réservation(s) bloquée(s) : distributeur arrêté ou planté', $stats->stuckReservations);
        }
        if (null !== $stats->oldestDueSeconds && $stats->oldestDueSeconds > (int) $input->getOption('max-due-age')) {
            $critical[] = sprintf('message échu depuis %d s : le distributeur ne passe plus', $stats->oldestDueSeconds);
        }
        if (null !== $stats->oldestWaitingSeconds && $stats->oldestWaitingSeconds > (int) $input->getOption('max-waiting-age')) {
            $critical[] = sprintf('message en attente depuis %d s', $stats->oldestWaitingSeconds);
        }
        if ($stats->overdueSecrets > 0) {
            $critical[] = sprintf('%d secret(s) échu(s) non purgé(s)', $stats->overdueSecrets);
        }
        if ($stats->failedRecently > (int) $input->getOption('max-failed')) {
            $warning[] = sprintf('%d message(s) abandonné(s) dans la fenêtre', $stats->failedRecently);
        }

        foreach ($critical as $line) {
            $io->writeln('<error>CRITIQUE : '.$line.'</error>');
        }
        foreach ($warning as $line) {
            $io->writeln('<comment>AVERTISSEMENT : '.$line.'</comment>');
        }
        if ([] !== $critical) {
            return self::CRITICAL;
        }
        if ([] !== $warning) {
            return self::WARNING;
        }
        $io->success('OK');

        return self::OK;
    }
}
