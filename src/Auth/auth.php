<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2)
    . '/config/session.php';

require_once dirname(__DIR__)
    . '/Support/http.php';

require_once dirname(__DIR__)
    . '/Security/csrf.php';

require_once dirname(__DIR__)
    . '/Database/errors.php';

const APP_AUTH_IDLE_TIMEOUT_SECONDS = 1800;

/**
 * Zwraca dane zalogowanego użytkownika albo null.
 *
 * @return array{
 *     user_id: int,
 *     login: string,
 *     role: string,
 *     display_name: string,
 *     logged_in_at: int,
 *     last_activity: int
 * }|null
 */
function app_current_user(): ?array
{
    $user = $_SESSION['auth'] ?? null;

    if (!is_array($user)) {
        return null;
    }

    $requiredKeys = [
        'user_id',
        'login',
        'role',
        'display_name',
        'logged_in_at',
        'last_activity',
    ];

    foreach ($requiredKeys as $key) {
        if (!array_key_exists($key, $user)) {
            return null;
        }
    }

    return $user;
}

function app_is_authenticated(): bool
{
    return app_current_user() !== null;
}

/**
 * Zapisuje minimalny zestaw danych użytkownika w sesji.
 *
 * @param array<string, mixed> $databaseUser
 */
function app_login_user(array $databaseUser): void
{
    if (!session_regenerate_id(true)) {
        throw new RuntimeException(
            'Nie udało się zabezpieczyć sesji logowania.'
        );
    }

    app_rotate_csrf_token();

    $firstName = trim(
        (string) $databaseUser['Imie']
    );

    $lastName = trim(
        (string) $databaseUser['Nazwisko']
    );

    $_SESSION['auth'] = [
        'user_id' => (int)
            $databaseUser['UzytkownikId'],

        'login' => (string)
            $databaseUser['Login'],

        'role' => (string)
            $databaseUser['Rola'],

        'display_name' => trim(
            $firstName . ' ' . $lastName
        ),

        'session_version' =>
            (int) $databaseUser['SesjaWersja'],

        'logged_in_at' => time(),
        'last_activity' => time(),
    ];
}

/**
 * Usuwa dane sesji oraz cookie sesyjne.
 */
function app_logout_user(): void
{
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();

        setcookie(
            session_name(),
            '',
            [
                'expires' => time() - 42000,
                'path' => $params['path'],
                'domain' => $params['domain'],
                'secure' => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => $params['samesite']
                    ?? 'Lax',
            ]
        );
    }

    session_destroy();
}

function app_require_guest(): void
{
    if (app_is_authenticated()) {
        app_redirect(
            '/dashboard.php',
            302
        );
    }
}

/**
 * @param array<string, mixed> $sessionUser
 *
 * @return array<string, mixed>|null
 */
function app_refresh_authenticated_user(
    array $sessionUser
): ?array {
    $connection = null;
    $statement = null;

    try {
        $connection = require dirname(__DIR__, 2)
            . '/config/database.php';

        $sql = <<<'SQL'
            SELECT
                u.UzytkownikId,
                u.Login,
                u.Imie,
                u.Nazwisko,
                u.CzyAktywny,
                u.SesjaWersja,
                r.Nazwa AS Rola
            FROM app.Uzytkownicy AS u
            INNER JOIN app.Role AS r
                ON r.RolaId = u.RolaId
            WHERE u.UzytkownikId = ?;
        SQL;

        $statement = sqlsrv_query(
            $connection,
            $sql,
            [
                (int) $sessionUser['user_id'],
            ]
        );

        if ($statement === false) {
            app_log_sqlsrv_errors(
                'Błąd odświeżania danych sesji'
            );

            throw new RuntimeException(
                'Nie udało się zweryfikować sesji.'
            );
        }

        $databaseUser = sqlsrv_fetch_array(
            $statement,
            SQLSRV_FETCH_ASSOC
        );

        if ($databaseUser === false) {
            app_log_sqlsrv_errors(
                'Błąd odczytu danych sesji'
            );

            throw new RuntimeException(
                'Nie udało się odczytać danych sesji.'
            );
        }

        if (
            !is_array($databaseUser)
            || (int) $databaseUser['CzyAktywny'] !== 1
        ) {
            return null;
        }

        $databaseSessionVersion =
            (int) $databaseUser['SesjaWersja'];

        $currentSessionVersion =
            (int) (
                $sessionUser['session_version']
                ?? 0
            );

        /*
         * Hasło zostało zmienione lub zresetowane
         * z innej sesji.
         */
        if (
            $currentSessionVersion < 1
            || $currentSessionVersion
                !== $databaseSessionVersion
        ) {
            return null;
        }

        $refreshedUser = [
            'user_id' =>
                (int) $databaseUser['UzytkownikId'],

            'login' =>
                (string) $databaseUser['Login'],

            'role' =>
                (string) $databaseUser['Rola'],

            'display_name' => trim(
                (string) $databaseUser['Imie']
                . ' '
                . (string) $databaseUser['Nazwisko']
            ),

            'session_version' =>
                $databaseSessionVersion,

            'logged_in_at' =>
                (int) $sessionUser['logged_in_at'],

            'last_activity' => time(),
        ];

        $_SESSION['auth'] = $refreshedUser;

        return $refreshedUser;
    } finally {
        if (is_resource($statement)) {
            sqlsrv_free_stmt($statement);
        }

        if (is_resource($connection)) {
            sqlsrv_close($connection);
        }
    }
}

/**
 * Wymaga aktywnego, zalogowanego użytkownika.
 *
 * @return array<string, mixed>
 */
function app_require_auth(): array
{
    $user = app_current_user();

    if ($user === null) {
        app_flash(
            'error',
            'Zaloguj się, aby przejść dalej.'
        );

        app_redirect(
            '/login.php',
            302
        );
    }

    $lastActivity = (int)
        $user['last_activity'];

    if (
        time() - $lastActivity
        > APP_AUTH_IDLE_TIMEOUT_SECONDS
    ) {
        app_logout_user();

        app_redirect(
            '/login.php?expired=1',
            302
        );
    }

    try {
        $user = app_refresh_authenticated_user(
            $user
        );
    } catch (Throwable $exception) {
        error_log(
            'Weryfikacja aktywnej sesji: '
            . $exception->getMessage()
        );

        http_response_code(503);

        echo 'Aplikacja jest chwilowo niedostępna.';
        exit;
    }

    if ($user === null) {
        app_logout_user();

        app_redirect(
            '/login.php?revoked=1',
            302
        );
    }

    return $user;
}

/**
 * Wymaga określonej roli aplikacji.
 *
 * @return array<string, mixed>
 */
function app_require_role(string $requiredRole): array
{
    $user = app_require_auth();

    if ($user['role'] !== $requiredRole) {
        http_response_code(403);

        echo 'Nie masz uprawnień do tej operacji.';
        exit;
    }

    return $user;
}