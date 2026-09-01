<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

/*
 * Make the test environment win over the ambient one.
 *
 * `<env force="true">` in phpunit.xml sets getenv() and $_ENV, but not $_SERVER.
 * Laravel's Env reads $_SERVER first, so anything exported by the container
 * (docker compose passes DB_CONNECTION, CACHE_STORE, … into the process) would
 * silently override the test configuration and point the suite at the live
 * database. Drop those keys from $_SERVER so the phpunit.xml values apply.
 */
$config = simplexml_load_file(__DIR__.'/../phpunit.xml');

foreach ($config->php->env ?? [] as $env) {
    unset($_SERVER[(string) $env['name']]);
}
