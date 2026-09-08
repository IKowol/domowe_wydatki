<?php

declare(strict_types=1);

/**
 * @return array{
 *     canonical: ?string,
 *     cents: ?int,
 *     error: ?string
 * }
 */
function app_parse_expense_filter_amount(string $input): array
{
    if ($input === '') {
        return [
            'canonical' => null,
            'cents' => null,
            'error' => null,
        ];
    }

    $normalized = str_replace(',', '.', $input);

    if (
        preg_match(
            '/\A\d+(?:\.\d{1,2})?\z/',
            $normalized
        ) !== 1
    ) {
        return [
            'canonical' => null,
            'cents' => null,
            'error' =>
                'Kwota musi być liczbą z maksymalnie '
                . 'dwoma miejscami po przecinku.',
        ];
    }

    $parts = explode('.', $normalized, 2);

    $wholePart = ltrim($parts[0], '0');

    if ($wholePart === '') {
        $wholePart = '0';
    }

    if (strlen($wholePart) > 10) {
        return [
            'canonical' => null,
            'cents' => null,
            'error' =>
                'Kwota przekracza maksymalną wartość.',
        ];
    }

    $fractionPart = str_pad(
        $parts[1] ?? '',
        2,
        '0'
    );

    $canonical = $wholePart . '.' . $fractionPart;

    $cents =
        ((int) $wholePart * 100)
        + (int) $fractionPart;

    return [
        'canonical' => $canonical,
        'cents' => $cents,
        'error' => null,
    ];
}

/**
 * Zwraca poprawną datę YYYY-MM-DD albo NULL.
 */
function app_parse_expense_filter_date(
    string $input
): ?string {
    if ($input === '') {
        return null;
    }

    $date = DateTimeImmutable::createFromFormat(
        '!Y-m-d',
        $input
    );

    $dateErrors = DateTimeImmutable::getLastErrors();

    $hasErrors = is_array($dateErrors)
        && (
            $dateErrors['warning_count'] > 0
            || $dateErrors['error_count'] > 0
        );

    if (
        !$date instanceof DateTimeImmutable
        || $hasErrors
        || $date->format('Y-m-d') !== $input
    ) {
        return null;
    }

    return $input;
}

/**
 * @param array<string, mixed> $input
 *
 * @return array{
 *     filters: array{
 *         owner_user_id: ?int,
 *         store_id: ?int,
 *         date_from: ?string,
 *         date_to: ?string,
 *         amount_min: ?string,
 *         amount_max: ?string
 *     },
 *     old_input: array<string, string>,
 *     sort: string,
 *     errors: array<string, string>
 * }
 */
function app_validate_expense_filters(
    array $input,
    bool $isAdmin
): array {
    $readScalar = static function (
        string $key
    ) use ($input): string {
        $value = $input[$key] ?? '';

        return is_scalar($value)
            ? trim((string) $value)
            : '';
    };

    $ownerInput = $readScalar('owner_user_id');
    $storeInput = $readScalar('store_id');
    $dateFromInput = $readScalar('date_from');
    $dateToInput = $readScalar('date_to');
    $amountMinInput = $readScalar('amount_min');
    $amountMaxInput = $readScalar('amount_max');
    $sortInput = $readScalar('sort');

    $errors = [];

    $ownerUserId = null;

    if ($isAdmin && $ownerInput !== '') {
        $parsedOwner = filter_var(
            $ownerInput,
            FILTER_VALIDATE_INT,
            [
                'options' => [
                    'min_range' => 1,
                ],
            ]
        );

        if (is_int($parsedOwner)) {
            $ownerUserId = $parsedOwner;
        } else {
            $errors['owner_user_id'] =
                'Wybrano nieprawidłowego użytkownika.';
        }
    }

    $storeId = null;

    if ($storeInput !== '') {
        $parsedStore = filter_var(
            $storeInput,
            FILTER_VALIDATE_INT,
            [
                'options' => [
                    'min_range' => 1,
                ],
            ]
        );

        if (is_int($parsedStore)) {
            $storeId = $parsedStore;
        } else {
            $errors['store_id'] =
                'Wybrano nieprawidłowy sklep.';
        }
    }

    $dateFrom = app_parse_expense_filter_date(
        $dateFromInput
    );

    if ($dateFromInput !== '' && $dateFrom === null) {
        $errors['date_from'] =
            'Data początkowa jest nieprawidłowa.';
    }

    $dateTo = app_parse_expense_filter_date(
        $dateToInput
    );

    if ($dateToInput !== '' && $dateTo === null) {
        $errors['date_to'] =
            'Data końcowa jest nieprawidłowa.';
    }

    if (
        $dateFrom !== null
        && $dateTo !== null
        && $dateFrom > $dateTo
    ) {
        $errors['date_range'] =
            'Data początkowa nie może być późniejsza '
            . 'od daty końcowej.';
    }

    $amountMinResult =
        app_parse_expense_filter_amount(
            $amountMinInput
        );

    if ($amountMinResult['error'] !== null) {
        $errors['amount_min'] =
            $amountMinResult['error'];
    }

    $amountMaxResult =
        app_parse_expense_filter_amount(
            $amountMaxInput
        );

    if ($amountMaxResult['error'] !== null) {
        $errors['amount_max'] =
            $amountMaxResult['error'];
    }

    if (
        $amountMinResult['cents'] !== null
        && $amountMaxResult['cents'] !== null
        && $amountMinResult['cents']
            > $amountMaxResult['cents']
    ) {
        $errors['amount_range'] =
            'Kwota minimalna nie może być większa '
            . 'od kwoty maksymalnej.';
    }

    $allowedSorts = [
        'date_desc',
        'date_asc',
        'amount_desc',
        'amount_asc',
        'created_desc',
        'created_asc',
    ];

    $sort = in_array(
        $sortInput,
        $allowedSorts,
        true
    )
        ? $sortInput
        : 'date_desc';

    return [
        'filters' => [
            'owner_user_id' => $ownerUserId,
            'store_id' => $storeId,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'amount_min' =>
                $amountMinResult['canonical'],
            'amount_max' =>
                $amountMaxResult['canonical'],
        ],

        'old_input' => [
            'owner_user_id' => $isAdmin
                ? $ownerInput
                : '',

            'store_id' => $storeInput,
            'date_from' => $dateFromInput,
            'date_to' => $dateToInput,
            'amount_min' => $amountMinInput,
            'amount_max' => $amountMaxInput,
            'sort' => $sort,
        ],

        'sort' => $sort,
        'errors' => $errors,
    ];
}