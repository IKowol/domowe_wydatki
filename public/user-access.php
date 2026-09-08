<?php

declare(strict_types=1);

require_once dirname(__DIR__)
    . '/src/Auth/auth.php';

require_once dirname(__DIR__)
    . '/src/Support/form.php';

require_once dirname(__DIR__)
    . '/src/User/UserRepository.php';

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

$targetUserId = filter_var(
    $_GET['id'] ?? null,
    FILTER_VALIDATE_INT,
    [
        'options' => [
            'min_range' => 1,
        ],
    ]
);

if (!is_int($targetUserId)) {
    http_response_code(400);

    echo 'Nieprawidłowy identyfikator użytkownika.';
    exit;
}

$actorUserId = (int) $admin['user_id'];

if ($targetUserId === $actorUserId) {
    http_response_code(403);

    echo 'Nie możesz zmieniać roli ani statusu własnego konta.';
    exit;
}

$accessUrl = '/user-access.php?id='
    . $targetUserId;

$formName = 'user_access_'
    . $targetUserId;

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

        app_redirect($accessUrl);
    }

    $roleId = filter_var(
        $_POST['role_id'] ?? null,
        FILTER_VALIDATE_INT
    );

    $activeRaw = $_POST['is_active'] ?? null;

    $oldInput = [
        'role_id' => is_int($roleId)
            ? (string) $roleId
            : '',

        'is_active' => is_string($activeRaw)
            ? $activeRaw
            : '',
    ];

    $errors = [];

    if (
        !is_int($roleId)
        || !in_array($roleId, [1, 2], true)
    ) {
        $errors['role_id'] =
            'Wybierz poprawną rolę.';
    }

    if (
        !is_string($activeRaw)
        || !in_array(
            $activeRaw,
            ['0', '1'],
            true
        )
    ) {
        $errors['is_active'] =
            'Wybierz poprawny status konta.';
    }

    if ($errors !== []) {
        app_store_form_state(
            $formName,
            $errors,
            $oldInput
        );

        app_redirect($accessUrl);
    }

    $connection = null;
    $operationError = null;

    try {
        $connection = require dirname(__DIR__)
            . '/config/database.php';

        $repository = new UserRepository(
            $connection
        );

        $repository->updateAccessSettings(
            $targetUserId,
            $actorUserId,
            $roleId,
            $activeRaw === '1'
        );
    } catch (
        UserAccessChangeException $exception
    ) {
        $operationError = match (
            $exception->reason()
        ) {
            UserAccessChangeException::SELF_CHANGE =>
                'Nie możesz zmieniać dostępu własnego konta.',

            UserAccessChangeException::LAST_ACTIVE_ADMIN =>
                'Nie można odebrać dostępu ostatniemu '
                . 'aktywnemu administratorowi.',

            default =>
                'Nie znaleziono użytkownika albo nie masz '
                . 'uprawnień do tej operacji.',
        };
    } catch (Throwable $exception) {
        error_log(
            'Zmiana dostępu użytkownika: '
            . $exception->getMessage()
        );

        $operationError =
            'Nie udało się zmienić ustawień konta.';
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

        app_redirect($accessUrl);
    }

    app_flash(
        'success',
        'Rola i status użytkownika zostały zapisane.'
    );

    app_redirect($accessUrl);
}

/*
 * Obsługa GET.
 */
$connection = null;
$targetUser = null;

try {
    $connection = require dirname(__DIR__)
        . '/config/database.php';

    $repository = new UserRepository(
        $connection
    );

    $targetUser = $repository->findAccessSettings(
        $targetUserId,
        $actorUserId
    );
} catch (Throwable $exception) {
    error_log(
        'Pobieranie ustawień dostępu: '
        . $exception->getMessage()
    );

    http_response_code(500);

    echo 'Nie udało się pobrać ustawień użytkownika.';
    exit;
} finally {
    if (is_resource($connection)) {
        sqlsrv_close($connection);
    }
}

if (!is_array($targetUser)) {
    http_response_code(404);

    echo 'Nie znaleziono użytkownika albo nie masz dostępu.';
    exit;
}

$formState = app_consume_form_state(
    $formName
);

$errors = $formState['errors'];
$oldInput = $formState['old_input'];

$selectedRoleId = isset($oldInput['role_id'])
    ? (int) $oldInput['role_id']
    : (int) $targetUser['RolaId'];

$selectedActive = $oldInput['is_active']
    ?? (
        (int) $targetUser['CzyAktywny'] === 1
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

    <title>Dostęp użytkownika — Domowe wydatki</title>

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
            href="/users.php"
            class="button-link button-secondary"
        >
            Użytkownicy
        </a>
    </div>
</header>

<main class="container">
    <section class="card form-card">
        <div class="section-header">
            <div>
                <h1>Rola i status konta</h1>

                <p class="muted">
                    <?= app_e(
                        $targetUser['Imie']
                        . ' '
                        . $targetUser['Nazwisko']
                    ) ?>
                    ·
                    <?= app_e($targetUser['Login']) ?>
                    · ID:
                    <?= (int) $targetUser['UzytkownikId'] ?>
                </p>
            </div>
        </div>

        <div class="alert alert-warning">
            Administrator nie może zmieniać własnego dostępu.
            Aplikacja chroni również ostatniego aktywnego
            administratora.
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
            action="<?= app_e($accessUrl) ?>"
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
                    <label for="role_id">
                        Rola
                    </label>

                    <select
                        id="role_id"
                        name="role_id"
                        required
                    >
                        <option
                            value="1"
                            <?= $selectedRoleId === 1
                                ? 'selected'
                                : ''
                            ?>
                        >
                            ADMIN
                        </option>

                        <option
                            value="2"
                            <?= $selectedRoleId === 2
                                ? 'selected'
                                : ''
                            ?>
                        >
                            USER
                        </option>
                    </select>

                    <?php if (
                        isset($errors['role_id'])
                    ): ?>
                        <p class="field-error">
                            <?= app_e(
                                $errors['role_id']
                            ) ?>
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
                    href="/users.php"
                    class="button-link button-secondary"
                >
                    Anuluj
                </a>

                <button type="submit">
                    Zapisz dostęp
                </button>
            </div>
        </form>
    </section>
</main>
</body>
</html>