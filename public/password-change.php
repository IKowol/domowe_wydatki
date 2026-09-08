<?php

declare(strict_types=1);

require_once dirname(__DIR__)
    . '/src/Auth/auth.php';

require_once dirname(__DIR__)
    . '/src/Support/form.php';

require_once dirname(__DIR__)
    . '/src/Security/password.php';

require_once dirname(__DIR__)
    . '/src/Security/PasswordRepository.php';

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

if ($method === 'POST') {
    if (
        !app_verify_csrf_token(
            $_POST['csrf_token'] ?? null
        )
    ) {
        app_flash(
            'error',
            'Sesja formularza wygasła. '
            . 'Hasło nie zostało zmienione.'
        );

        app_redirect('/password-change.php');
    }

    $currentPassword = (string) (
        $_POST['current_password'] ?? ''
    );

    $newPassword = (string) (
        $_POST['new_password'] ?? ''
    );

    $newPasswordConfirmation = (string) (
        $_POST['new_password_confirmation'] ?? ''
    );

    $errors = [];

    if ($currentPassword === '') {
        $errors['current_password'] =
            'Podaj aktualne hasło.';
    }

    $newPasswordErrors =
        app_validate_new_password(
            $newPassword,
            $newPasswordConfirmation
        );

    $errors = array_merge(
        $errors,
        $newPasswordErrors
    );

    if ($errors !== []) {
        app_store_form_state(
            'password_change',
            $errors,
            []
        );

        app_redirect('/password-change.php');
    }

    $connection = null;
    $state = null;

    try {
        $connection = require dirname(__DIR__)
            . '/config/database.php';

        $repository = new PasswordRepository(
            $connection
        );

        $state = $repository->getOwnPasswordState(
            (int) $user['user_id']
        );

        if (!is_array($state)) {
            app_logout_user();

            app_redirect(
                '/login.php?revoked=1'
            );
        }

        $currentHash = (string)
            $state['HasloHash'];

        if (
            !password_verify(
                $currentPassword,
                $currentHash
            )
        ) {
            app_store_form_state(
                'password_change',
                [
                    'current_password' =>
                        'Aktualne hasło jest nieprawidłowe.',
                ],
                []
            );

            app_redirect('/password-change.php');
        }

        /*
         * Nowe hasło nie może być identyczne
         * z aktualnym.
         */
        if (
            password_verify(
                $newPassword,
                $currentHash
            )
        ) {
            app_store_form_state(
                'password_change',
                [
                    'new_password' =>
                        'Nowe hasło musi różnić się '
                        . 'od aktualnego.',
                ],
                []
            );

            app_redirect('/password-change.php');
        }

        $newHash = password_hash(
            $newPassword,
            PASSWORD_DEFAULT
        );

        if (!is_string($newHash)) {
            throw new RuntimeException(
                'password_hash() nie zwróciło hasha.'
            );
        }

        $newSessionVersion =
            $repository->changeOwnPassword(
                (int) $user['user_id'],
                $currentHash,
                $newHash
            );

        if ($newSessionVersion === null) {
            /*
             * Hash lub stan konta zmienił się pomiędzy
             * SELECT i UPDATE.
             */
            app_logout_user();

            app_redirect(
                '/login.php?revoked=1'
            );
        }

        /*
         * Aktualna sesja przechodzi na nową wersję.
         * Wszystkie pozostałe sesje mają starą wersję
         * i zostaną unieważnione.
         */
        $_SESSION['auth']['session_version'] =
            $newSessionVersion;

        $_SESSION['auth']['last_activity'] =
            time();

        session_regenerate_id(true);

        app_rotate_csrf_token();

        $currentPassword = '';
        $newPassword = '';
        $newPasswordConfirmation = '';
        $currentHash = '';
        $newHash = '';
    } catch (Throwable $exception) {
        error_log(
            'Zmiana własnego hasła: '
            . $exception->getMessage()
        );

        app_store_form_state(
            'password_change',
            [
                'general' =>
                    'Nie udało się zmienić hasła. '
                    . 'Spróbuj ponownie.',
            ],
            []
        );

        app_redirect('/password-change.php');
    } finally {
        if (is_resource($connection)) {
            sqlsrv_close($connection);
        }
    }

    app_flash(
        'success',
        'Hasło zostało zmienione. '
        . 'Pozostałe aktywne sesje zostały unieważnione.'
    );

    app_redirect('/dashboard.php');
}

$formState = app_consume_form_state(
    'password_change'
);

$errors = $formState['errors'];

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
        Zmień hasło — Domowe wydatki
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
                <h1>Zmień hasło</h1>

                <p class="muted">
                    Zmiana hasła unieważni pozostałe
                    aktywne sesje Twojego konta.
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
            action="/password-change.php"
            class="form"
            novalidate
        >
            <input
                type="hidden"
                name="csrf_token"
                value="<?= app_e($csrfToken) ?>"
            >

            <div class="form-field">
                <label for="current_password">
                    Aktualne hasło
                </label>

                <input
                    id="current_password"
                    name="current_password"
                    type="password"
                    autocomplete="current-password"
                    maxlength="72"
                    required
                    autofocus
                >

                <?php if (
                    isset($errors['current_password'])
                ): ?>
                    <p class="field-error">
                        <?= app_e(
                            $errors['current_password']
                        ) ?>
                    </p>
                <?php endif; ?>
            </div>

            <div class="form-field">
                <label for="new_password">
                    Nowe hasło
                </label>

                <input
                    id="new_password"
                    name="new_password"
                    type="password"
                    autocomplete="new-password"
                    minlength="12"
                    maxlength="72"
                    required
                >

                <p class="field-help">
                    Minimum 12 znaków.
                </p>

                <?php if (
                    isset($errors['password'])
                ): ?>
                    <p class="field-error">
                        <?= app_e(
                            $errors['password']
                        ) ?>
                    </p>
                <?php endif; ?>

                <?php if (
                    isset($errors['new_password'])
                ): ?>
                    <p class="field-error">
                        <?= app_e(
                            $errors['new_password']
                        ) ?>
                    </p>
                <?php endif; ?>
            </div>

            <div class="form-field">
                <label for="new_password_confirmation">
                    Powtórz nowe hasło
                </label>

                <input
                    id="new_password_confirmation"
                    name="new_password_confirmation"
                    type="password"
                    autocomplete="new-password"
                    minlength="12"
                    maxlength="72"
                    required
                >

                <?php if (
                    isset(
                        $errors[
                            'password_confirmation'
                        ]
                    )
                ): ?>
                    <p class="field-error">
                        <?= app_e(
                            $errors[
                                'password_confirmation'
                            ]
                        ) ?>
                    </p>
                <?php endif; ?>
            </div>

            <div class="form-actions">
                <a
                    href="/dashboard.php"
                    class="button-link button-secondary"
                >
                    Anuluj
                </a>

                <button type="submit">
                    Zmień hasło
                </button>
            </div>
        </form>
    </section>
</main>
</body>
</html>