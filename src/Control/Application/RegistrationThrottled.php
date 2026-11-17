<?php

declare(strict_types=1);

namespace App\Control\Application;

final class RegistrationThrottled extends \RuntimeException
{
    public function __construct(public readonly int $retryAfterSeconds)
    {
        parent::__construct('Trop de tentatives.');
    }
}
