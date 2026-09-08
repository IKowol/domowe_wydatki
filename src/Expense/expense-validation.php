<?php

declare(strict_types=1);

/**
 * Normalizuje i waliduje dane formularza wydatku.
 *
 * @param array<string, mixed> $input
 *
 * @return array{
 *     data: array{
 *         owner_user_id: ?int,
 *         store_id: ?int,
 *         expense_date: string,
 *         amount: string,
 *         description: ?string
 *     },
 *     old_input: array<string, string>,
 *     errors: array<string, string>
 * }
 */
function app_validate_expense_input(
    array $input,
    bool $isAdmin,
    int $actorUserId
): array {
    $ownerRaw = $input['owner_user_id'] ?? null;
    $storeRaw = $input['store_id'] ?? null;

    $dateInput = trim(
        (string) ($input['expense_date'] ?? '')
    );

    $amountInput = trim(
        (string) ($input['amount'] ?? '')
    );

    $descriptionInput = trim(
        (string) ($input['description'] ?? '')
    );

    /*
     * Zwykły użytkownik jest zawsze właścicielem
     * własnego wydatku. Wartość przesłana z przeglądarki
     * jest w jego przypadku ignorowana.
     */
    $ownerUserId = $isAdmin
        ? filter_var(
            $ownerRaw,
            FILTER_VALIDATE_INT,
            [
                'options' => [
                    'min_range' => 1,
                ],
            ]
        )
        : $actorUserId;

    $storeId = filter_var(
        $storeRaw,
        FILTER_VALIDATE_INT,
        [
            'options' => [
                'min_range' => 1,
            ],
        ]
    );

    $errors = [];

    if ($isAdmin && !is_int($ownerUserId)) {
        $errors['owner_user_id'] =
            'Wybierz poprawnego użytkownika.';
    }

    if (!is_int($storeId)) {
        $errors['store_id'] =
            'Wybierz poprawny sklep.';
    }

    /*
     * Sprawdzamy datę ściśle w formacie YYYY-MM-DD.
     */
    $dateObject = DateTimeImmutable::createFromFormat(
        '!Y-m-d',
        $dateInput
    );

    $dateErrors = DateTimeImmutable::getLastErrors();

    $dateHasErrors = is_array($dateErrors)
        && (
            $dateErrors['warning_count'] > 0
            || $dateErrors['error_count'] > 0
        );

    if (
        !$dateObject instanceof DateTimeImmutable
        || $dateHasErrors
        || $dateObject->format('Y-m-d') !== $dateInput
    ) {
        $errors['expense_date'] =
            'Podaj poprawną datę wydatku.';
    }

    /*
     * Akceptujemy przecinek albo kropkę jako separator
     * dziesiętny. Nie korzystamy z obliczeń float.
     */
    $normalizedAmount = str_replace(
        ',',
        '.',
        $amountInput
    );

    $canonicalAmount = '';

    if (
        preg_match(
            '/\A\d+(?:\.\d{1,2})?\z/',
            $normalizedAmount
        ) !== 1
    ) {
        $errors['amount'] =
            'Kwota musi być dodatnią liczbą z maksymalnie '
            . 'dwoma miejscami po przecinku.';
    } else {
        $amountParts = explode(
            '.',
            $normalizedAmount,
            2
        );

        $wholePart = ltrim(
            $amountParts[0],
            '0'
        );

        if ($wholePart === '') {
            $wholePart = '0';
        }

        $fractionPart = $amountParts[1] ?? '';

        if (strlen($wholePart) > 10) {
            $errors['amount'] =
                'Kwota przekracza maksymalną dozwoloną wartość.';
        } else {
            $fractionPart = str_pad(
                $fractionPart,
                2,
                '0'
            );

            $canonicalAmount =
                $wholePart . '.' . $fractionPart;

            if (
                $wholePart === '0'
                && $fractionPart === '00'
            ) {
                $errors['amount'] =
                    'Kwota musi być większa od zera.';
            }
        }
    }

    $description = $descriptionInput !== ''
        ? $descriptionInput
        : null;

    if (
        $description !== null
        && mb_strlen($description, 'UTF-8') > 500
    ) {
        $errors['description'] =
            'Opis może mieć maksymalnie 500 znaków.';
    }

    return [
        'data' => [
            'owner_user_id' => is_int($ownerUserId)
                ? $ownerUserId
                : null,

            'store_id' => is_int($storeId)
                ? $storeId
                : null,

            'expense_date' => $dateInput,
            'amount' => $canonicalAmount,
            'description' => $description,
        ],

        'old_input' => [
            'owner_user_id' => is_scalar($ownerRaw)
                ? (string) $ownerRaw
                : '',

            'store_id' => is_scalar($storeRaw)
                ? (string) $storeRaw
                : '',

            'expense_date' => $dateInput,
            'amount' => $amountInput,
            'description' => $descriptionInput,
        ],

        'errors' => $errors,
    ];
}