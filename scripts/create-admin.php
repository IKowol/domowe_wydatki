<?php

declare(strict_types=1);

require_once dirname(__DIR__)
    . '/src/Security/password.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/**
 * Pobiera pojedynczą wartość z terminala.
 */
function prompt(string $label): string
{
    echo $label;

    $value = fgets(STDIN);

    if ($value === false) {
        throw new RuntimeException(
            'Nie udało się odczytać wartości z terminala.'
        );
    }

    return trim($value);
}

/**
 * Zapisuje szczegóły błędu SQL do lokalnego logu,
 * ale nie wyświetla ich jako komunikatu użytkownika.
 */
function throwSqlError(string $context): never
{
    $errors = sqlsrv_errors(SQLSRV_ERR_ALL);

    error_log(
        $context . ': '
        . json_encode(
            $errors,
            JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
        )
    );

    throw new RuntimeException(
        'Operacja bazy danych nie powiodła się. '
        . 'Sprawdź storage/logs/app.log.'
    );
}

$connection = null;
$checkStatement = null;
$insertStatement = null;
$transactionStarted = false;

try {
    echo PHP_EOL;
    echo "Tworzenie pierwszego administratora" . PHP_EOL;
    echo "----------------------------------" . PHP_EOL;

    $login = mb_strtolower(
        prompt('Login: '),
        'UTF-8'
    );

    $firstName = prompt('Imię: ');
    $lastName = prompt('Nazwisko: ');

    $email = mb_strtolower(
        prompt('E-mail: '),
        'UTF-8'
    );

    $phoneInput = prompt(
        'Telefon opcjonalnie — Enter, aby pominąć: '
    );

    $password = prompt(
        'Hasło — minimum 12 znaków: '
    );

    $passwordConfirmation = prompt(
        'Powtórz hasło: '
    );

    /*
     * Walidacja danych wejściowych.
     */
    if (
        preg_match(
            '/\A[a-z0-9._-]{3,50}\z/',
            $login
        ) !== 1
    ) {
        throw new InvalidArgumentException(
            'Login musi mieć od 3 do 50 znaków i może '
            . 'zawierać małe litery, cyfry, kropkę, '
            . 'podkreślenie oraz myślnik.'
        );
    }

    if (
        mb_strlen($firstName, 'UTF-8') < 1
        || mb_strlen($firstName, 'UTF-8') > 50
    ) {
        throw new InvalidArgumentException(
            'Imię musi mieć od 1 do 50 znaków.'
        );
    }

    if (
        mb_strlen($lastName, 'UTF-8') < 1
        || mb_strlen($lastName, 'UTF-8') > 80
    ) {
        throw new InvalidArgumentException(
            'Nazwisko musi mieć od 1 do 80 znaków.'
        );
    }

    if (
        filter_var($email, FILTER_VALIDATE_EMAIL) === false
        || mb_strlen($email, 'UTF-8') > 254
    ) {
        throw new InvalidArgumentException(
            'Podaj poprawny adres e-mail.'
        );
    }

    $phone = $phoneInput !== ''
        ? $phoneInput
        : null;

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
        throw new InvalidArgumentException(
            'Numer telefonu ma nieprawidłowy format.'
        );
    }

    if (strlen($password) < 12) {
        throw new InvalidArgumentException(
            'Hasło musi zawierać co najmniej 12 znaków.'
        );
    }

    if ($password !== $passwordConfirmation) {
        throw new InvalidArgumentException(
            'Podane hasła nie są identyczne.'
        );
    }

    /*
     * Hash powstaje przed wysłaniem danych do SQL Servera.
     * Do bazy nigdy nie trafia hasło jawne.
     */
    $passwordHash = password_hash(
        $password,
        PASSWORD_DEFAULT
    );

    if (
        !is_string($passwordHash)
        || !password_verify($password, $passwordHash)
    ) {
        throw new RuntimeException(
            'Nie udało się bezpiecznie przygotować hasła.'
        );
    }

    /*
     * Usuwamy jawne hasła ze zmiennych możliwie szybko.
     */
    $password = '';
    $passwordConfirmation = '';

    $connection = require dirname(__DIR__)
        . '/config/database.php';

    if (!sqlsrv_begin_transaction($connection)) {
        throwSqlError(
            'Nie udało się rozpocząć transakcji administratora'
        );
    }

    $transactionStarted = true;

    /*
     * UPDLOCK i HOLDLOCK uniemożliwiają równoczesne
     * utworzenie dwóch pierwszych administratorów.
     */
    $checkSql = '
        SELECT COUNT_BIG(*) AS LiczbaAdministratorow
        FROM app.Uzytkownicy WITH (UPDLOCK, HOLDLOCK)
        WHERE RolaId = ?;
    ';

    $checkStatement = sqlsrv_query(
        $connection,
        $checkSql,
        [1]
    );

    if ($checkStatement === false) {
        throwSqlError(
            'Błąd sprawdzania istniejącego administratora'
        );
    }

    $checkResult = sqlsrv_fetch_array(
        $checkStatement,
        SQLSRV_FETCH_ASSOC
    );

    if ($checkResult === false || $checkResult === null) {
        throw new RuntimeException(
            'Nie udało się sprawdzić kont administratorów.'
        );
    }

    if ((int) $checkResult['LiczbaAdministratorow'] > 0) {
        throw new RuntimeException(
            'Konto administratora już istnieje. '
            . 'Skrypt nie utworzył kolejnego konta.'
        );
    }

    /*
     * Wszystkie wartości użytkownika są parametrami.
     * Nie są łączone z tekstem zapytania.
     */
    $insertSql = '
        INSERT INTO app.Uzytkownicy
        (
            RolaId,
            Login,
            HasloHash,
            Imie,
            Nazwisko,
            Email,
            Telefon,
            CzyAktywny
        )
        OUTPUT INSERTED.UzytkownikId
        VALUES
        (
            1,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            1
        );
    ';

    $insertParams = [
        $login,
        $passwordHash,
        $firstName,
        $lastName,
        $email,
        $phone,
    ];

    $insertStatement = sqlsrv_query(
        $connection,
        $insertSql,
        $insertParams
    );

    if ($insertStatement === false) {
        throwSqlError(
            'Błąd dodawania pierwszego administratora'
        );
    }

    $insertResult = sqlsrv_fetch_array(
        $insertStatement,
        SQLSRV_FETCH_ASSOC
    );

    if ($insertResult === false || $insertResult === null) {
        throw new RuntimeException(
            'Nie udało się odczytać ID administratora.'
        );
    }

    $administratorId = (int)
        $insertResult['UzytkownikId'];

    if (!sqlsrv_commit($connection)) {
        throwSqlError(
            'Nie udało się zatwierdzić administratora'
        );
    }

    $transactionStarted = false;

    echo PHP_EOL;
    echo 'Administrator został utworzony.' . PHP_EOL;
    echo 'ID użytkownika: '
        . $administratorId
        . PHP_EOL;

    echo 'Login: '
        . $login
        . PHP_EOL;
} catch (Throwable $exception) {
    if (
        $transactionStarted
        && is_resource($connection)
    ) {
        sqlsrv_rollback($connection);
    }

    fwrite(
        STDERR,
        PHP_EOL
        . 'Błąd: '
        . $exception->getMessage()
        . PHP_EOL
    );

    $exitCode = 1;
} finally {
    if (is_resource($checkStatement)) {
        sqlsrv_free_stmt($checkStatement);
    }

    if (is_resource($insertStatement)) {
        sqlsrv_free_stmt($insertStatement);
    }

    if (is_resource($connection)) {
        sqlsrv_close($connection);
    }
}

if (isset($exitCode)) {
    exit($exitCode);
}