<?php

declare(strict_types=1);

/**
 * Zapisuje błędy i bezpieczne wartości formularza
 * na czas jednego przekierowania.
 *
 * @param array<string, string> $errors
 * @param array<string, string> $oldInput
 */
function app_store_form_state(
    string $formName,
    array $errors,
    array $oldInput
): void {
    $_SESSION['_form_state'][$formName] = [
        'errors' => $errors,
        'old_input' => $oldInput,
    ];
}

/**
 * Pobiera i usuwa stan formularza.
 *
 * @return array{
 *     errors: array<string, string>,
 *     old_input: array<string, string>
 * }
 */
function app_consume_form_state(
    string $formName
): array {
    $state = $_SESSION['_form_state'][$formName] ?? null;

    unset($_SESSION['_form_state'][$formName]);

    if (!is_array($state)) {
        return [
            'errors' => [],
            'old_input' => [],
        ];
    }

    $errors = [];

    foreach (($state['errors'] ?? []) as $field => $message) {
        if (is_string($field) && is_string($message)) {
            $errors[$field] = $message;
        }
    }

    $oldInput = [];

    foreach (($state['old_input'] ?? []) as $field => $value) {
        if (is_string($field) && is_string($value)) {
            $oldInput[$field] = $value;
        }
    }

    return [
        'errors' => $errors,
        'old_input' => $oldInput,
    ];
}