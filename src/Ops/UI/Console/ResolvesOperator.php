<?php

declare(strict_types=1);

namespace App\Ops\UI\Console;

use App\Identity\Domain\User;
use App\Identity\Domain\UserRepository;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Uid\Uuid;

/** Opérateur CWS désigné par `--operator=<identifiant>` (il doit être super-administrateur actif : contrôlé par les services). */
trait ResolvesOperator
{
    private function operator(InputInterface $input, UserRepository $users): ?User
    {
        $id = (string) $input->getOption('operator');

        return Uuid::isValid($id) ? $users->find(Uuid::fromString($id)) : null;
    }
}
