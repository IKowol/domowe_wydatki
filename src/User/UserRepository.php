<?php

declare(strict_types=1);

require_once __DIR__
    . '/UserAccessChangeException.php';

require_once dirname(__DIR__)
    . '/Database/errors.php';

require_once __DIR__
    . '/DuplicateUserFieldException.php';
final class UserRepository
{
    private mixed $connection;

    public function __construct(mixed $connection)
    {
        if (!is_resource($connection)) {
            throw new InvalidArgumentException(
                'Przekazano nieprawidłowe połączenie z bazą.'
            );
        }

        $this->connection = $connection;
    }

    /**
     * @return array{
     *     users: list<array<string, mixed>>,
     *     total_rows: int
     * }
     */
    public function getPaginated(
        int $page,
        int $perPage
    ): array {
        if ($page < 1 || $perPage < 1) {
            throw new InvalidArgumentException(
                'Parametry paginacji muszą być dodatnie.'
            );
        }

        $offset = ($page - 1) * $perPage;

        /*
         * FilteredUsers:
         * wybiera wyłącznie potrzebne dane.
         *
         * CountedUsers:
         * oblicza łączną liczbę użytkowników.
         *
         * PagedUsers:
         * pobiera tylko fragment wyników dla danej strony.
         *
         * LEFT JOIN sprawia, że otrzymamy TotalRows nawet wtedy,
         * gdy użytkownik poda numer strony większy niż istniejący.
         */
        $sql = '
            WITH FilteredUsers AS
            (
                SELECT
                    u.UzytkownikId,
                    u.Login,
                    u.Imie,
                    u.Nazwisko,
                    u.Email,
                    u.Telefon,
                    u.CzyAktywny,
                    r.Nazwa AS Rola,
                    u.UtworzonoUtc
                FROM app.Uzytkownicy AS u
                INNER JOIN app.Role AS r
                    ON r.RolaId = u.RolaId
            ),
            CountedUsers AS
            (
                SELECT
                    COUNT_BIG(*) AS TotalRows
                FROM FilteredUsers
            ),
            PagedUsers AS
            (
                SELECT
                    UzytkownikId,
                    Login,
                    Imie,
                    Nazwisko,
                    Email,
                    Telefon,
                    CzyAktywny,
                    Rola,
                    UtworzonoUtc
                FROM FilteredUsers
                ORDER BY UzytkownikId
                OFFSET ? ROWS
                FETCH NEXT ? ROWS ONLY
            )
            SELECT
                c.TotalRows,
                p.UzytkownikId,
                p.Login,
                p.Imie,
                p.Nazwisko,
                p.Email,
                p.Telefon,
                p.CzyAktywny,
                p.Rola,
                p.UtworzonoUtc
            FROM CountedUsers AS c
            LEFT JOIN PagedUsers AS p
                ON 1 = 1
            ORDER BY p.UzytkownikId;
        ';

        /*
         * Jawnie przekazujemy typ INT.
         * Jest to istotne dla OFFSET oraz FETCH NEXT.
         */
        $params = [
            [
                $offset,
                SQLSRV_PARAM_IN,
                SQLSRV_PHPTYPE_INT,
                SQLSRV_SQLTYPE_INT,
            ],
            [
                $perPage,
                SQLSRV_PARAM_IN,
                SQLSRV_PHPTYPE_INT,
                SQLSRV_SQLTYPE_INT,
            ],
        ];

        $statement = sqlsrv_query(
            $this->connection,
            $sql,
            $params
        );

        if ($statement === false) {
            app_log_sqlsrv_errors(
                'Błąd pobierania listy użytkowników'
            );

            throw new RuntimeException(
                'Nie udało się pobrać listy użytkowników.'
            );
        }

        $users = [];
        $totalRows = 0;

        try {
            while (true) {
                $row = sqlsrv_fetch_array(
                    $statement,
                    SQLSRV_FETCH_ASSOC
                );

                if ($row === null) {
                    break;
                }

                if ($row === false) {
                    app_log_sqlsrv_errors(
                        'Błąd odczytu listy użytkowników'
                    );

                    throw new RuntimeException(
                        'Nie udało się odczytać użytkowników.'
                    );
                }

                $totalRows = (int) $row['TotalRows'];

                /*
                 * Przy pustej stronie LEFT JOIN zwraca jeden wiersz
                 * z TotalRows, ale kolumny użytkownika są NULL.
                 */
                if ($row['UzytkownikId'] === null) {
                    continue;
                }

                unset($row['TotalRows']);

                $users[] = $row;
            }
        } finally {
            sqlsrv_free_stmt($statement);
        }

        return [
            'users' => $users,
            'total_rows' => $totalRows,
        ];
    }
    /**
 * Tworzy aktywne konto z rolą USER.
 */
public function create(
    string $login,
    string $passwordHash,
    string $firstName,
    string $lastName,
    string $email,
    ?string $phone
): int {
    $sql = '
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
            2,
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            1
        );
    ';

    $params = [
        $login,
        $passwordHash,
        $firstName,
        $lastName,
        $email,
        $phone,
    ];

    $statement = sqlsrv_query(
        $this->connection,
        $sql,
        $params
    );

    if ($statement === false) {
        $errors = sqlsrv_errors(
            SQLSRV_ERR_ALL
        );

        if (is_array($errors)) {
            foreach ($errors as $error) {
                $code = (int) ($error['code'] ?? 0);
                $message = (string) ($error['message'] ?? '');

                if (!in_array($code, [2601, 2627], true)) {
                    continue;
                }

                if (
                    stripos(
                        $message,
                        'UQ_Uzytkownicy_Login'
                    ) !== false
                ) {
                    throw new DuplicateUserFieldException(
                        'login'
                    );
                }

                if (
                    stripos(
                        $message,
                        'UQ_Uzytkownicy_Email'
                    ) !== false
                ) {
                    throw new DuplicateUserFieldException(
                        'email'
                    );
                }
            }
        }

        app_log_sqlsrv_errors(
            'Błąd dodawania użytkownika'
        );

        throw new RuntimeException(
            'Nie udało się utworzyć użytkownika.'
        );
    }

    try {
        $result = sqlsrv_fetch_array(
            $statement,
            SQLSRV_FETCH_ASSOC
        );

        if ($result === false || $result === null) {
            app_log_sqlsrv_errors(
                'Błąd odczytu ID nowego użytkownika'
            );

            throw new RuntimeException(
                'Nie udało się odczytać ID użytkownika.'
            );
        }

        return (int) $result['UzytkownikId'];
    } finally {
        sqlsrv_free_stmt($statement);
    }
}
/**
 * Zwraca użytkownika, jeżeli aktor może go edytować.
 *
 * Użytkownik może edytować siebie.
 * Aktywny administrator może edytować dowolne konto.
 *
 * @return array<string, mixed>|null
 */
public function findEditableById(
    int $targetUserId,
    int $actorUserId
): ?array {
    $sql = '
        SELECT
            target.UzytkownikId,
            target.Login,
            target.Imie,
            target.Nazwisko,
            target.Email,
            target.Telefon,
            target.CzyAktywny,
            target.RolaId,
            targetRole.Nazwa AS Rola
        FROM app.Uzytkownicy AS target
        INNER JOIN app.Role AS targetRole
            ON targetRole.RolaId = target.RolaId
        WHERE target.UzytkownikId = ?
          AND
          (
              target.UzytkownikId = ?
              OR EXISTS
              (
                  SELECT 1
                  FROM app.Uzytkownicy AS actor
                  INNER JOIN app.Role AS actorRole
                      ON actorRole.RolaId = actor.RolaId
                  WHERE actor.UzytkownikId = ?
                    AND actor.CzyAktywny = 1
                    AND actorRole.Nazwa = N\'ADMIN\'
              )
          );
    ';

    $statement = sqlsrv_query(
        $this->connection,
        $sql,
        [
            $targetUserId,
            $actorUserId,
            $actorUserId,
        ]
    );

    if ($statement === false) {
        app_log_sqlsrv_errors(
            'Błąd pobierania użytkownika do edycji'
        );

        throw new RuntimeException(
            'Nie udało się pobrać użytkownika.'
        );
    }

    try {
        $result = sqlsrv_fetch_array(
            $statement,
            SQLSRV_FETCH_ASSOC
        );

        if ($result === false) {
            app_log_sqlsrv_errors(
                'Błąd odczytu użytkownika do edycji'
            );

            throw new RuntimeException(
                'Nie udało się odczytać użytkownika.'
            );
        }

        return is_array($result)
            ? $result
            : null;
    } finally {
        sqlsrv_free_stmt($statement);
    }
}
/**
 * Aktualizuje podstawowe dane użytkownika.
 *
 * @return array<string, mixed>|null
 */
public function updateProfile(
    int $targetUserId,
    int $actorUserId,
    string $login,
    string $firstName,
    string $lastName,
    string $email,
    ?string $phone
): ?array {
    $sql = '
        UPDATE target
        SET
            Login = ?,
            Imie = ?,
            Nazwisko = ?,
            Email = ?,
            Telefon = ?
        OUTPUT
            INSERTED.UzytkownikId,
            INSERTED.Login,
            INSERTED.Imie,
            INSERTED.Nazwisko,
            INSERTED.Email,
            INSERTED.Telefon
        FROM app.Uzytkownicy AS target
        WHERE target.UzytkownikId = ?
          AND
          (
              target.UzytkownikId = ?
              OR EXISTS
              (
                  SELECT 1
                  FROM app.Uzytkownicy AS actor
                  INNER JOIN app.Role AS actorRole
                      ON actorRole.RolaId = actor.RolaId
                  WHERE actor.UzytkownikId = ?
                    AND actor.CzyAktywny = 1
                    AND actorRole.Nazwa = N\'ADMIN\'
              )
          );
    ';

    $params = [
        $login,
        $firstName,
        $lastName,
        $email,
        $phone,
        $targetUserId,
        $actorUserId,
        $actorUserId,
    ];

    $statement = sqlsrv_query(
        $this->connection,
        $sql,
        $params
    );

    if ($statement === false) {
        $errors = sqlsrv_errors(
            SQLSRV_ERR_ALL
        );

        if (is_array($errors)) {
            foreach ($errors as $error) {
                $code = (int) ($error['code'] ?? 0);
                $message = (string) ($error['message'] ?? '');

                if (!in_array($code, [2601, 2627], true)) {
                    continue;
                }

                if (
                    stripos(
                        $message,
                        'UQ_Uzytkownicy_Login'
                    ) !== false
                ) {
                    throw new DuplicateUserFieldException(
                        'login'
                    );
                }

                if (
                    stripos(
                        $message,
                        'UQ_Uzytkownicy_Email'
                    ) !== false
                ) {
                    throw new DuplicateUserFieldException(
                        'email'
                    );
                }
            }
        }

        app_log_sqlsrv_errors(
            'Błąd aktualizacji użytkownika'
        );

        throw new RuntimeException(
            'Nie udało się zaktualizować użytkownika.'
        );
    }

    try {
        $result = sqlsrv_fetch_array(
            $statement,
            SQLSRV_FETCH_ASSOC
        );

        if ($result === false) {
            app_log_sqlsrv_errors(
                'Błąd odczytu wyniku aktualizacji użytkownika'
            );

            throw new RuntimeException(
                'Nie udało się odczytać wyniku aktualizacji.'
            );
        }

        /*
         * Brak wyniku oznacza:
         * - rekord nie istnieje,
         * - albo aktor nie ma prawa go edytować.
         */
        return is_array($result)
            ? $result
            : null;
    } finally {
        sqlsrv_free_stmt($statement);
    }
}
/**
 * Pobiera konto do zarządzania rolą i statusem.
 *
 * @return array<string, mixed>|null
 */
public function findAccessSettings(
    int $targetUserId,
    int $actorUserId
): ?array {
    $sql = <<<'SQL'
        SELECT
            target.UzytkownikId,
            target.Login,
            target.Imie,
            target.Nazwisko,
            target.Email,
            target.CzyAktywny,
            target.RolaId,
            targetRole.Nazwa AS Rola
        FROM app.Uzytkownicy AS target
        INNER JOIN app.Role AS targetRole
            ON targetRole.RolaId = target.RolaId
        WHERE target.UzytkownikId = ?
          AND EXISTS
          (
              SELECT 1
              FROM app.Uzytkownicy AS actor
              INNER JOIN app.Role AS actorRole
                  ON actorRole.RolaId = actor.RolaId
              WHERE actor.UzytkownikId = ?
                AND actor.CzyAktywny = 1
                AND actorRole.Nazwa = N'ADMIN'
          );
    SQL;

    $statement = sqlsrv_query(
        $this->connection,
        $sql,
        [
            $targetUserId,
            $actorUserId,
        ]
    );

    if ($statement === false) {
        app_log_sqlsrv_errors(
            'Błąd pobierania ustawień dostępu użytkownika'
        );

        throw new RuntimeException(
            'Nie udało się pobrać ustawień dostępu.'
        );
    }

    try {
        $result = sqlsrv_fetch_array(
            $statement,
            SQLSRV_FETCH_ASSOC
        );

        if ($result === false) {
            app_log_sqlsrv_errors(
                'Błąd odczytu ustawień dostępu użytkownika'
            );

            throw new RuntimeException(
                'Nie udało się odczytać ustawień dostępu.'
            );
        }

        return is_array($result)
            ? $result
            : null;
    } finally {
        sqlsrv_free_stmt($statement);
    }
}
/**
 * Zmienia rolę i status konta.
 *
 * Operacja działa w transakcji i blokuje rekordy
 * administratorów, aby dwie równoczesne operacje
 * nie pozostawiły aplikacji bez administratora.
 *
 * @return array<string, mixed>
 */
public function updateAccessSettings(
    int $targetUserId,
    int $actorUserId,
    int $newRoleId,
    bool $newIsActive
): array {
    if (!in_array($newRoleId, [1, 2], true)) {
        throw new InvalidArgumentException(
            'Nieprawidłowy identyfikator roli.'
        );
    }

    $lockStatement = null;
    $countStatement = null;
    $updateStatement = null;
    $transactionStarted = false;

    try {
        if (!sqlsrv_begin_transaction($this->connection)) {
            app_log_sqlsrv_errors(
                'Błąd rozpoczęcia transakcji dostępu'
            );

            throw new RuntimeException(
                'Nie udało się rozpocząć transakcji.'
            );
        }

        $transactionStarted = true;

        /*
         * UPDLOCK:
         * zakładamy blokady aktualizacyjne.
         *
         * HOLDLOCK:
         * utrzymujemy je do końca transakcji.
         */
        $lockSql = <<<'SQL'
            SELECT
                u.UzytkownikId,
                u.RolaId,
                u.CzyAktywny,
                r.Nazwa AS Rola
            FROM app.Uzytkownicy AS u
                WITH (UPDLOCK, HOLDLOCK)
            INNER JOIN app.Role AS r
                ON r.RolaId = u.RolaId
            WHERE u.UzytkownikId IN (?, ?)
            ORDER BY u.UzytkownikId;
        SQL;

        $lockStatement = sqlsrv_query(
            $this->connection,
            $lockSql,
            [
                $actorUserId,
                $targetUserId,
            ]
        );

        if ($lockStatement === false) {
            app_log_sqlsrv_errors(
                'Błąd blokowania kont podczas zmiany dostępu'
            );

            throw new RuntimeException(
                'Nie udało się zabezpieczyć operacji.'
            );
        }

        $lockedUsers = [];

        while (true) {
            $row = sqlsrv_fetch_array(
                $lockStatement,
                SQLSRV_FETCH_ASSOC
            );

            if ($row === null) {
                break;
            }

            if ($row === false) {
                app_log_sqlsrv_errors(
                    'Błąd odczytu zablokowanych kont'
                );

                throw new RuntimeException(
                    'Nie udało się odczytać kont.'
                );
            }

            $lockedUsers[
                (int) $row['UzytkownikId']
            ] = $row;
        }

        $actor = $lockedUsers[$actorUserId] ?? null;
        $target = $lockedUsers[$targetUserId] ?? null;

        if (
            !is_array($actor)
            || (int) $actor['CzyAktywny'] !== 1
            || (string) $actor['Rola'] !== 'ADMIN'
        ) {
            throw new UserAccessChangeException(
                UserAccessChangeException::NOT_FOUND_OR_FORBIDDEN
            );
        }

        if (!is_array($target)) {
            throw new UserAccessChangeException(
                UserAccessChangeException::NOT_FOUND_OR_FORBIDDEN
            );
        }

        /*
         * Administrator nie może odebrać dostępu samemu sobie.
         */
        if ($targetUserId === $actorUserId) {
            throw new UserAccessChangeException(
                UserAccessChangeException::SELF_CHANGE
            );
        }

        $targetWasActiveAdmin =
            (string) $target['Rola'] === 'ADMIN'
            && (int) $target['CzyAktywny'] === 1;

        $targetWillBeActiveAdmin =
            $newRoleId === 1
            && $newIsActive;

        /*
         * Jeżeli aktywny administrator ma przestać nim być,
         * sprawdzamy liczbę wszystkich aktywnych administratorów.
         */
        if (
            $targetWasActiveAdmin
            && !$targetWillBeActiveAdmin
        ) {
            $countSql = <<<'SQL'
                SELECT
                    COUNT_BIG(*) AS LiczbaAdministratorow
                FROM app.Uzytkownicy AS u
                    WITH (UPDLOCK, HOLDLOCK)
                INNER JOIN app.Role AS r
                    ON r.RolaId = u.RolaId
                WHERE u.CzyAktywny = 1
                  AND r.Nazwa = N'ADMIN';
            SQL;

            $countStatement = sqlsrv_query(
                $this->connection,
                $countSql
            );

            if ($countStatement === false) {
                app_log_sqlsrv_errors(
                    'Błąd liczenia administratorów'
                );

                throw new RuntimeException(
                    'Nie udało się sprawdzić administratorów.'
                );
            }

            $countResult = sqlsrv_fetch_array(
                $countStatement,
                SQLSRV_FETCH_ASSOC
            );

            if (
                $countResult === false
                || $countResult === null
            ) {
                throw new RuntimeException(
                    'Nie udało się odczytać liczby administratorów.'
                );
            }

            if (
                (int) $countResult[
                    'LiczbaAdministratorow'
                ] <= 1
            ) {
                throw new UserAccessChangeException(
                    UserAccessChangeException::LAST_ACTIVE_ADMIN
                );
            }
        }

        $updateSql = <<<'SQL'
            UPDATE app.Uzytkownicy
            SET
                RolaId = ?,
                CzyAktywny = ?
            OUTPUT
                INSERTED.UzytkownikId,
                INSERTED.Login,
                INSERTED.RolaId,
                INSERTED.CzyAktywny
            WHERE UzytkownikId = ?;
        SQL;

        $updateStatement = sqlsrv_query(
            $this->connection,
            $updateSql,
            [
                $newRoleId,
                $newIsActive ? 1 : 0,
                $targetUserId,
            ]
        );

        if ($updateStatement === false) {
            app_log_sqlsrv_errors(
                'Błąd aktualizacji roli i statusu'
            );

            throw new RuntimeException(
                'Nie udało się zmienić dostępu użytkownika.'
            );
        }

        $updatedUser = sqlsrv_fetch_array(
            $updateStatement,
            SQLSRV_FETCH_ASSOC
        );

        if (
            $updatedUser === false
            || $updatedUser === null
        ) {
            throw new UserAccessChangeException(
                UserAccessChangeException::NOT_FOUND_OR_FORBIDDEN
            );
        }

        if (!sqlsrv_commit($this->connection)) {
            app_log_sqlsrv_errors(
                'Błąd zatwierdzenia zmiany dostępu'
            );

            throw new RuntimeException(
                'Nie udało się zatwierdzić operacji.'
            );
        }

        $transactionStarted = false;

        return $updatedUser;
    } catch (Throwable $exception) {
        if ($transactionStarted) {
            if (!sqlsrv_rollback($this->connection)) {
                app_log_sqlsrv_errors(
                    'Błąd wycofania zmiany dostępu'
                );
            }
        }

        throw $exception;
    } finally {
        if (is_resource($lockStatement)) {
            sqlsrv_free_stmt($lockStatement);
        }

        if (is_resource($countStatement)) {
            sqlsrv_free_stmt($countStatement);
        }

        if (is_resource($updateStatement)) {
            sqlsrv_free_stmt($updateStatement);
        }
    }
}
}