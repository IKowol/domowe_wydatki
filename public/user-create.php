<?php

declare(strict_types=1);

require_once dirname(__DIR__)
    . '/src/Auth/auth.php';

require_once dirname(__DIR__)
    . '/src/Support/form.php';

require_once dirname(__DIR__)
    . '/src/User/UserRepository.php';

require_once dirname(__DIR__)
    . '/src/Security/password.php';

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
    $csrfToken = $_POST['csrf_token'] ?? null;

    if (!app_verify_csrf_token($csrfToken)) {
        app_flash(
            'error',
            'Sesja formularza wygasła. Spróbuj ponownie.'
        );

        app_redirect('/user-create.php');
    }

    $login = mb_strtolower(
        trim(
            (string) ($_POST['login'] ?? '')
        ),
        'UTF-8'
    );

    $firstName = trim(
        (string) ($_POST['first_name'] ?? '')
    );

    $lastName = trim(
        (string) ($_POST['last_name'] ?? '')
    );

    $email = mb_strtolower(
        trim(
            (string) ($_POST['email'] ?? '')
        ),
        'UTF-8'
    );

    $phoneInput = trim(
        (string) ($_POST['phone'] ?? '')
    );

    $password = (string)
        ($_POST['password'] ?? '');

    $passwordConfirmation = (string)
        ($_POST['password_confirmation'] ?? '');

    $phone = $phoneInput !== ''
        ? $phoneInput
        : null;

    $oldInput = [
        'login' => $login,
        'first_name' => $firstName,
        'last_name' => $lastName,
        'email' => $email,
        'phone' => $phoneInput,
    ];

    $errors = [];

    if (
        preg_match(
            '/\A[a-z0-9._-]{3,50}\z/',
            $login
        ) !== 1
    ) {
        $errors['login'] =
            'Login musi mieć od 3 do 50 znaków i może '
            . 'zawierać małe litery, cyfry, kropkę, '
            . 'podkreślenie oraz myślnik.';
    }

    $firstNameLength = mb_strlen(
        $firstName,
        'UTF-8'
    );

    if (
        $firstNameLength < 1
        || $firstNameLength > 50
    ) {
        $errors['first_name'] =
            'Imię musi mieć od 1 do 50 znaków.';
    }

    $lastNameLength = mb_strlen(
        $lastName,
        'UTF-8'
    );

    if (
        $lastNameLength < 1
        || $lastNameLength > 80
    ) {
        $errors['last_name'] =
            'Nazwisko musi mieć od 1 do 80 znaków.';
    }

    if (
        filter_var(
            $email,
            FILTER_VALIDATE_EMAIL
        ) === false
        || mb_strlen($email, 'UTF-8') > 254
    ) {
        $errors['email'] =
            'Podaj poprawny adres e-mail.';
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

    $passwordErrors = app_validate_new_password(
        $password,
        $passwordConfirmation
    );
    $errors = array_merge(
        $errors,
        $passwordErrors
    );

    if ($errors !== []) {
        app_store_form_state(
            'user_create',
            $errors,
            $oldInput
        );

        app_redirect('/user-create.php');
    }

    $passwordHash = password_hash(
        $password,
        PASSWORD_DEFAULT
    );

    $password = '';
    $passwordConfirmation = '';

    if (!is_string($passwordHash)) {
        error_log(
            'password_hash() nie zwróciło poprawnego hasha.'
        );

        app_flash(
            'error',
            'Nie udało się bezpiecznie przygotować konta.'
        );

        app_store_form_state(
            'user_create',
            [],
            $oldInput
        );

        app_redirect('/user-create.php');
    }

    $connection = null;
    $createdUserId = null;
    $databaseError = null;

    try {
        $connection = require dirname(__DIR__)
            . '/config/database.php';

        $repository = new UserRepository(
            $connection
        );

        $createdUserId = $repository->create(
            $login,
            $passwordHash,
            $firstName,
            $lastName,
            $email,
            $phone
        );
    } catch (DuplicateUserFieldException $exception) {
        if ($exception->field() === 'login') {
            $databaseError = [
                'login' =>
                    'Konto z takim loginem już istnieje.',
            ];
        } elseif ($exception->field() === 'email') {
            $databaseError = [
                'email' =>
                    'Konto z takim adresem e-mail już istnieje.',
            ];
        } else {
            $databaseError = [
                'general' =>
                    'Nie udało się utworzyć użytkownika.',
            ];
        }
    } catch (Throwable $exception) {
        error_log(
            'Tworzenie użytkownika: '
            . $exception->getMessage()
        );

        $databaseError = [
            'general' =>
                'Nie udało się utworzyć użytkownika. '
                . 'Spróbuj ponownie.',
        ];
    } finally {
        if (is_resource($connection)) {
            sqlsrv_close($connection);
        }
    }

    if ($databaseError !== null) {
        app_store_form_state(
            'user_create',
            $databaseError,
            $oldInput
        );

        app_redirect('/user-create.php');
    }

    app_flash(
        'success',
        'Użytkownik został utworzony. ID: '
        . (int) $createdUserId
        . '.'
    );

    app_redirect('/users.php');
}

$formState = app_consume_form_state(
    'user_create'
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

    <title>Dodaj użytkownika — Domowe wydatki</title>

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
                <h1>Dodaj użytkownika</h1>

                <p class="muted">
                    Nowe konto otrzyma rolę USER.
                </p>
            </div>
        </div>

        <?php foreach ($pageErrors as $error): ?>
            <div class="alert alert-error">
                <?= app_e($error) ?>
            </div>
        <?php endforeach; ?>

        <?php if (isset($errors['general'])): ?>
            <div class="alert alert-error">
                <?= app_e($errors['general']) ?>
            </div>
        <?php endif; ?>

        <form
            method="post"
            action="/user-create.php"
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
                    <label for="login">
                        Login
                    </label>

                    <input
                        id="login"
                        name="login"
                        type="text"
                        minlength="3"
                        maxlength="50"
                        autocomplete="off"
                        value="<?= app_e(
                            $oldInput['login'] ?? ''
                        ) ?>"
                        required
                        autofocus
                    >

                    <?php if (isset($errors['login'])): ?>
                        <p class="field-error">
                            <?= app_e($errors['login']) ?>
                        </p>
                    <?php endif; ?>
                </div>

                <div class="form-field">
                    <label for="email">
                        E-mail
                    </label>

                    <input
                        id="email"
                        name="email"
                        type="email"
                        maxlength="254"
                        autocomplete="email"
                        value="<?= app_e(
                            $oldInput['email'] ?? ''
                        ) ?>"
                        required
                    >

                    <?php if (isset($errors['email'])): ?>
                        <p class="field-error">
                            <?= app_e($errors['email']) ?>
                        </p>
                    <?php endif; ?>
                </div>

                <div class="form-field">
                    <label for="first_name">
                        Imię
                    </label>

                    <input
                        id="first_name"
                        name="first_name"
                        type="text"
                        maxlength="50"
                        autocomplete="given-name"
                        value="<?= app_e(
                            $oldInput['first_name'] ?? ''
                        ) ?>"
                        required
                    >

                    <?php if (
                        isset($errors['first_name'])
                    ): ?>
                        <p class="field-error">
                            <?= app_e(
                                $errors['first_name']
                            ) ?>
                        </p>
                    <?php endif; ?>
                </div>

                <div class="form-field">
                    <label for="last_name">
                        Nazwisko
                    </label>

                    <input
                        id="last_name"
                        name="last_name"
                        type="text"
                        maxlength="80"
                        autocomplete="family-name"
                        value="<?= app_e(
                            $oldInput['last_name'] ?? ''
                        ) ?>"
                        required
                    >

                    <?php if (
                        isset($errors['last_name'])
                    ): ?>
                        <p class="field-error">
                            <?= app_e(
                                $errors['last_name']
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
                    <label>
                        Rola
                    </label>

                    <input
                        type="text"
                        value="USER"
                        readonly
                    >

                    <p class="field-help">
                        Rolę będzie można zmienić podczas
                        edycji konta.
                    </p>
                </div>

                <div class="form-field">
                    <label for="password">
                        Hasło
                    </label>

                    <input
                        id="password"
                        name="password"
                        type="password"
                        minlength="12"
                        maxlength="72"
                        autocomplete="new-password"
                        required
                    >

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
                    <label for="password_confirmation">
                        Powtórz hasło
                    </label>

                    <input
                        id="password_confirmation"
                        name="password_confirmation"
                        type="password"
                        minlength="12"
                        maxlength="4096"
                        autocomplete="new-password"
                        required
                    >

                    <?php if (
                        isset(
                            $errors['password_confirmation']
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
            </div>

            <div class="form-actions">
                <a
                    href="/users.php"
                    class="button-link button-secondary"
                >
                    Anuluj
                </a>

                <button type="submit">
                    Utwórz użytkownika
                </button>
            </div>
        </form>
    </section>
</main>
</body>
</html>