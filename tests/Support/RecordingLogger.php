<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Psr\Log\AbstractLogger;

/** Journal en mémoire : permet de vérifier qu'aucun secret ni adresse n'est écrit. */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<string> */
    public array $lines = [];

    public function log($level, \Stringable|string $message, array $context = []): void
    {
        $this->lines[] = $level.' '.$message.' '.json_encode($context, \JSON_INVALID_UTF8_SUBSTITUTE);
    }
}
