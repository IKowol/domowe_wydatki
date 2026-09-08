<?php

declare(strict_types=1);

require_once dirname(__DIR__)
    . '/src/Auth/auth.php';

require_once dirname(__DIR__)
    . '/src/Support/form.php';

require_once dirname(__DIR__)
    . '/src/User/UserRepository.php';

$authenticatedUser = app_require_auth();

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

$actorUserId = (int)
    $authenticatedUser['user_id'];

$actorRole = (string)
    $authenticatedUser['role'];

/*
 * Dla GET identyfikator pochodzi z adresu.
 * Brak ID oznacza edycję własnego profilu.
 *
 * Dla POST identyfikator pochodzi z ukrytego pola,
 * ale nadal traktujemy go jako dane niezaufane.
 */
$rawTargetUserId = $method === 'POST'
    ? ($_POST['user_id'] ?? null)
    : ($_GET['id'] ?? $actorUserId);

$targetUserId = filter_var(
    $rawTargetUserId,
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

/*
 * Pierwsza warstwa autoryzacji.
 */
if (
    $actorRole !== 'ADMIN'
    && $targetUserId !== $actorUserId
) {
    http_response_code(403);

    echo 'Nie masz uprawnień do edycji tego użytkownika.';
    exit;
}

$editUrl = $targetUserId === $actorUserId
    ? '/user-edit.php'
    : '/user-edit.php?id=' . $targetUserId;

$formName = 'user_edit_' . $targetUserId;

if ($method === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? null;

    if (!app_verify_csrf_token($csrfToken)) {
        app_flash(
            'error',
            'Sesja formularza wygasła. Spróbuj ponownie.'
        );

        app_redirect($editUrl);
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

    if ($errors !== []) {
        app_store_form_state(
            $formName,
            $errors,
            $oldInput
        );

        app_redirect($editUrl);
    }

    $connection = null;
    $updatedUser = null;
    $databaseErrors = [];

    try {
        $connection = require dirname(__DIR__)
            . '/config/database.php';

        $repository = new UserRepository(
            $connection
        );

        /*
         * Druga warstwa autoryzacji znajduje się
         * bezpośrednio w warunku UPDATE.
         */
        $updatedUser = $repository->updateProfile(
            $targetUserId,
            $actorUserId,
            $login,
            $firstName,
            $lastName,
            $email,
            $phone
        );
    } catch (DuplicateUserFieldException $exception) {
        if ($exception->field() === 'login') {
            $databaseErrors['login'] =
                'Konto z takim loginem już istnieje.';
        } elseif ($exception->field() === 'email') {
            $databaseErrors['email'] =
                'Konto z takim adresem e-mail już istnieje.';
        } else {
            $databaseErrors['general'] =
                'Nie udało się zapisać danych użytkownika.';
        }
    } catch (Throwable $exception) {
        error_log(
            'Edycja użytkownika: '
            . $exception->getMessage()
        );

        $databaseErrors['general'] =
            'Nie udało się zapisać danych użytkownika.';
    } finally {
        if (is_resource($connection)) {
            sqlsrv_close($connection);
        }
    }

    if ($databaseErrors !== []) {
        app_store_form_state(
            $formName,
            $databaseErrors,
            $oldInput
        );

        app_redirect($editUrl);
    }

    if (!is_array($updatedUser)) {
        /*
         * Nie ujawniamy, czy rekord istnieje,
         * lecz użytkownik nie ma do niego dostępu.
         */
        http_response_code(404);

        echo 'Nie znaleziono użytkownika lub nie masz dostępu.';
        exit;
    }

    /*
     * Jeśli użytkownik zmienił własne dane,
     * aktualizujemy również bieżącą sesję.
     */
    if ($targetUserId === $actorUserId) {
        $_SESSION['auth']['login'] =
            (string) $updatedUser['Login'];

        $_SESSION['auth']['display_name'] = trim(
            (string) $updatedUser['Imie']
            . ' '
            . (string) $updatedUser['Nazwisko']
        );
    }

    app_flash(
        'success',
        'Dane użytkownika zostały zapisane.'
    );

    app_redirect($editUrl);
}

/*
 * Obsługa żądania GET.
 */
$connection = null;
$editedUser = null;

try {
    $connection = require dirname(__DIR__)
        . '/config/database.php';

    $repository = new UserRepository(
        $connection
    );

    $editedUser = $repository->findEditableById(
        $targetUserId,
        $actorUserId
    );
} catch (Throwable $exception) {
    error_log(
        'Pobieranie użytkownika do edycji: '
        . $exception->getMessage()
    );

    http_response_code(500);

    echo 'Nie udało się pobrać danych użytkownika.';
    exit;
} finally {
    if (is_resource($connection)) {
        sqlsrv_close($connection);
    }
}

if (!is_array($editedUser)) {
    http_response_code(404);

    echo 'Nie znaleziono użytkownika lub nie masz dostępu.';
    exit;
}

$formState = app_consume_form_state(
    $formName
);

$errors = $formState['errors'];
$oldInput = $formState['old_input'];

$formValues = [
    'login' => (string) $editedUser['Login'],
    'first_name' => (string) $editedUser['Imie'],
    'last_name' => (string) $editedUser['Nazwisko'],
    'email' => (string) $editedUser['Email'],
    'phone' => (string) ($editedUser['Telefon'] ?? ''),
];

foreach ($oldInput as $field => $value) {
    $formValues[$field] = $value;
}

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

    <title>Edycja użytkownika — Domowe wydatki</title>

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
            <?= app_e(
                $authenticatedUser['display_name']
            ) ?>
        </span>

        <?php if ($actorRole === 'ADMIN'): ?>
            <a
                href="/users.php"
                class="button-link button-secondary"
            >
                Użytkownicy
            </a>
        <?php else: ?>
            <a
                href="/dashboard.php"
                class="button-link button-secondary"
            >
                Panel
            </a>
        <?php endif; ?>
    </div>
</header>

<main class="container">
    <section class="card form-card">
        <div class="section-header">
            <div>
                <h1>
                    <?= $targetUserId === $actorUserId
                        ? 'Edytuj mój profil'
                        : 'Edytuj użytkownika'
                    ?>
                </h1>

                <p class="muted">
                    ID:
                    <?= (int) $editedUser['UzytkownikId'] ?>
                    · Rola:
                    <?= app_e($editedUser['Rola']) ?>
                    · Status:
                    <?= (int) $editedUser['CzyAktywny'] === 1
                        ? 'aktywny'
                        : 'nieaktywny'
                    ?>
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
                name="user_id"
                value="<?= $targetUserId ?>"
            >

            <div class="form-grid">
                <div class="form-field">
                    <label for="login">Login</label>

                    <input
                        id="login"
                        name="login"
                        type="text"
                        minlength="3"
                        maxlength="50"
                        autocomplete="username"
                        value="<?= app_e(
                            $formValues['login']
                        ) ?>"
                        required
                    >

                    <?php if (isset($errors['login'])): ?>
                        <p class="field-error">
                            <?= app_e($errors['login']) ?>
                        </p>
                    <?php endif; ?>
                </div>

                <div class="form-field">
                    <label for="email">E-mail</label>

                    <input
                        id="email"
                        name="email"
                        type="email"
                        maxlength="254"
                        autocomplete="email"
                        value="<?= app_e(
                            $formValues['email']
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
                    <label for="first_name">Imię</label>

                    <input
                        id="first_name"
                        name="first_name"
                        type="text"
                        maxlength="50"
                        autocomplete="given-name"
                        value="<?= app_e(
                            $formValues['first_name']
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
                    <label for="last_name">Nazwisko</label>

                    <input
                        id="last_name"
                        name="last_name"
                        type="text"
                        maxlength="80"
                        autocomplete="family-name"
                        value="<?= app_e(
                            $formValues['last_name']
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
                    <label for="phone">Telefon</label>

                    <input
                        id="phone"
                        name="phone"
                        type="tel"
                        maxlength="20"
                        autocomplete="tel"
                        value="<?= app_e(
                            $formValues['phone']
                        ) ?>"
                    >

                    <?php if (isset($errors['phone'])): ?>
                        <p class="field-error">
                            <?= app_e($errors['phone']) ?>
                        </p>
                    <?php endif; ?>
                </div>

                <div class="form-field">
                    <label>Rola</label>

                    <input
                        type="text"
                        value="<?= app_e(
                            $editedUser['Rola']
                        ) ?>"
                        readonly
                    >

                    <p class="field-help">
                        Zmianę roli dodamy w następnym etapie.
                    </p>
                </div>
            </div>

            <div class="form-actions">
                <a
                    href="<?= $actorRole === 'ADMIN'
                        ? '/users.php'
                        : '/dashboard.php'
                    ?>"
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