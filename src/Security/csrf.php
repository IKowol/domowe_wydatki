<?php

declare(strict_types=1);

/**
 * Zwraca token CSRF przypisany do bieżącej sesji.
 */
function app_csrf_token(): string
{
    $token = $_SESSION['_csrf_token'] ?? null;

    if (!is_string($token) || strlen($token) !== 64) {
        $token = bin2hex(
            random_bytes(32)
        );

        $_SESSION['_csrf_token'] = $token;
    }

    return $token;
}

/**
 * Porównuje token formularza z tokenem sesji.
 */
function app_verify_csrf_token(
    mixed $submittedToken
): bool {
    if (!is_string($submittedToken)) {
        return false;
    }

    $sessionToken = $_SESSION['_csrf_token'] ?? null;

    if (!is_string($sessionToken)) {
        return false;
    }

    return hash_equals(
        $sessionToken,
        $submittedToken
    );
}

/**
 * Generuje nowy token, np. po zalogowaniu.
 */
function app_rotate_csrf_token(): void
{
    $_SESSION['_csrf_token'] = bin2hex(
        random_bytes(32)
    );
}