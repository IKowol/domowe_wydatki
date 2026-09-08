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

if ((string) $user['role'] !== 'ADMIN') {
    http_response_code(403);

    echo 'Brak uprawnień.';
    exit;
}

$actorUserId = (int) $user['user_id'];

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
            . 'Hasło nie zostało zresetowane.'
        );

        app_redirect('/password-reset.php');
    }

    $rawTargetUserId =
        $_POST['target_user_id'] ?? null;

    $targetUserId = filter_var(
        $rawTargetUserId,
        FILTER_VALIDATE_INT,
        [
            'options' => [
                'min_range' => 1,
            ],
        ]
    );

    $newPassword = (string) (
        $_POST['new_password'] ?? ''
    );

    $newPasswordConfirmation = (string) (
        $_POST['new_password_confirmation'] ?? ''
    );

    $errors = [];

    if (!is_int($targetUserId)) {
        $errors['target_user_id'] =
            'Wybierz poprawnego użytkownika.';
    } elseif ($targetUserId === $actorUserId) {
        /*
         * Nawet ręczna manipulacja formularzem
         * nie pozwala ominąć tej reguły.
         */
        $errors['target_user_id'] =
            'Własne hasło zmień przez ekran '
            . '"Zmień hasło".';
    }

    $passwordErrors =
        app_validate_new_password(
            $newPassword,
            $newPasswordConfirmation
        );

    $errors = array_merge(
        $errors,
        $passwordErrors
    );

    if ($errors !== []) {
        app_store_form_state(
            'password_reset',
            $errors,
            [
                'target_user_id' =>
                    is_int($targetUserId)
                        ? (string) $targetUserId
                        : '',
            ]
        );

        app_redirect('/password-reset.php');
    }

    $connection = null;

    try {
        $connection = require dirname(__DIR__)
            . '/config/database.php';

        $repository = new PasswordRepository(
            $connection
        );

        $target = $repository->getAdminResetTarget(
            $actorUserId,
            $targetUserId
        );

        if (!is_array($target)) {
            app_store_form_state(
                'password_reset',
                [
                    'target_user_id' =>
                        'Użytkownik nie istnieje '
                        . 'albo nie możesz wykonać tej operacji.',
                ],
                [
                    'target_user_id' =>
                        (string) $targetUserId,
                ]
            );

            app_redirect('/password-reset.php');
        }

        $currentHash = (string)
            $target['HasloHash'];

        /*
         * Administrator nie musi znać starego hasła,
         * ale nie ma sensu resetować go dokładnie
         * do tej samej wartości.
         */
        if (
            password_verify(
                $newPassword,
                $currentHash
            )
        ) {
            app_store_form_state(
                'password_reset',
                [
                    'password' =>
                        'Nowe hasło musi różnić się '
                        . 'od obecnego hasła użytkownika.',
                ],
                [
                    'target_user_id' =>
                        (string) $targetUserId,
                ]
            );

            app_redirect('/password-reset.php');
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
            $repository->adminResetPassword(
                $actorUserId,
                $targetUserId,
                $currentHash,
                $newHash
            );

        if ($newSessionVersion === null) {
            app_store_form_state(
                'password_reset',
                [
                    'general' =>
                        'Stan konta zmienił się podczas '
                        . 'operacji. Spróbuj ponownie.',
                ],
                [
                    'target_user_id' =>
                        (string) $targetUserId,
                ]
            );

            app_redirect('/password-reset.php');
        }

        $targetLogin =
            (string) $target['Login'];

        /*
         * Nie zapisujemy ani starego, ani nowego
         * hasła w sesji, flashu czy logach.
         */
        $newPassword = '';
        $newPasswordConfirmation = '';
        $currentHash = '';
        $newHash = '';

        app_flash(
            'success',
            'Hasło użytkownika '
            . $targetLogin
            . ' zostało zresetowane. '
            . 'Wszystkie jego dotychczasowe '
            . 'sesje zostały unieważnione.'
        );

        app_redirect('/password-reset.php');
    } catch (Throwable $exception) {
        error_log(
            'Reset hasła przez administratora: '
            . $exception->getMessage()
        );

        app_store_form_state(
            'password_reset',
            [
                'general' =>
                    'Nie udało się zresetować hasła. '
                    . 'Spróbuj ponownie.',
            ],
            [
                'target_user_id' =>
                    is_int($targetUserId)
                        ? (string) $targetUserId
                        : '',
            ]
        );

        app_redirect('/password-reset.php');
    } finally {
        if (is_resource($connection)) {
            sqlsrv_close($connection);
        }
    }
}

/*
 * GET — przygotowanie formularza.
 */
$connection = null;
$users = [];

try {
    $connection = require dirname(__DIR__)
        . '/config/database.php';

    $repository = new PasswordRepository(
        $connection
    );

    $users = $repository->getUsersForAdminReset(
        $actorUserId
    );
} catch (Throwable $exception) {
    error_log(
        'Lista użytkowników do resetu hasła: '
        . $exception->getMessage()
    );

    http_response_code(500);

    echo 'Nie udało się przygotować formularza.';
    exit;
} finally {
    if (is_resource($connection)) {
        sqlsrv_close($connection);
    }
}

$formState = app_consume_form_state(
    'password_reset'
);

$errors = $formState['errors'];
$oldInput = $formState['old_input'];

$selectedUserId = isset(
    $oldInput['target_user_id']
)
    ? (int) $oldInput['target_user_id']
    : 0;

$successMessages = app_consume_flash(
    'success'
);

$errorMessages = app_consume_flash(
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
        Reset hasła — Domowe wydatki
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
                <h1>Reset hasła użytkownika</h1>

                <p class="muted">
                    Nadanie nowego hasła unieważni
                    wszystkie aktualne sesje wybranego konta.
                </p>
            </div>
        </div>

        <?php foreach ($successMessages as $message): ?>
            <div class="alert alert-success">
                <?= app_e($message) ?>
            </div>
        <?php endforeach; ?>

        <?php foreach ($errorMessages as $message): ?>
            <div class="alert alert-error">
                <?= app_e($message) ?>
            </div>
        <?php endforeach; ?>

        <?php if (isset($errors['general'])): ?>
            <div class="alert alert-error">
                <?= app_e($errors['general']) ?>
            </div>
        <?php endif; ?>

        <?php if ($users === []): ?>
            <div class="alert alert-warning">
                Nie ma innych użytkowników,
                którym można zresetować hasło.
            </div>
        <?php else: ?>
            <form
                method="post"
                action="/password-reset.php"
                class="form"
                novalidate
            >
                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= app_e($csrfToken) ?>"
                >

                <div class="form-field">
                    <label for="target_user_id">
                        Użytkownik
                    </label>

                    <select
                        id="target_user_id"
                        name="target_user_id"
                        required
                    >
                        <option value="">
                            Wybierz użytkownika
                        </option>

                        <?php foreach ($users as $targetUser): ?>
                            <?php
                            $targetUserId = (int)
                                $targetUser['UzytkownikId'];

                            $label = trim(
                                (string) $targetUser['Imie']
                                . ' '
                                . (string) $targetUser['Nazwisko']
                            );

                            $label .= ' — '
                                . (string) $targetUser['Login']
                                . ' ('
                                . (string) $targetUser['Rola']
                                . ')';

                            if (
                                (int) $targetUser['CzyAktywny']
                                !== 1
                            ) {
                                $label .= ' [nieaktywny]';
                            }
                            ?>

                            <option
                                value="<?= $targetUserId ?>"
                                <?= $selectedUserId
                                    === $targetUserId
                                    ? 'selected'
                                    : ''
                                ?>
                            >
                                <?= app_e($label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <?php if (
                        isset($errors['target_user_id'])
                    ): ?>
                        <p class="field-error">
                            <?= app_e(
                                $errors['target_user_id']
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
                        Hasło nie będzie nigdzie wyświetlane
                        po zapisaniu.
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
                        Resetuj hasło
                    </button>
                </div>
            </form>
        <?php endif; ?>
    </section>
</main>
</body>
</html>