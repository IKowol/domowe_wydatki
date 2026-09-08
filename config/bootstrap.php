<?php

declare(strict_types=1);

use Dotenv\Dotenv;

$rootPath = dirname(__DIR__);
$logDirectory = $rootPath . '/storage/logs';

/*
 * Nigdy nie pokazujemy szczegółów błędów
 * użytkownikowi aplikacji.
 *
 * Szczegóły mają trafiać wyłącznie do logów.
 *
 * Ustawiamy to przed operacjami, które same
 * mogą zakończyć się wyjątkiem.
 */
error_reporting(E_ALL);

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');

if (
    !is_dir($logDirectory)
    && !mkdir($logDirectory, 0775, true)
    && !is_dir($logDirectory)
) {
    throw new RuntimeException(
        'Nie udało się utworzyć katalogu logów.'
    );
}

ini_set(
    'error_log',
    $logDirectory . '/app.log'
);

$autoloadPath =
    $rootPath . '/vendor/autoload.php';

if (!is_file($autoloadPath)) {
    throw new RuntimeException(
        'Nie znaleziono autoloadera Composera. '
        . 'Uruchom composer install.'
    );
}

require_once $autoloadPath;

$dotenv = Dotenv::createImmutable(
    $rootPath
);

$dotenv->load();

$dotenv
    ->required([
        'APP_ENV',
        'APP_DEBUG',
        'DB_SERVER',
        'DB_DATABASE',
        'DB_USERNAME',
        'DB_PASSWORD',
        'DB_ENCRYPT',
        'DB_TRUST_SERVER_CERTIFICATE',
    ])
    ->notEmpty();

$dotenv
    ->required([
        'APP_DEBUG',
        'DB_ENCRYPT',
        'DB_TRUST_SERVER_CERTIFICATE',
    ])
    ->isBoolean();