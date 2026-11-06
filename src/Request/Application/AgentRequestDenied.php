<?php

declare(strict_types=1);

namespace App\Request\Application;

/** L'agent voit la ressource mais ne peut pas y déposer : rôle absent, événement ou participation non opérationnels (403). */
final class AgentRequestDenied extends \RuntimeException
{
}
