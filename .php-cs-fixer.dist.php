<?php

declare(strict_types=1);

$finder = (new PhpCsFixer\Finder())
    ->in([
        __DIR__.'/src',
        __DIR__.'/tests',
    ])
;

return (new PhpCsFixer\Config())
    ->setRules([
        '@Symfony' => true,
        '@PSR12' => true,
        'declare_strict_types' => true,
    ])
    ->setRiskyAllowed(true)
    ->setFinder($finder)
    ->setCacheFile(__DIR__.'/var/cache/.php-cs-fixer.cache')
    ;
