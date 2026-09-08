<?php

declare(strict_types=1);

/**
 * Przekierowuje użytkownika i zawsze zatrzymuje skrypt.
 */
function app_redirect(
    string $location,
    int $statusCode = 303
): never {
    if ($statusCode < 300 || $statusCode > 399) {
        throw new InvalidArgumentException(
            'Kod przekierowania musi należeć do zakresu 3xx.'
        );
    }

    header(
        'Location: ' . $location,
        true,
        $statusCode
    );

    exit;
}

/**
 * Bezpiecznie koduje wartość wyświetlaną w HTML.
 */
function app_e(mixed $value): string
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

/**
 * Wymaga żądania HTTP POST.
 */
function app_require_post(): void
{
    $method = strtoupper(
        (string) ($_SERVER['REQUEST_METHOD'] ?? '')
    );

    if ($method === 'POST') {
        return;
    }

    header('Allow: POST');
    http_response_code(405);

    echo 'Ta operacja wymaga żądania POST.';
    exit;
}

/**
 * Zapisuje jednorazowy komunikat w sesji.
 */
function app_flash(
    string $type,
    string $message
): void {
    $_SESSION['_flash'][$type][] = $message;
}

/**
 * Pobiera i usuwa komunikaty danego typu.
 *
 * @return list<string>
 */
function app_consume_flash(string $type): array
{
    $messages = $_SESSION['_flash'][$type] ?? [];

    unset($_SESSION['_flash'][$type]);

    if (!is_array($messages)) {
        return [];
    }

    return array_values(
        array_filter(
            $messages,
            static fn (mixed $message): bool =>
                is_string($message)
        )
    );
}