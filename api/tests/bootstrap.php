<?php

use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\Process\Process;

require dirname(__DIR__).'/vendor/autoload.php';

if (file_exists(dirname(__DIR__).'/.env.test')) {
    (new Dotenv())->bootEnv(dirname(__DIR__).'/.env.test');
}

// Refresh test database schema before Behat suite runs.
// PHPUnit uses vendor/autoload.php as bootstrap and never loads this file.
foreach ([
    // Drop all ORM-mapped tables
    ['php', 'bin/console', 'doctrine:schema:drop', '--force', '--env=test', '--no-interaction', '--quiet'],
    // Drop custom schemas left behind (schema:drop removes tables, not schemas)
    ['php', 'bin/console', 'dbal:run-sql', '--env=test', '--no-interaction',
        'DROP SCHEMA IF EXISTS document_center, telegram, monitoring, users CASCADE'],
    // Recreate full schema (schemas + tables)
    ['php', 'bin/console', 'doctrine:schema:create', '--env=test', '--no-interaction', '--quiet'],
] as $command) {
    (new Process($command, dirname(__DIR__)))->mustRun();
}
