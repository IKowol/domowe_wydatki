<?php

declare(strict_types=1);

require_once dirname(__DIR__)
    . '/src/Auth/auth.php';

require_once dirname(__DIR__)
    . '/src/Support/form.php';

require_once dirname(__DIR__)
    . '/src/Store/store-validation.php';

require_once dirname(__DIR__)
    . '/src/Store/StoreRepository.php';

$admin = app_require_role('ADMIN');

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

$rawStoreId = $method === 'POST'
    ? ($_POST['store_id'] ?? null)
    : ($_GET['id'] ?? null);

$storeId = filter_var(
    $rawStoreId,
    FILTER_VALIDATE_INT,
    [
        'options' => [
            'min_range' => 1,
        ],
    ]
);

if (!is_int($storeId)) {
    http_response_code(400);

    echo 'Nieprawidłowy identyfikator sklepu.';
    exit;
}

$actorUserId = (int) $admin['user_id'];

$editUrl = '/store-edit.php?id=' . $storeId;
$formName = 'store_edit_' . $storeId;

if ($method === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? null;

    if (!app_verify_csrf_token($csrfToken)) {
        app_flash(
            'error',
            'Sesja formularza wygasła. Spróbuj ponownie.'
        );

        app_redirect($editUrl);
    }

    $validation = app_validate_store_input(
        $_POST
    );

    $storeData = $validation['data'];
    $oldInput = $validation['old_input'];
    $errors = $validation['errors'];

    $activeRaw = $_POST['is_active'] ?? null;

    $oldInput['is_active'] = is_string($activeRaw)
        ? $activeRaw
        : '';

    if (
        !is_string($activeRaw)
        || !in_array($activeRaw, ['0', '1'], true)
    ) {
        $errors['is_active'] =
            'Wybierz poprawny status sklepu.';
    }

    if ($errors !== []) {
        app_store_form_state(
            $formName,
            $errors,
            $oldInput
        );

        app_redirect($editUrl);
    }

    $connection = null;
    $updatedStore = null;
    $operationError = null;

    try {
        $connection = require dirname(__DIR__)
            . '/config/database.php';

        $repository = new StoreRepository(
            $connection
        );

        $updatedStore = $repository->update(
            $storeId,
            $actorUserId,
            $storeData['name'],
            $storeData['city'],
            $storeData['street'],
            $storeData['building_number'],
            $storeData['phone'],
            $activeRaw === '1'
        );
    } catch (Throwable $exception) {
        error_log(
            'Edycja sklepu: '
            . $exception->getMessage()
        );

        $operationError =
            'Nie udało się zapisać danych sklepu.';
    } finally {
        if (is_resource($connection)) {
            sqlsrv_close($connection);
        }
    }

    if ($operationError !== null) {
        app_store_form_state(
            $formName,
            [
                'general' => $operationError,
            ],
            $oldInput
        );

        app_redirect($editUrl);
    }

    if (!is_array($updatedStore)) {
        http_response_code(404);

        echo 'Nie znaleziono sklepu albo nie masz dostępu.';
        exit;
    }

    app_flash(
        'success',
        'Dane sklepu zostały zapisane.'
    );

    app_redirect($editUrl);
}

/*
 * Obsługa GET.
 */
$connection = null;
$store = null;

try {
    $connection = require dirname(__DIR__)
        . '/config/database.php';

    $repository = new StoreRepository(
        $connection
    );

    $store = $repository->findEditableById(
        $storeId,
        $actorUserId
    );
} catch (Throwable $exception) {
    error_log(
        'Pobieranie sklepu do edycji: '
        . $exception->getMessage()
    );

    http_response_code(500);

    echo 'Nie udało się pobrać danych sklepu.';
    exit;
} finally {
    if (is_resource($connection)) {
        sqlsrv_close($connection);
    }
}

if (!is_array($store)) {
    http_response_code(404);

    echo 'Nie znaleziono sklepu albo nie masz dostępu.';
    exit;
}

$formState = app_consume_form_state(
    $formName
);

$errors = $formState['errors'];
$oldInput = $formState['old_input'];

$formValues = [
    'name' => (string) $store['Nazwa'],
    'city' => (string) ($store['Miasto'] ?? ''),
    'street' => (string) ($store['Ulica'] ?? ''),
    'building_number' => (string) (
        $store['NumerBudynku'] ?? ''
    ),
    'phone' => (string) ($store['Telefon'] ?? ''),
];

foreach ($oldInput as $field => $value) {
    if ($field !== 'is_active') {
        $formValues[$field] = $value;
    }
}

$selectedActive = $oldInput['is_active']
    ?? (
        (int) $store['CzyAktywny'] === 1
            ? '1'
            : '0'
    );

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

    <title>Edycja sklepu — Domowe wydatki</title>

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
            <?= app_e($admin['display_name']) ?>
        </span>

        <a
            href="/stores.php"
            class="button-link button-secondary"
        >
            Sklepy
        </a>
    </div>
</header>

<main class="container">
    <section class="card form-card">
        <div class="section-header">
            <div>
                <h1>Edytuj sklep</h1>

                <p class="muted">
                    ID: <?= (int) $store['SklepId'] ?>
                </p>
            </div>
        </div>

        <div class="alert alert-warning">
            Dezaktywacja nie usuwa sklepu ani historii
            powiązanych wydatków. Nieaktywny sklep nie będzie
            dostępny przy dodawaniu nowych wydatków.
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
                name="store_id"
                value="<?= $storeId ?>"
            >

            <div class="form-grid">
                <div class="form-field">
                    <label for="name">
                        Nazwa sklepu
                    </label>

                    <input
                        id="name"
                        name="name"
                        type="text"
                        maxlength="120"
                        value="<?= app_e($formValues['name']) ?>"
                        required
                        autofocus
                    >

                    <?php if (isset($errors['name'])): ?>
                        <p class="field-error">
                            <?= app_e($errors['name']) ?>
                        </p>
                    <?php endif; ?>
                </div>

                <div class="form-field">
                    <label for="city">
                        Miasto
                    </label>

                    <input
                        id="city"
                        name="city"
                        type="text"
                        maxlength="100"
                        value="<?= app_e($formValues['city']) ?>"
                    >

                    <?php if (isset($errors['city'])): ?>
                        <p class="field-error">
                            <?= app_e($errors['city']) ?>
                        </p>
                    <?php endif; ?>
                </div>

                <div class="form-field">
                    <label for="street">
                        Ulica
                    </label>

                    <input
                        id="street"
                        name="street"
                        type="text"
                        maxlength="120"
                        value="<?= app_e($formValues['street']) ?>"
                    >

                    <?php if (isset($errors['street'])): ?>
                        <p class="field-error">
                            <?= app_e($errors['street']) ?>
                        </p>
                    <?php endif; ?>
                </div>

                <div class="form-field">
                    <label for="building_number">
                        Numer budynku
                    </label>

                    <input
                        id="building_number"
                        name="building_number"
                        type="text"
                        maxlength="20"
                        value="<?= app_e(
                            $formValues['building_number']
                        ) ?>"
                    >

                    <?php if (
                        isset($errors['building_number'])
                    ): ?>
                        <p class="field-error">
                            <?= app_e(
                                $errors['building_number']
                            ) ?>
                        </p>
                    <?php endif; ?>
                </div>

                <div class="form-field">
                    <label for="phone">
                        Telefon
                    </label>

                    <input
                        id="phone"
                        name="phone"
                        type="tel"
                        maxlength="20"
                        value="<?= app_e($formValues['phone']) ?>"
                    >

                    <?php if (isset($errors['phone'])): ?>
                        <p class="field-error">
                            <?= app_e($errors['phone']) ?>
                        </p>
                    <?php endif; ?>
                </div>

                <div class="form-field">
                    <label for="is_active">
                        Status
                    </label>

                    <select
                        id="is_active"
                        name="is_active"
                        required
                    >
                        <option
                            value="1"
                            <?= $selectedActive === '1'
                                ? 'selected'
                                : ''
                            ?>
                        >
                            Aktywny
                        </option>

                        <option
                            value="0"
                            <?= $selectedActive === '0'
                                ? 'selected'
                                : ''
                            ?>
                        >
                            Nieaktywny
                        </option>
                    </select>

                    <?php if (
                        isset($errors['is_active'])
                    ): ?>
                        <p class="field-error">
                            <?= app_e(
                                $errors['is_active']
                            ) ?>
                        </p>
                    <?php endif; ?>
                </div>
            </div>

            <div class="form-actions">
                <a
                    href="/stores.php"
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