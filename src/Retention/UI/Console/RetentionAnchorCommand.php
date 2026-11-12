<?php

declare(strict_types=1);

namespace App\Retention\UI\Console;

use App\Ops\Application\OpsSettings;
use App\Retention\Application\RegisterAnchors;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

/**
 * Point d'ancrage du registre (LOT 05.6.7) : inscrit l'empreinte de tête dans le fichier d'ancrage ET l'émet sur deux canaux indépendants du serveur du registre : le journal (à
 * transmettre à un collecteur distant) et le courriel d'exploitation. Il n'ancre jamais une chaîne rompue ou un ancrage incohérent (code de sortie non nul : alerte).
 */
#[AsCommand(name: 'app:retention:anchor', description: 'Inscrit et émet un point d\'ancrage de la chaîne du registre de purge.')]
final class RetentionAnchorCommand extends Command
{
    public function __construct(
        private readonly RegisterAnchors $anchors,
        private readonly OpsSettings $ops,
        private readonly MailerInterface $mailer,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(MAILER_FROM)%')] private readonly string $from,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $result = $this->anchors->emit();
        } catch (\RuntimeException $e) {
            $output->writeln('<error>'.$e->getMessage().'</error>');

            return Command::FAILURE;
        }
        if (!$result['ok']) {
            $output->writeln('<error>Point d\'ancrage NON émis : '.$result['reason'].' (incident : voir le runbook).</error>');

            return Command::FAILURE;
        }
        $json = json_encode($result['line'], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);
        $this->logger->notice('retention.anchor '.$json);
        $output->writeln($json);
        if ('' !== $this->ops->alertEmail()) {
            $this->mailer->send((new Email())->from($this->from)->to($this->ops->alertEmail())->subject('Civipass — point d\'ancrage du registre de purge')->text($json."\n\nConserver ce message hors du serveur : il sert à vérifier le registre (app:retention:restore-check --external-anchors). Aucune donnée personnelle.\n"));
        }

        return Command::SUCCESS;
    }
}
