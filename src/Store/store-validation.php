<?php

declare(strict_types=1);

/**
 * Normalizuje i waliduje dane formularza sklepu.
 *
 * @param array<string, mixed> $input
 *
 * @return array{
 *     data: array{
 *         name: string,
 *         city: ?string,
 *         street: ?string,
 *         building_number: ?string,
 *         phone: ?string
 *     },
 *     old_input: array<string, string>,
 *     errors: array<string, string>
 * }
 */
function app_validate_store_input(array $input): array
{
    $name = trim(
        (string) ($input['name'] ?? '')
    );

    $cityInput = trim(
        (string) ($input['city'] ?? '')
    );

    $streetInput = trim(
        (string) ($input['street'] ?? '')
    );

    $buildingNumberInput = trim(
        (string) ($input['building_number'] ?? '')
    );

    $phoneInput = trim(
        (string) ($input['phone'] ?? '')
    );

    $city = $cityInput !== ''
        ? $cityInput
        : null;

    $street = $streetInput !== ''
        ? $streetInput
        : null;

    $buildingNumber = $buildingNumberInput !== ''
        ? $buildingNumberInput
        : null;

    $phone = $phoneInput !== ''
        ? $phoneInput
        : null;

    $errors = [];

    $nameLength = mb_strlen(
        $name,
        'UTF-8'
    );

    if ($nameLength < 1 || $nameLength > 120) {
        $errors['name'] =
            'Nazwa sklepu musi mieć od 1 do 120 znaków.';
    }

    if (
        $city !== null
        && mb_strlen($city, 'UTF-8') > 100
    ) {
        $errors['city'] =
            'Miasto może mieć maksymalnie 100 znaków.';
    }

    if (
        $street !== null
        && mb_strlen($street, 'UTF-8') > 120
    ) {
        $errors['street'] =
            'Ulica może mieć maksymalnie 120 znaków.';
    }

    if (
        $buildingNumber !== null
        && mb_strlen($buildingNumber, 'UTF-8') > 20
    ) {
        $errors['building_number'] =
            'Numer budynku może mieć maksymalnie 20 znaków.';
    }

    if (
        $phone !== null
        && (
            strlen($phone) > 20
            || preg_match(
                '/\A\+?[0-9 ()-]{7,20}\z/',
                $phone
            ) !== 1
        )
    ) {
        $errors['phone'] =
            'Numer telefonu ma nieprawidłowy format.';
    }

    return [
        'data' => [
            'name' => $name,
            'city' => $city,
            'street' => $street,
            'building_number' => $buildingNumber,
            'phone' => $phone,
        ],

        'old_input' => [
            'name' => $name,
            'city' => $cityInput,
            'street' => $streetInput,
            'building_number' => $buildingNumberInput,
            'phone' => $phoneInput,
        ],

        'errors' => $errors,
    ];
}