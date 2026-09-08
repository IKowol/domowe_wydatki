<?php

declare(strict_types=1);

/**
 * Pobiera dodatnią liczbę całkowitą z parametrów adresu URL.
 */
function app_query_positive_int(
    string $key,
    int $default = 1
): int {
    $rawValue = $_GET[$key] ?? null;

    if (
        !is_string($rawValue)
        && !is_int($rawValue)
    ) {
        return $default;
    }

    $value = filter_var(
        $rawValue,
        FILTER_VALIDATE_INT,
        [
            'options' => [
                'min_range' => 1,
            ],
        ]
    );

    return is_int($value)
        ? $value
        : $default;
}

/**
 * Oblicza liczbę stron na podstawie liczby rekordów.
 */
function app_total_pages(
    int $totalRows,
    int $perPage
): int {
    if ($perPage < 1) {
        throw new InvalidArgumentException(
            'Liczba rekordów na stronę musi być dodatnia.'
        );
    }

    return max(
        1,
        (int) ceil($totalRows / $perPage)
    );
}