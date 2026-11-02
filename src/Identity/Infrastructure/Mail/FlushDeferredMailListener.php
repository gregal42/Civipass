<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Mail;

use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\TerminateEvent;

final readonly class FlushDeferredMailListener
{
    public function __construct(private DeferredMailQueue $queue)
    {
    }

    #[AsEventListener(event: TerminateEvent::class)]
    public function onKernelTerminate(TerminateEvent $event): void
    {
        $this->queue->flush();
    }

    #[AsEventListener(event: ConsoleTerminateEvent::class)]
    public function onConsoleTerminate(ConsoleTerminateEvent $event): void
    {
        $this->queue->flush();
    }
}
