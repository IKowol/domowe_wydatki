<?php

declare(strict_types=1);

require_once dirname(__DIR__)
    . '/src/Auth/auth.php';

require_once dirname(__DIR__)
    . '/src/Support/form.php';

require_once dirname(__DIR__)
    . '/src/Store/StoreRepository.php';

require_once dirname(__DIR__)
    . '/src/Store/store-validation.php';    

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

        app_redirect('/store-create.php');
    }

    $validation = app_validate_store_input(
        $_POST
    );

    $storeData = $validation['data'];
    $oldInput = $validation['old_input'];
    $errors = $validation['errors'];

    if ($errors !== []) {
        app_store_form_state(
            'store_create',
            $errors,
            $oldInput
        );

        app_redirect('/store-create.php');
    }

    $connection = null;
    $createdStoreId = null;
    $operationError = null;

    try {
        $connection = require dirname(__DIR__)
            . '/config/database.php';

        $repository = new StoreRepository(
            $connection
        );

        $createdStoreId = $repository->create(
            $storeData['name'],
            $storeData['city'],
            $storeData['street'],
            $storeData['building_number'],
            $storeData['phone']
        );
    } catch (Throwable $exception) {
        error_log(
            'Tworzenie sklepu: '
            . $exception->getMessage()
        );

        $operationError =
            'Nie udało się utworzyć sklepu. '
            . 'Spróbuj ponownie.';
    } finally {
        if (is_resource($connection)) {
            sqlsrv_close($connection);
        }
    }

    if ($operationError !== null) {
        app_store_form_state(
            'store_create',
            [
                'general' => $operationError,
            ],
            $oldInput
        );

        app_redirect('/store-create.php');
    }

    app_flash(
        'success',
        'Sklep został utworzony. ID: '
        . (int) $createdStoreId
        . '.'
    );

    app_redirect('/stores.php');
}

$formState = app_consume_form_state(
    'store_create'
);

$errors = $formState['errors'];
$oldInput = $formState['old_input'];

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

    <title>Dodaj sklep — Domowe wydatki</title>

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
                <h1>Dodaj sklep</h1>

                <p class="muted">
                    Nowy sklep zostanie utworzony
                    jako aktywny.
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
            action="/store-create.php"
            class="form"
            novalidate
        >
            <input
                type="hidden"
                name="csrf_token"
                value="<?= app_e($csrfToken) ?>"
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
                        value="<?= app_e(
                            $oldInput['name'] ?? ''
                        ) ?>"
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
                        value="<?= app_e(
                            $oldInput['city'] ?? ''
                        ) ?>"
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
                        value="<?= app_e(
                            $oldInput['street'] ?? ''
                        ) ?>"
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
                            $oldInput['building_number']
                            ?? ''
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
                        autocomplete="tel"
                        value="<?= app_e(
                            $oldInput['phone'] ?? ''
                        ) ?>"
                    >

                    <?php if (isset($errors['phone'])): ?>
                        <p class="field-error">
                            <?= app_e($errors['phone']) ?>
                        </p>
                    <?php endif; ?>
                </div>

                <div class="form-field">
                    <label>Status</label>

                    <input
                        type="text"
                        value="Aktywny"
                        readonly
                    >

                    <p class="field-help">
                        Status będzie można zmienić
                        podczas edycji sklepu.
                    </p>
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
                    Utwórz sklep
                </button>
            </div>
        </form>
    </section>
</main>
</body>
</html>