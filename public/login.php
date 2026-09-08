<?php

declare(strict_types=1);

require_once dirname(__DIR__)
    . '/src/Auth/auth.php';

require_once dirname(__DIR__)
    . '/src/Database/errors.php';

app_require_guest();

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
    $connection = null;
    $statement = null;
    $authenticatedUser = null;
    $technicalFailure = false;

    try {
        $csrfToken = $_POST['csrf_token'] ?? null;

        if (!app_verify_csrf_token($csrfToken)) {
            app_flash(
                'error',
                'Sesja formularza wygasła. Spróbuj ponownie.'
            );

            app_redirect('/login.php');
        }

        $login = mb_strtolower(
            trim(
                (string) ($_POST['login'] ?? '')
            ),
            'UTF-8'
        );

        /*
         * Hasła celowo nie przycinamy przez trim().
         * Spacje mogą być częścią prawidłowego hasła.
         */
        $password = (string)
            ($_POST['password'] ?? '');

        $validLoginFormat = preg_match(
            '/\A[a-z0-9._-]{3,50}\z/',
            $login
        ) === 1;

        if (
            !$validLoginFormat
            || $password === ''
        ) {
            app_flash(
                'error',
                'Nieprawidłowy login lub hasło.'
            );

            app_redirect('/login.php');
        }

        $connection = require dirname(__DIR__)
            . '/config/database.php';

        /*
         * Jedno zapytanie logowania.
         *
         * Nie wykonujemy wcześniej SELECT COUNT(*).
         * Pobieramy wyłącznie kolumny potrzebne
         * do uwierzytelnienia i utworzenia sesji.
         */
        $sql = '
            SELECT
                u.UzytkownikId,
                u.Login,
                u.HasloHash,
                u.Imie,
                u.Nazwisko,
                u.CzyAktywny,
                u.SesjaWersja,
                r.Nazwa AS Rola
            FROM app.Uzytkownicy AS u
            INNER JOIN app.Role AS r
                ON r.RolaId = u.RolaId
            WHERE u.Login = ?;
        ';

        $statement = sqlsrv_query(
            $connection,
            $sql,
            [$login]
        );

        if ($statement === false) {
            app_log_sqlsrv_errors(
                'Błąd zapytania logowania'
            );

            $technicalFailure = true;
        } else {
            $databaseUser = sqlsrv_fetch_array(
                $statement,
                SQLSRV_FETCH_ASSOC
            );

            if ($databaseUser === false) {
                app_log_sqlsrv_errors(
                    'Błąd odczytu wyniku logowania'
                );

                $technicalFailure = true;
            } elseif (is_array($databaseUser)) {
                $storedHash = (string)
                    $databaseUser['HasloHash'];

                $passwordIsValid = password_verify(
                    $password,
                    $storedHash
                );

                $accountIsActive = (int)
                    $databaseUser['CzyAktywny'] === 1;

                if (
                    $passwordIsValid
                    && $accountIsActive
                ) {
                    $authenticatedUser = $databaseUser;
                }
            }
        }

        /*
         * Nie przechowujemy hasła dłużej,
         * niż wymaga tego password_verify().
         */
        $password = '';
        unset($password);
    } catch (Throwable $exception) {
        error_log(
            'Nieoczekiwany błąd logowania: '
            . $exception->getMessage()
        );

        $technicalFailure = true;
    } finally {
        if (is_resource($statement)) {
            sqlsrv_free_stmt($statement);
        }

        if (is_resource($connection)) {
            sqlsrv_close($connection);
        }
    }

    if (is_array($authenticatedUser)) {
        app_login_user(
            $authenticatedUser
        );

        app_redirect('/dashboard.php');
    }

    if ($technicalFailure) {
        app_flash(
            'error',
            'Logowanie jest chwilowo niedostępne. '
            . 'Spróbuj ponownie.'
        );
    } else {
        /*
         * Ten sam komunikat dla:
         * - nieistniejącego loginu,
         * - błędnego hasła,
         * - nieaktywnego konta.
         */
        app_flash(
            'error',
            'Nieprawidłowy login lub hasło.'
        );
    }

    app_redirect('/login.php');
}

$errors = app_consume_flash('error');

$loggedOut = (
    $_GET['logged_out'] ?? null
) === '1';

$expired = (
    $_GET['expired'] ?? null
) === '1';

$revoked = (
    $_GET['revoked'] ?? null
) === '1';

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

    <title>Logowanie — Domowe wydatki</title>

    <link
        rel="stylesheet"
        href="/assets/css/app.css"
    >
</head>
<body>
<main class="auth-layout">
    <section class="card auth-card">
        <h1>Domowe wydatki</h1>

        <p class="muted">
            Zaloguj się do swojego konta.
        </p>

        <?php if ($loggedOut): ?>
            <div class="alert alert-success">
                Zostałeś bezpiecznie wylogowany.
            </div>
        <?php endif; ?>

        <?php if ($expired): ?>
            <div class="alert alert-warning">
                Sesja wygasła z powodu braku aktywności.
            </div>
        <?php endif; ?>

        <?php if ($revoked): ?>
            <div class="alert alert-warning">
                Konto zostało dezaktywowane albo zmieniły się
                jego uprawnienia. Zaloguj się ponownie.
            </div>
        <?php endif; ?>

        <?php foreach ($errors as $error): ?>
            <div class="alert alert-error">
                <?= app_e($error) ?>
            </div>
        <?php endforeach; ?>

        <form
            method="post"
            action="/login.php"
            class="form"
        >
            <input
                type="hidden"
                name="csrf_token"
                value="<?= app_e($csrfToken) ?>"
            >

            <label for="login">
                Login
            </label>

            <input
                id="login"
                name="login"
                type="text"
                minlength="3"
                maxlength="50"
                autocomplete="username"
                required
                autofocus
            >

            <label for="password">
                Hasło
            </label>

            <input
                id="password"
                name="password"
                type="password"
                autocomplete="current-password"
                required
            >

            <button type="submit">
                Zaloguj się
            </button>
        </form>
    </section>
</main>
</body>
</html>