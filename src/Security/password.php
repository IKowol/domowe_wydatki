<?php

declare(strict_types=1);

const APP_PASSWORD_MIN_CHARACTERS = 12;
const APP_PASSWORD_MAX_BYTES = 72;

/**
 * @return array<string, string>
 */
function app_validate_new_password(
    string $password,
    string $confirmation
): array {
    $errors = [];

    if (
        mb_strlen($password, 'UTF-8')
        < APP_PASSWORD_MIN_CHARACTERS
    ) {
        $errors['password'] =
            'Hasło musi mieć co najmniej 12 znaków.';
    }

    /*
     * PASSWORD_DEFAULT w używanym środowisku PHP 8.3
     * korzysta z bcrypt. Nie pozwalamy więc przekroczyć
     * jego limitu 72 bajtów.
     */
    if (strlen($password) > APP_PASSWORD_MAX_BYTES) {
        $errors['password'] =
            'Hasło może mieć maksymalnie 72 bajty.';
    }

    if (!hash_equals($password, $confirmation)) {
        $errors['password_confirmation'] =
            'Podane hasła nie są identyczne.';
    }

    return $errors;
}