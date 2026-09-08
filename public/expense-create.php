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
$isAdmin = (string) $user['role'] === 'ADMIN';

if ($method === 'POST') {
    if (
        !app_verify_csrf_token(
            $_POST['csrf_token'] ?? null
        )
    ) {
        app_flash(
            'error',
            'Sesja formularza wygasła. Spróbuj ponownie.'
        );

        app_redirect('/expense-create.php');
    }

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
            'expense_create',
            $errors,
            $oldInput
        );

        app_redirect('/expense-create.php');
    }

    $connection = null;
    $createdExpense = null;
    $operationErrors = [];

    try {
        $connection = require dirname(__DIR__)
            . '/config/database.php';

        $repository = new ExpenseRepository(
            $connection
        );

        $createdExpense = $repository->create(
            $actorUserId,
            (int) $expenseData['owner_user_id'],
            (int) $expenseData['store_id'],
            $expenseData['expense_date'],
            $expenseData['amount'],
            $expenseData['description']
        );
    } catch (
        ExpenseCreationException $exception
    ) {
        $operationErrors = match (
            $exception->reason()
        ) {
            ExpenseCreationException::ACTOR_INACTIVE => [
                'general' =>
                    'Twoje konto nie jest już aktywne.',
            ],

            ExpenseCreationException::FORBIDDEN_OWNER => [
                'owner_user_id' =>
                    'Nie możesz dodać wydatku dla tego użytkownika.',
            ],

            ExpenseCreationException::OWNER_INACTIVE => [
                'owner_user_id' =>
                    'Wybrany użytkownik nie istnieje '
                    . 'albo jest nieaktywny.',
            ],

            ExpenseCreationException::STORE_INACTIVE => [
                'store_id' =>
                    'Wybrany sklep nie istnieje '
                    . 'albo jest nieaktywny.',
            ],

            ExpenseCreationException::INVALID_AMOUNT => [
                'amount' =>
                    'Kwota musi być większa od zera.',
            ],

            ExpenseCreationException::DESCRIPTION_TOO_LONG => [
                'description' =>
                    'Opis może mieć maksymalnie 500 znaków.',
            ],

            default => [
                'general' =>
                    'Nie udało się utworzyć wydatku.',
            ],
        };
    } catch (Throwable $exception) {
        error_log(
            'Tworzenie wydatku: '
            . $exception->getMessage()
        );

        $operationErrors = [
            'general' =>
                'Nie udało się utworzyć wydatku. '
                . 'Spróbuj ponownie.',
        ];
    } finally {
        if (is_resource($connection)) {
            sqlsrv_close($connection);
        }
    }

    if ($operationErrors !== []) {
        app_store_form_state(
            'expense_create',
            $operationErrors,
            $oldInput
        );

        app_redirect('/expense-create.php');
    }

    app_flash(
        'success',
        'Wydatek został dodany. ID: '
        . (int) $createdExpense['WydatekId']
        . '.'
    );

    app_redirect('/expenses.php');
}

/*
 * Obsługa GET i pobranie danych formularza.
 */
$connection = null;
$stores = [];
$activeUsers = [];

try {
    $connection = require dirname(__DIR__)
        . '/config/database.php';

    $repository = new ExpenseRepository(
        $connection
    );

    $stores = $repository->getActiveStores();

    if ($isAdmin) {
        $activeUsers = $repository->getActiveUsers();
    }
} catch (Throwable $exception) {
    error_log(
        'Pobieranie formularza wydatku: '
        . $exception->getMessage()
    );

    http_response_code(500);

    echo 'Nie udało się przygotować formularza wydatku.';
    exit;
} finally {
    if (is_resource($connection)) {
        sqlsrv_close($connection);
    }
}

$formState = app_consume_form_state(
    'expense_create'
);

$errors = $formState['errors'];
$oldInput = $formState['old_input'];

$selectedOwnerId = isset(
    $oldInput['owner_user_id']
)
    ? (int) $oldInput['owner_user_id']
    : $actorUserId;

$selectedStoreId = isset(
    $oldInput['store_id']
)
    ? (int) $oldInput['store_id']
    : 0;

$expenseDate = $oldInput['expense_date']
    ?? date('Y-m-d');

$amount = $oldInput['amount'] ?? '';
$description = $oldInput['description'] ?? '';

$successMessages = app_consume_flash(
    'success'
);

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

    <title>Dodaj wydatek — Domowe wydatki</title>

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
            href="/dashboard.php"
            class="button-link button-secondary"
        >
            Panel
        </a>
    </div>
</header>

<main class="container">
    <section class="card form-card">
        <div class="section-header">
            <div>
                <h1>Dodaj wydatek</h1>

                <p class="muted">
                    Zapis zostanie wykonany atomowo
                    przez procedurę SQL Servera.
                </p>
            </div>
        </div>

        <?php foreach ($successMessages as $message): ?>
            <div class="alert alert-success">
                <?= app_e($message) ?>
            </div>
        <?php endforeach; ?>

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

        <?php if ($stores === []): ?>
            <div class="alert alert-warning">
                Brak aktywnych sklepów. Przed dodaniem wydatku
                administrator musi utworzyć lub aktywować sklep.
            </div>
        <?php endif; ?>

        <?php if ($isAdmin && $activeUsers === []): ?>
            <div class="alert alert-warning">
                Brak aktywnych użytkowników.
            </div>
        <?php endif; ?>

        <?php if (
            $stores !== []
            && (!$isAdmin || $activeUsers !== [])
        ): ?>
            <form
                method="post"
                action="/expense-create.php"
                class="form"
                novalidate
            >
                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= app_e($csrfToken) ?>"
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
                                        $activeUser['UzytkownikId'];

                                    $activeUserName = trim(
                                        (string) $activeUser['Imie']
                                        . ' '
                                        . (string) $activeUser['Nazwisko']
                                    );
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
                                            $activeUserName
                                            . ' — '
                                            . $activeUser['Login']
                                            . ' ('
                                            . $activeUser['Rola']
                                            . ')'
                                        ) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>

                            <?php if (
                                isset($errors['owner_user_id'])
                            ): ?>
                                <p class="field-error">
                                    <?= app_e(
                                        $errors['owner_user_id']
                                    ) ?>
                                </p>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div class="form-field">
                            <label>Użytkownik</label>

                            <input
                                type="text"
                                value="<?= app_e(
                                    $user['display_name']
                                ) ?>"
                                readonly
                            >

                            <p class="field-help">
                                Zwykły użytkownik może dodawać
                                wyłącznie własne wydatki.
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
                            <option value="">
                                Wybierz sklep
                            </option>

                            <?php foreach ($stores as $store): ?>
                                <?php
                                $storeId = (int)
                                    $store['SklepId'];

                                $storeLocationParts = array_filter(
                                    [
                                        trim(
                                            (string) (
                                                $store['Miasto']
                                                ?? ''
                                            )
                                        ),

                                        trim(
                                            (string) (
                                                $store['Ulica']
                                                ?? ''
                                            )
                                            . ' '
                                            . (string) (
                                                $store[
                                                    'NumerBudynku'
                                                ] ?? ''
                                            )
                                        ),
                                    ],
                                    static fn (
                                        string $value
                                    ): bool => $value !== ''
                                );

                                $storeLabel = (string)
                                    $store['Nazwa'];

                                if ($storeLocationParts !== []) {
                                    $storeLabel .= ' — '
                                        . implode(
                                            ', ',
                                            $storeLocationParts
                                        );
                                }
                                ?>

                                <option
                                    value="<?= $storeId ?>"
                                    <?= $selectedStoreId === $storeId
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
                            value="<?= app_e($expenseDate) ?>"
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
                            placeholder="0,00"
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

                        <p class="field-help">
                            Pole opcjonalne, maksymalnie 500 znaków.
                        </p>

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
                        href="/dashboard.php"
                        class="button-link button-secondary"
                    >
                        Anuluj
                    </a>

                    <button type="submit">
                        Dodaj wydatek
                    </button>
                </div>
            </form>
        <?php endif; ?>
    </section>
</main>
</body>
</html>