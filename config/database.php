<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

if (!function_exists('sqlsrv_connect')) {
    error_log(
        'Rozszerzenie sqlsrv nie jest aktywne.'
    );

    throw new RuntimeException(
        'Połączenie z bazą danych jest obecnie niedostępne.'
    );
}

$encrypt = filter_var(
    $_ENV['DB_ENCRYPT'],
    FILTER_VALIDATE_BOOLEAN,
    FILTER_NULL_ON_FAILURE
);

$trustServerCertificate = filter_var(
    $_ENV['DB_TRUST_SERVER_CERTIFICATE'],
    FILTER_VALIDATE_BOOLEAN,
    FILTER_NULL_ON_FAILURE
);

if (
    $encrypt === null
    || $trustServerCertificate === null
) {
    error_log(
        'Nieprawidłowe wartości konfiguracji szyfrowania SQL Server.'
    );

    throw new RuntimeException(
        'Konfiguracja bazy danych jest nieprawidłowa.'
    );
}

$connectionOptions = [
    'Database' => $_ENV['DB_DATABASE'],
    'UID' => $_ENV['DB_USERNAME'],
    'PWD' => $_ENV['DB_PASSWORD'],
    'CharacterSet' => 'UTF-8',
    'Encrypt' => $encrypt,
    'TrustServerCertificate' => $trustServerCertificate,
    'LoginTimeout' => 5,
];

$connection = sqlsrv_connect(
    $_ENV['DB_SERVER'],
    $connectionOptions
);

if ($connection === false) {
    error_log(
        'Błąd połączenia SQL Server: '
        . json_encode(
            sqlsrv_errors(SQLSRV_ERR_ALL),
            JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
        )
    );

    throw new RuntimeException(
        'Nie udało się połączyć z bazą danych.'
    );
}

return $connection;