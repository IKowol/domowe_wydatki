<?php

declare(strict_types=1);

require_once dirname(__DIR__)
    . '/src/Auth/auth.php';

require_once dirname(__DIR__)
    . '/src/Support/form.php';

require_once dirname(__DIR__)
    . '/src/Expense/expense-validation.php';

require_once dirname(__DIR__)
    . '/src/Expense/ExpenseRepository.php';

$user = app_require_auth();

header(
    'Cache-Control: no-store, no-cache, '
    . 'must-revalidate, max-age=0'
);

header('Pragma: no-cache');

$method = strtoupper(
    (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')
);

if (!in_array($method, ['GET', 'POST'], true)) {
    header('Allow: GET, POST');

    http_response_code(405);

    echo 'Niedozwolona metoda żądania.';
    exit;
}

$actorUserId = (int) $user['user_id'];

$isAdmin = (
    (string) $user['role']
    === 'ADMIN'
);

$rawExpenseId = $method === 'POST'
    ? ($_POST['expense_id'] ?? null)
    : ($_GET['id'] ?? null);

$expenseId = filter_var(
    $rawExpenseId,
    FILTER_VALIDATE_INT,
    [
        'options' => [
            'min_range' => 1,
        ],
    ]
);

if (!is_int($expenseId)) {
    http_response_code(400);

    echo 'Nieprawidłowy identyfikator wydatku.';
    exit;
}

$editUrl =
    '/expense-edit.php?id=' . $expenseId;

$formName =
    'expense_edit_' . $expenseId;

if ($method === 'POST') {
    if (
        !app_verify_csrf_token(
            $_POST['csrf_token'] ?? null
        )
    ) {
        app_flash(
            'error',
            'Sesja formularza wygasła. '
            . 'Spróbuj ponownie.'
        );

        app_redirect($editUrl);
    }

    /*
     * Ta sama walidacja, której używamy
     * podczas tworzenia wydatku.
     *
     * Dla USER funkcja ignoruje owner_user_id
     * przesłany przez przeglądarkę.
     */
    $validation = app_validate_expense_input(
        $_POST,
        $isAdmin,
        $actorUserId
    );

    $expenseData = $validation['data'];
    $oldInput = $validation['old_input'];
    $errors = $validation['errors'];

    if ($errors !== []) {
        app_store_form_state(
            $formName,
            $errors,
            $oldInput
        );

        app_redirect($editUrl);
    }

    $connection = null;
    $updatedExpense = null;
    $operationErrors = [];

    try {
        $connection = require dirname(__DIR__)
            . '/config/database.php';

        $repository = new ExpenseRepository(
            $connection
        );

        $updatedExpense = $repository->update(
            $actorUserId,
            $expenseId,
            (int) $expenseData[
                'owner_user_id'
            ],
            (int) $expenseData[
                'store_id'
            ],
            $expenseData[
                'expense_date'
            ],
            $expenseData[
                'amount'
            ],
            $expenseData[
                'description'
            ]
        );
    } catch (
        ExpenseUpdateException $exception
    ) {
        switch ($exception->reason()) {
            case ExpenseUpdateException::NOT_FOUND:
                http_response_code(404);

                echo 'Nie znaleziono wydatku.';
                exit;

            case ExpenseUpdateException::FORBIDDEN:
                http_response_code(403);

                echo 'Nie masz uprawnień do edycji '
                    . 'tego wydatku.';
                exit;

            case ExpenseUpdateException::ACTOR_INACTIVE:
                app_logout_user();

                app_redirect(
                    '/login.php?revoked=1'
                );

            case ExpenseUpdateException::OWNER_INACTIVE:
                $operationErrors[
                    'owner_user_id'
                ] =
                    'Nowy właściciel nie istnieje '
                    . 'albo jest nieaktywny.';
                break;

            case ExpenseUpdateException::STORE_INACTIVE:
                $operationErrors[
                    'store_id'
                ] =
                    'Nowy sklep nie istnieje '
                    . 'albo jest nieaktywny.';
                break;

            case ExpenseUpdateException::INVALID_DATE:
                $operationErrors[
                    'expense_date'
                ] =
                    'Podaj poprawną datę wydatku.';
                break;

            case ExpenseUpdateException::INVALID_AMOUNT:
                $operationErrors[
                    'amount'
                ] =
                    'Kwota musi być większa od zera.';
                break;

            case ExpenseUpdateException::DESCRIPTION_TOO_LONG:
                $operationErrors[
                    'description'
                ] =
                    'Opis może mieć maksymalnie '
                    . '500 znaków.';
                break;

            default:
                $operationErrors['general'] =
                    'Nie udało się zaktualizować wydatku.';
                break;
        }
    } catch (Throwable $exception) {
        error_log(
            'Edycja wydatku: '
            . $exception->getMessage()
        );

        $operationErrors = [
            'general' =>
                'Nie udało się zapisać zmian. '
                . 'Spróbuj ponownie.',
        ];
    } finally {
        if (is_resource($connection)) {
            sqlsrv_close($connection);
        }
    }

    if ($operationErrors !== []) {
        app_store_form_state(
            $formName,
            $operationErrors,
            $oldInput
        );

        app_redirect($editUrl);
    }

    if (!is_array($updatedExpense)) {
        http_response_code(500);

        echo 'Nie udało się potwierdzić aktualizacji.';
        exit;
    }

    app_flash(
        'success',
        'Wydatek został zaktualizowany.'
    );

    app_redirect('/expenses.php');
}

/*
 * GET — pobranie bieżącego wydatku.
 */
$connection = null;

$expense = null;
$stores = [];
$activeUsers = [];

try {
    $connection = require dirname(__DIR__)
        . '/config/database.php';

    $repository = new ExpenseRepository(
        $connection
    );

    $expense = $repository->findEditableById(
        $expenseId,
        $actorUserId
    );

    if (!is_array($expense)) {
        http_response_code(404);

        echo 'Nie znaleziono wydatku albo '
            . 'nie masz do niego dostępu.';
        exit;
    }

    /*
     * Normalnie pobieramy tylko aktywne sklepy.
     */
    $stores = $repository->getActiveStores();

    /*
     * Jeżeli obecny sklep jest już nieaktywny,
     * dokładamy go do listy.
     *
     * Dzięki temu można edytować np. opis wydatku
     * bez konieczności zmiany historycznego sklepu.
     */
    $currentStoreId = (int)
        $expense['SklepId'];

    $currentStoreFound = false;

    foreach ($stores as $store) {
        if (
            (int) $store['SklepId']
            === $currentStoreId
        ) {
            $currentStoreFound = true;
            break;
        }
    }

    if (!$currentStoreFound) {
        array_unshift(
            $stores,
            [
                'SklepId' =>
                    $currentStoreId,

                'Nazwa' =>
                    $expense['SklepNazwa'],

                'Miasto' =>
                    $expense['SklepMiasto'],

                'Ulica' =>
                    $expense['SklepUlica'],

                'NumerBudynku' =>
                    $expense['SklepNumerBudynku'],

                'CzyAktywny' => 0,
            ]
        );
    }

    if ($isAdmin) {
        $activeUsers =
            $repository->getActiveUsers();

        /*
         * Historyczny właściciel może być
         * obecnie nieaktywny.
         */
        $currentOwnerId = (int)
            $expense['UzytkownikId'];

        $currentOwnerFound = false;

        foreach ($activeUsers as $activeUser) {
            if (
                (int) $activeUser['UzytkownikId']
                === $currentOwnerId
            ) {
                $currentOwnerFound = true;
                break;
            }
        }

        if (!$currentOwnerFound) {
            array_unshift(
                $activeUsers,
                [
                    'UzytkownikId' =>
                        $currentOwnerId,

                    'Login' =>
                        $expense['UzytkownikLogin'],

                    'Imie' =>
                        $expense['UzytkownikImie'],

                    'Nazwisko' =>
                        $expense['UzytkownikNazwisko'],

                    'Rola' =>
                        $expense['UzytkownikRola'],

                    'CzyAktywny' => 0,
                ]
            );
        }
    }
} catch (Throwable $exception) {
    error_log(
        'Pobieranie formularza edycji wydatku: '
        . $exception->getMessage()
    );

    http_response_code(500);

    echo 'Nie udało się przygotować '
        . 'formularza edycji wydatku.';
    exit;
} finally {
    if (is_resource($connection)) {
        sqlsrv_close($connection);
    }
}

$formState = app_consume_form_state(
    $formName
);

$errors = $formState['errors'];
$oldInput = $formState['old_input'];

$selectedOwnerId = isset(
    $oldInput['owner_user_id']
)
    ? (int) $oldInput['owner_user_id']
    : (int) $expense['UzytkownikId'];

$selectedStoreId = isset(
    $oldInput['store_id']
)
    ? (int) $oldInput['store_id']
    : (int) $expense['SklepId'];

$expenseDate =
    $oldInput['expense_date']
    ?? (string) $expense['DataWydatkuTekst'];

$amount =
    $oldInput['amount']
    ?? (string) $expense['KwotaTekst'];

$description =
    $oldInput['description']
    ?? (string) ($expense['Opis'] ?? '');

$pageErrors = app_consume_flash(
    'error'
);

$csrfToken = app_csrf_token();

?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1"
    >

    <title>
        Edytuj wydatek — Domowe wydatki
    </title>

    <link
        rel="stylesheet"
        href="/assets/css/app.css"
    >
</head>
<body>
<header class="topbar">
    <a
        href="/dashboard.php"
        class="brand-link"
    >
        Domowe wydatki
    </a>

    <div class="topbar-actions">
        <span>
            <?= app_e($user['display_name']) ?>
        </span>

        <a
            href="/expenses.php"
            class="button-link button-secondary"
        >
            Wydatki
        </a>
    </div>
</header>

<main class="container">
    <section class="card form-card">
        <div class="section-header">
            <div>
                <h1>Edytuj wydatek</h1>

                <p class="muted">
                    ID: <?= $expenseId ?>
                </p>
            </div>
        </div>

        <?php foreach ($pageErrors as $message): ?>
            <div class="alert alert-error">
                <?= app_e($message) ?>
            </div>
        <?php endforeach; ?>

        <?php if (isset($errors['general'])): ?>
            <div class="alert alert-error">
                <?= app_e($errors['general']) ?>
            </div>
        <?php endif; ?>

        <form
            method="post"
            action="<?= app_e($editUrl) ?>"
            class="form"
            novalidate
        >
            <input
                type="hidden"
                name="csrf_token"
                value="<?= app_e($csrfToken) ?>"
            >

            <input
                type="hidden"
                name="expense_id"
                value="<?= $expenseId ?>"
            >

            <div class="form-grid">
                <?php if ($isAdmin): ?>
                    <div class="form-field">
                        <label for="owner_user_id">
                            Użytkownik
                        </label>

                        <select
                            id="owner_user_id"
                            name="owner_user_id"
                            required
                        >
                            <?php foreach (
                                $activeUsers as $activeUser
                            ): ?>
                                <?php
                                $activeUserId = (int)
                                    $activeUser[
                                        'UzytkownikId'
                                    ];

                                $activeUserLabel = trim(
                                    (string) $activeUser['Imie']
                                    . ' '
                                    . (string) $activeUser[
                                        'Nazwisko'
                                    ]
                                );

                                $activeUserLabel .= ' — '
                                    . $activeUser['Login'];

                                if (
                                    isset(
                                        $activeUser[
                                            'CzyAktywny'
                                        ]
                                    )
                                    && (int) $activeUser[
                                        'CzyAktywny'
                                    ] !== 1
                                ) {
                                    $activeUserLabel .=
                                        ' [nieaktywny — obecny]';
                                }
                                ?>

                                <option
                                    value="<?= $activeUserId ?>"
                                    <?= $selectedOwnerId
                                        === $activeUserId
                                        ? 'selected'
                                        : ''
                                    ?>
                                >
                                    <?= app_e(
                                        $activeUserLabel
                                    ) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <?php if (
                            isset(
                                $errors[
                                    'owner_user_id'
                                ]
                            )
                        ): ?>
                            <p class="field-error">
                                <?= app_e(
                                    $errors[
                                        'owner_user_id'
                                    ]
                                ) ?>
                            </p>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="form-field">
                        <label>
                            Użytkownik
                        </label>

                        <input
                            type="text"
                            value="<?= app_e(
                                $user['display_name']
                            ) ?>"
                            readonly
                        >

                        <p class="field-help">
                            Nie możesz zmienić właściciela
                            wydatku.
                        </p>
                    </div>
                <?php endif; ?>

                <div class="form-field">
                    <label for="store_id">
                        Sklep
                    </label>

                    <select
                        id="store_id"
                        name="store_id"
                        required
                    >
                        <?php foreach ($stores as $store): ?>
                            <?php
                            $storeId = (int)
                                $store['SklepId'];

                            $storeLabel = (string)
                                $store['Nazwa'];

                            $locationParts = [];

                            $city = trim(
                                (string) (
                                    $store['Miasto'] ?? ''
                                )
                            );

                            $street = trim(
                                (string) (
                                    $store['Ulica'] ?? ''
                                )
                            );

                            $buildingNumber = trim(
                                (string) (
                                    $store[
                                        'NumerBudynku'
                                    ] ?? ''
                                )
                            );

                            if ($city !== '') {
                                $locationParts[] = $city;
                            }

                            $address = trim(
                                $street
                                . ' '
                                . $buildingNumber
                            );

                            if ($address !== '') {
                                $locationParts[] = $address;
                            }

                            if ($locationParts !== []) {
                                $storeLabel .= ' — '
                                    . implode(
                                        ', ',
                                        $locationParts
                                    );
                            }

                            if (
                                isset($store['CzyAktywny'])
                                && (int) $store[
                                    'CzyAktywny'
                                ] !== 1
                            ) {
                                $storeLabel .=
                                    ' [nieaktywny — obecny]';
                            }
                            ?>

                            <option
                                value="<?= $storeId ?>"
                                <?= $selectedStoreId
                                    === $storeId
                                    ? 'selected'
                                    : ''
                                ?>
                            >
                                <?= app_e($storeLabel) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <?php if (
                        isset($errors['store_id'])
                    ): ?>
                        <p class="field-error">
                            <?= app_e(
                                $errors['store_id']
                            ) ?>
                        </p>
                    <?php endif; ?>
                </div>

                <div class="form-field">
                    <label for="expense_date">
                        Data wydatku
                    </label>

                    <input
                        id="expense_date"
                        name="expense_date"
                        type="date"
                        value="<?= app_e(
                            $expenseDate
                        ) ?>"
                        required
                    >

                    <?php if (
                        isset($errors['expense_date'])
                    ): ?>
                        <p class="field-error">
                            <?= app_e(
                                $errors['expense_date']
                            ) ?>
                        </p>
                    <?php endif; ?>
                </div>

                <div class="form-field">
                    <label for="amount">
                        Kwota
                    </label>

                    <input
                        id="amount"
                        name="amount"
                        type="text"
                        inputmode="decimal"
                        maxlength="20"
                        value="<?= app_e($amount) ?>"
                        required
                    >

                    <p class="field-help">
                        Możesz użyć przecinka albo kropki.
                    </p>

                    <?php if (
                        isset($errors['amount'])
                    ): ?>
                        <p class="field-error">
                            <?= app_e(
                                $errors['amount']
                            ) ?>
                        </p>
                    <?php endif; ?>
                </div>

                <div class="form-field form-field-full">
                    <label for="description">
                        Opis
                    </label>

                    <textarea
                        id="description"
                        name="description"
                        maxlength="500"
                        rows="5"
                    ><?= app_e($description) ?></textarea>

                    <?php if (
                        isset($errors['description'])
                    ): ?>
                        <p class="field-error">
                            <?= app_e(
                                $errors['description']
                            ) ?>
                        </p>
                    <?php endif; ?>
                </div>
            </div>

            <div class="form-actions">
                <a
                    href="/expenses.php"
                    class="button-link button-secondary"
                >
                    Anuluj
                </a>

                <button type="submit">
                    Zapisz zmiany
                </button>
            </div>
        </form>
    </section>
</main>
</body>
</html>