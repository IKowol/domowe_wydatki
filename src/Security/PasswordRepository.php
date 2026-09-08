<?php

declare(strict_types=1);

require_once dirname(__DIR__)
    . '/Database/errors.php';

final class PasswordRepository
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
     * @return array<string, mixed>|null
     */
    public function getOwnPasswordState(
        int $userId
    ): ?array {
        $sql = <<<'SQL'
            SELECT
                UzytkownikId,
                HasloHash,
                SesjaWersja
            FROM app.Uzytkownicy
            WHERE UzytkownikId = ?
              AND CzyAktywny = 1;
        SQL;

        $statement = sqlsrv_query(
            $this->connection,
            $sql,
            [$userId]
        );

        if ($statement === false) {
            app_log_sqlsrv_errors(
                'Błąd pobierania stanu hasła'
            );

            throw new RuntimeException(
                'Nie udało się pobrać danych konta.'
            );
        }

        try {
            $result = sqlsrv_fetch_array(
                $statement,
                SQLSRV_FETCH_ASSOC
            );

            if ($result === false) {
                app_log_sqlsrv_errors(
                    'Błąd odczytu stanu hasła'
                );

                throw new RuntimeException(
                    'Nie udało się odczytać danych konta.'
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
     * Zmienia hasło tylko wtedy, gdy hash nie zmienił
     * się od momentu jego pobrania.
     *
     * Zwraca nową wersję sesji.
     */
    public function changeOwnPassword(
        int $userId,
        string $expectedCurrentHash,
        string $newHash
    ): ?int {
        $sql = <<<'SQL'
            UPDATE app.Uzytkownicy
            SET
                HasloHash = ?,
                SesjaWersja = SesjaWersja + 1
            OUTPUT
                INSERTED.SesjaWersja
            WHERE UzytkownikId = ?
              AND CzyAktywny = 1
              AND HasloHash COLLATE Latin1_General_100_BIN2
                  = ?;
        SQL;

        $statement = sqlsrv_query(
            $this->connection,
            $sql,
            [
                $newHash,
                $userId,
                $expectedCurrentHash,
            ]
        );

        if ($statement === false) {
            app_log_sqlsrv_errors(
                'Błąd zmiany własnego hasła'
            );

            throw new RuntimeException(
                'Nie udało się zmienić hasła.'
            );
        }

        try {
            $result = sqlsrv_fetch_array(
                $statement,
                SQLSRV_FETCH_ASSOC
            );

            if ($result === false) {
                app_log_sqlsrv_errors(
                    'Błąd odczytu nowej wersji sesji'
                );

                throw new RuntimeException(
                    'Nie udało się potwierdzić zmiany hasła.'
                );
            }

            if ($result === null) {
                return null;
            }

            return (int) $result['SesjaWersja'];
        } finally {
            sqlsrv_free_stmt($statement);
        }
    }
    /**
 * Lista kont, którym administrator może zresetować hasło.
 *
 * Nie zwracamy konta samego administratora wykonującego
 * operację, ponieważ własne hasło zmienia się przez
 * password-change.php.
 *
 * @return list<array<string, mixed>>
 */
public function getUsersForAdminReset(
    int $actorUserId
): array {
    $sql = <<<'SQL'
        SELECT
            target.UzytkownikId,
            target.Login,
            target.Imie,
            target.Nazwisko,
            target.CzyAktywny,
            targetRole.Nazwa AS Rola
        FROM app.Uzytkownicy AS target
        INNER JOIN app.Role AS targetRole
            ON targetRole.RolaId = target.RolaId
        WHERE target.UzytkownikId <> ?
          AND EXISTS
          (
              SELECT 1
              FROM app.Uzytkownicy AS actor
              INNER JOIN app.Role AS actorRole
                  ON actorRole.RolaId = actor.RolaId
              WHERE actor.UzytkownikId = ?
                AND actor.CzyAktywny = 1
                AND actorRole.Nazwa = N'ADMIN'
          )
        ORDER BY
            target.Nazwisko,
            target.Imie,
            target.Login,
            target.UzytkownikId;
    SQL;

    $statement = sqlsrv_query(
        $this->connection,
        $sql,
        [
            $actorUserId,
            $actorUserId,
        ]
    );

    if ($statement === false) {
        app_log_sqlsrv_errors(
            'Błąd pobierania użytkowników do resetu hasła'
        );

        throw new RuntimeException(
            'Nie udało się pobrać użytkowników.'
        );
    }

    $users = [];

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
                    'Błąd odczytu użytkowników do resetu hasła'
                );

                throw new RuntimeException(
                    'Nie udało się odczytać użytkowników.'
                );
            }

            $users[] = $row;
        }
    } finally {
        sqlsrv_free_stmt($statement);
    }

    return $users;
}
/**
 * Zwraca dane potrzebne do bezpiecznego resetu hasła.
 *
 * @return array<string, mixed>|null
 */
public function getAdminResetTarget(
    int $actorUserId,
    int $targetUserId
): ?array {
    $sql = <<<'SQL'
        SELECT
            target.UzytkownikId,
            target.Login,
            target.Imie,
            target.Nazwisko,
            target.CzyAktywny,
            target.HasloHash,
            target.SesjaWersja,
            targetRole.Nazwa AS Rola
        FROM app.Uzytkownicy AS target
        INNER JOIN app.Role AS targetRole
            ON targetRole.RolaId = target.RolaId
        WHERE target.UzytkownikId = ?
          AND target.UzytkownikId <> ?
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
            $actorUserId,
        ]
    );

    if ($statement === false) {
        app_log_sqlsrv_errors(
            'Błąd pobierania konta do resetu hasła'
        );

        throw new RuntimeException(
            'Nie udało się pobrać danych użytkownika.'
        );
    }

    try {
        $result = sqlsrv_fetch_array(
            $statement,
            SQLSRV_FETCH_ASSOC
        );

        if ($result === false) {
            app_log_sqlsrv_errors(
                'Błąd odczytu konta do resetu hasła'
            );

            throw new RuntimeException(
                'Nie udało się odczytać danych użytkownika.'
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
 * Resetuje hasło innego użytkownika.
 *
 * Warunki:
 * - aktor nadal musi być aktywnym ADMIN-em,
 * - administrator nie może resetować własnego hasła,
 * - hash celu nie może zmienić się pomiędzy SELECT i UPDATE.
 *
 * Zwraca nową wersję sesji albo NULL.
 */
public function adminResetPassword(
    int $actorUserId,
    int $targetUserId,
    string $expectedCurrentHash,
    string $newHash
): ?int {
    $sql = <<<'SQL'
        UPDATE target
        SET
            HasloHash = ?,
            SesjaWersja = SesjaWersja + 1
        OUTPUT
            INSERTED.SesjaWersja
        FROM app.Uzytkownicy AS target
        WHERE target.UzytkownikId = ?
          AND target.UzytkownikId <> ?
          AND target.HasloHash
              COLLATE Latin1_General_100_BIN2 = ?
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
            $newHash,
            $targetUserId,
            $actorUserId,
            $expectedCurrentHash,
            $actorUserId,
        ]
    );

    if ($statement === false) {
        app_log_sqlsrv_errors(
            'Błąd resetu hasła przez administratora'
        );

        throw new RuntimeException(
            'Nie udało się zresetować hasła.'
        );
    }

    try {
        $result = sqlsrv_fetch_array(
            $statement,
            SQLSRV_FETCH_ASSOC
        );

        if ($result === false) {
            app_log_sqlsrv_errors(
                'Błąd potwierdzenia resetu hasła'
            );

            throw new RuntimeException(
                'Nie udało się potwierdzić resetu hasła.'
            );
        }

        if ($result === null) {
            return null;
        }

        return (int) $result['SesjaWersja'];
    } finally {
        sqlsrv_free_stmt($statement);
    }
}
}