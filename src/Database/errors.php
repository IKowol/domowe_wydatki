<?php

declare(strict_types=1);

function app_log_sqlsrv_errors(
    string $context
): void {
    $errors = sqlsrv_errors(
        SQLSRV_ERR_ALL
    );

    $encodedErrors = json_encode(
        $errors ?? [],
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_INVALID_UTF8_SUBSTITUTE
    );

    if ($encodedErrors === false) {
        $encodedErrors =
            '[nie udało się zakodować błędów SQL Server]';
    }

    error_log(
        $context . ': ' . $encodedErrors
    );
}