<?php

declare(strict_types=1);

require_once dirname(__DIR__)
    . '/Database/errors.php';

final class ReportRepository
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
     * Miesięczne KPI dla zakresu danych dostępnego aktorowi.
     *
     * @return array<string, mixed>
     */
    public function getMonthlyKpis(
        int $actorUserId,
        ?int $ownerUserId,
        string $currentMonthStart,
        string $nextMonthStart,
        string $previousMonthStart
    ): array {
        $sql = <<<'SQL'
            WITH Actor AS
            (
                SELECT
                    actor.UzytkownikId,
                    actorRole.Nazwa AS Rola
                FROM app.Uzytkownicy AS actor
                INNER JOIN app.Role AS actorRole
                    ON actorRole.RolaId = actor.RolaId
                WHERE actor.UzytkownikId = ?
                  AND actor.CzyAktywny = 1
            ),
            ScopedExpenses AS
            (
                SELECT
                    w.WydatekId,
                    w.UzytkownikId,
                    w.DataWydatku,
                    w.Kwota
                FROM app.Wydatki AS w
                CROSS JOIN Actor AS actor
                WHERE
                    (
                        actor.Rola = N'ADMIN'
                        OR w.UzytkownikId =
                            actor.UzytkownikId
                    )
                    AND
                    (
                        ? IS NULL
                        OR
                        (
                            actor.Rola = N'ADMIN'
                            AND w.UzytkownikId = ?
                        )
                    )
                    AND w.DataWydatku >= CAST(? AS DATE)
                    AND w.DataWydatku < CAST(? AS DATE)
            )
            SELECT
                CAST(
                    COALESCE(
                        SUM(
                            CASE
                                WHEN DataWydatku >=
                                    CAST(? AS DATE)
                                THEN Kwota
                                ELSE 0
                            END
                        ),
                        0
                    )
                    AS DECIMAL(18, 2)
                ) AS CurrentTotal,

                COUNT(
                    CASE
                        WHEN DataWydatku >=
                            CAST(? AS DATE)
                        THEN 1
                    END
                ) AS CurrentCount,

                CAST(
                    AVG(
                        CASE
                            WHEN DataWydatku >=
                                CAST(? AS DATE)
                            THEN Kwota
                        END
                    )
                    AS DECIMAL(18, 2)
                ) AS CurrentAverage,

                CAST(
                    MAX(
                        CASE
                            WHEN DataWydatku >=
                                CAST(? AS DATE)
                            THEN Kwota
                        END
                    )
                    AS DECIMAL(18, 2)
                ) AS CurrentMaximum,

                CAST(
                    COALESCE(
                        SUM(
                            CASE
                                WHEN DataWydatku <
                                    CAST(? AS DATE)
                                THEN Kwota
                                ELSE 0
                            END
                        ),
                        0
                    )
                    AS DECIMAL(18, 2)
                ) AS PreviousTotal

            FROM ScopedExpenses;
        SQL;

        /*
         * ScopedExpenses obejmuje:
         *
         * previousMonthStart <= DataWydatku < nextMonthStart
         *
         * Następnie agregacja dzieli ten zakres na:
         * - poprzedni miesiąc,
         * - bieżący miesiąc.
         */
        $params = [
            $actorUserId,

            $ownerUserId,
            $ownerUserId,

            $previousMonthStart,
            $nextMonthStart,

            $currentMonthStart,
            $currentMonthStart,
            $currentMonthStart,
            $currentMonthStart,
            $currentMonthStart,
        ];

        $statement = sqlsrv_query(
            $this->connection,
            $sql,
            $params
        );

        if ($statement === false) {
            app_log_sqlsrv_errors(
                'Błąd pobierania miesięcznych KPI'
            );

            throw new RuntimeException(
                'Nie udało się pobrać raportu.'
            );
        }

        try {
            $result = sqlsrv_fetch_array(
                $statement,
                SQLSRV_FETCH_ASSOC
            );

            if ($result === false || $result === null) {
                app_log_sqlsrv_errors(
                    'Błąd odczytu miesięcznych KPI'
                );

                throw new RuntimeException(
                    'Nie udało się odczytać raportu.'
                );
            }

            return $result;
        } finally {
            sqlsrv_free_stmt($statement);
        }
    }

    /**
     * Użytkownicy występujący w historii wydatków.
     *
     * Lista dostępna wyłącznie aktywnemu ADMIN-owi.
     * Uwzględniamy również nieaktywne konta,
     * ponieważ mogą posiadać historyczne wydatki.
     *
     * @return list<array<string, mixed>>
     */
    public function getUsersForReportFilter(
        int $actorUserId
    ): array {
        $sql = <<<'SQL'
            SELECT DISTINCT
                u.UzytkownikId,
                u.Login,
                u.Imie,
                u.Nazwisko,
                u.CzyAktywny
            FROM app.Wydatki AS w
            INNER JOIN app.Uzytkownicy AS u
                ON u.UzytkownikId =
                    w.UzytkownikId
            WHERE EXISTS
            (
                SELECT 1
                FROM app.Uzytkownicy AS actor
                INNER JOIN app.Role AS actorRole
                    ON actorRole.RolaId =
                        actor.RolaId
                WHERE actor.UzytkownikId = ?
                  AND actor.CzyAktywny = 1
                  AND actorRole.Nazwa = N'ADMIN'
            )
            ORDER BY
                u.Nazwisko,
                u.Imie,
                u.Login,
                u.UzytkownikId;
        SQL;

        $statement = sqlsrv_query(
            $this->connection,
            $sql,
            [$actorUserId]
        );

        if ($statement === false) {
            app_log_sqlsrv_errors(
                'Błąd pobierania użytkowników raportu'
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
                        'Błąd odczytu użytkowników raportu'
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
 * TOP 5 sklepów w wybranym miesiącu.
 *
 * SharePercent liczony jest względem wszystkich wydatków
 * w tym samym zakresie, a nie wyłącznie względem TOP 5.
 *
 * @return list<array<string, mixed>>
 */
public function getStoreBreakdown(
    int $actorUserId,
    ?int $ownerUserId,
    string $periodStart,
    string $periodEnd
): array {
    $sql = <<<'SQL'
        WITH Actor AS
        (
            SELECT
                actor.UzytkownikId,
                actorRole.Nazwa AS Rola
            FROM app.Uzytkownicy AS actor
            INNER JOIN app.Role AS actorRole
                ON actorRole.RolaId = actor.RolaId
            WHERE actor.UzytkownikId = ?
              AND actor.CzyAktywny = 1
        ),
        StoreTotals AS
        (
            SELECT
                s.SklepId,
                s.Nazwa,
                s.Miasto,
                s.CzyAktywny,

                COUNT_BIG(*) AS ExpenseCount,

                CAST(
                    SUM(w.Kwota)
                    AS DECIMAL(18, 2)
                ) AS TotalAmount,

                CAST(
                    AVG(w.Kwota)
                    AS DECIMAL(18, 2)
                ) AS AverageAmount

            FROM app.Wydatki AS w

            INNER JOIN app.Sklepy AS s
                ON s.SklepId = w.SklepId

            CROSS JOIN Actor AS actor

            WHERE
                (
                    actor.Rola = N'ADMIN'
                    OR w.UzytkownikId =
                        actor.UzytkownikId
                )
                AND
                (
                    ? IS NULL
                    OR
                    (
                        actor.Rola = N'ADMIN'
                        AND w.UzytkownikId = ?
                    )
                )
                AND w.DataWydatku >= CAST(? AS DATE)
                AND w.DataWydatku < CAST(? AS DATE)

            GROUP BY
                s.SklepId,
                s.Nazwa,
                s.Miasto,
                s.CzyAktywny
        ),
        StoreShares AS
        (
            SELECT
                SklepId,
                Nazwa,
                Miasto,
                CzyAktywny,
                ExpenseCount,
                TotalAmount,
                AverageAmount,

                CAST(
                    CASE
                        WHEN SUM(TotalAmount) OVER () = 0
                            THEN 0
                        ELSE
                            (
                                TotalAmount
                                * CAST(100.0 AS DECIMAL(18, 4))
                            )
                            / SUM(TotalAmount) OVER ()
                    END
                    AS DECIMAL(7, 2)
                ) AS SharePercent

            FROM StoreTotals
        )

        SELECT TOP (5)
            SklepId,
            Nazwa,
            Miasto,
            CzyAktywny,
            ExpenseCount,
            TotalAmount,
            AverageAmount,
            SharePercent
        FROM StoreShares
        ORDER BY
            TotalAmount DESC,
            SklepId ASC;
    SQL;

    $statement = sqlsrv_query(
        $this->connection,
        $sql,
        [
            $actorUserId,
            $ownerUserId,
            $ownerUserId,
            $periodStart,
            $periodEnd,
        ]
    );

    if ($statement === false) {
        app_log_sqlsrv_errors(
            'Błąd struktury wydatków według sklepów'
        );

        throw new RuntimeException(
            'Nie udało się pobrać raportu sklepów.'
        );
    }

    $rows = [];

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
                    'Błąd odczytu raportu sklepów'
                );

                throw new RuntimeException(
                    'Nie udało się odczytać raportu sklepów.'
                );
            }

            $rows[] = $row;
        }
    } finally {
        sqlsrv_free_stmt($statement);
    }

    return $rows;
}
/**
 * Agregacja wydatków według miesiąca.
 *
 * PHP uzupełni później miesiące, w których nie było
 * żadnego wydatku.
 *
 * @return list<array<string, mixed>>
 */
public function getMonthlyTrend(
    int $actorUserId,
    ?int $ownerUserId,
    string $periodStart,
    string $periodEnd
): array {
    $sql = <<<'SQL'
        WITH Actor AS
        (
            SELECT
                actor.UzytkownikId,
                actorRole.Nazwa AS Rola
            FROM app.Uzytkownicy AS actor
            INNER JOIN app.Role AS actorRole
                ON actorRole.RolaId = actor.RolaId
            WHERE actor.UzytkownikId = ?
              AND actor.CzyAktywny = 1
        )
        SELECT
            CONVERT(
                CHAR(10),
                DATEFROMPARTS(
                    YEAR(w.DataWydatku),
                    MONTH(w.DataWydatku),
                    1
                ),
                23
            ) AS MonthStart,

            COUNT_BIG(*) AS ExpenseCount,

            CAST(
                SUM(w.Kwota)
                AS DECIMAL(18, 2)
            ) AS TotalAmount

        FROM app.Wydatki AS w

        CROSS JOIN Actor AS actor

        WHERE
            (
                actor.Rola = N'ADMIN'
                OR w.UzytkownikId =
                    actor.UzytkownikId
            )
            AND
            (
                ? IS NULL
                OR
                (
                    actor.Rola = N'ADMIN'
                    AND w.UzytkownikId = ?
                )
            )
            AND w.DataWydatku >= CAST(? AS DATE)
            AND w.DataWydatku < CAST(? AS DATE)

        GROUP BY
            DATEFROMPARTS(
                YEAR(w.DataWydatku),
                MONTH(w.DataWydatku),
                1
            )

        ORDER BY
            DATEFROMPARTS(
                YEAR(w.DataWydatku),
                MONTH(w.DataWydatku),
                1
            );
    SQL;

    $statement = sqlsrv_query(
        $this->connection,
        $sql,
        [
            $actorUserId,
            $ownerUserId,
            $ownerUserId,
            $periodStart,
            $periodEnd,
        ]
    );

    if ($statement === false) {
        app_log_sqlsrv_errors(
            'Błąd trendu miesięcznego'
        );

        throw new RuntimeException(
            'Nie udało się pobrać trendu wydatków.'
        );
    }

    $rows = [];

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
                    'Błąd odczytu trendu miesięcznego'
                );

                throw new RuntimeException(
                    'Nie udało się odczytać trendu.'
                );
            }

            $rows[] = $row;
        }
    } finally {
        sqlsrv_free_stmt($statement);
    }

    return $rows;
}
/**
 * TOP 5 użytkowników według sumy wydatków.
 *
 * Metoda zwróci dane tylko aktywnemu ADMIN-owi.
 *
 * @return list<array<string, mixed>>
 */
public function getUserBreakdown(
    int $actorUserId,
    string $periodStart,
    string $periodEnd
): array {
    $sql = <<<'SQL'
        SELECT TOP (5)
            u.UzytkownikId,
            u.Login,
            u.Imie,
            u.Nazwisko,
            u.CzyAktywny,

            COUNT_BIG(*) AS ExpenseCount,

            CAST(
                SUM(w.Kwota)
                AS DECIMAL(18, 2)
            ) AS TotalAmount,

            CAST(
                AVG(w.Kwota)
                AS DECIMAL(18, 2)
            ) AS AverageAmount

        FROM app.Wydatki AS w

        INNER JOIN app.Uzytkownicy AS u
            ON u.UzytkownikId = w.UzytkownikId

        WHERE w.DataWydatku >= CAST(? AS DATE)
          AND w.DataWydatku < CAST(? AS DATE)

          AND EXISTS
          (
              SELECT 1
              FROM app.Uzytkownicy AS actor

              INNER JOIN app.Role AS actorRole
                  ON actorRole.RolaId =
                      actor.RolaId

              WHERE actor.UzytkownikId = ?
                AND actor.CzyAktywny = 1
                AND actorRole.Nazwa = N'ADMIN'
          )

        GROUP BY
            u.UzytkownikId,
            u.Login,
            u.Imie,
            u.Nazwisko,
            u.CzyAktywny

        ORDER BY
            TotalAmount DESC,
            u.UzytkownikId ASC;
    SQL;

    $statement = sqlsrv_query(
        $this->connection,
        $sql,
        [
            $periodStart,
            $periodEnd,
            $actorUserId,
        ]
    );

    if ($statement === false) {
        app_log_sqlsrv_errors(
            'Błąd rankingu użytkowników'
        );

        throw new RuntimeException(
            'Nie udało się pobrać rankingu użytkowników.'
        );
    }

    $rows = [];

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
                    'Błąd odczytu rankingu użytkowników'
                );

                throw new RuntimeException(
                    'Nie udało się odczytać rankingu.'
                );
            }

            $rows[] = $row;
        }
    } finally {
        sqlsrv_free_stmt($statement);
    }

    return $rows;
}
/**
 * Ostatnie wydatki widoczne dla aktora.
 *
 * USER widzi wyłącznie własne rekordy.
 * ADMIN widzi wydatki całego gospodarstwa.
 *
 * @return list<array<string, mixed>>
 */
public function getRecentExpenses(
    int $actorUserId,
    int $limit = 5
): array {
    if ($limit < 1 || $limit > 20) {
        throw new InvalidArgumentException(
            'Limit ostatnich wydatków musi wynosić od 1 do 20.'
        );
    }

    /*
     * LIMIT nie pochodzi bezpośrednio od użytkownika.
     * Po wcześniejszej walidacji konwertujemy go
     * do liczby całkowitej i osadzamy w TOP().
     */
    $limitSql = (string) $limit;

    $sql = <<<SQL
        WITH Actor AS
        (
            SELECT
                actor.UzytkownikId,
                actorRole.Nazwa AS Rola
            FROM app.Uzytkownicy AS actor
            INNER JOIN app.Role AS actorRole
                ON actorRole.RolaId = actor.RolaId
            WHERE actor.UzytkownikId = ?
              AND actor.CzyAktywny = 1
        )
        SELECT TOP ({$limitSql})
            w.WydatekId,

            CONVERT(
                CHAR(10),
                w.DataWydatku,
                23
            ) AS DataWydatkuTekst,

            CAST(
                w.Kwota
                AS DECIMAL(12, 2)
            ) AS Kwota,

            w.Opis,

            u.UzytkownikId,
            u.Login AS UzytkownikLogin,
            u.Imie AS UzytkownikImie,
            u.Nazwisko AS UzytkownikNazwisko,

            s.SklepId,
            s.Nazwa AS SklepNazwa,
            s.Miasto AS SklepMiasto

        FROM app.Wydatki AS w

        INNER JOIN app.Uzytkownicy AS u
            ON u.UzytkownikId =
                w.UzytkownikId

        INNER JOIN app.Sklepy AS s
            ON s.SklepId =
                w.SklepId

        CROSS JOIN Actor AS actor

        WHERE
            actor.Rola = N'ADMIN'
            OR w.UzytkownikId =
                actor.UzytkownikId

        ORDER BY
            w.DataWydatku DESC,
            w.UtworzonoUtc DESC,
            w.WydatekId DESC;
    SQL;

    $statement = sqlsrv_query(
        $this->connection,
        $sql,
        [$actorUserId]
    );

    if ($statement === false) {
        app_log_sqlsrv_errors(
            'Błąd pobierania ostatnich wydatków dashboardu'
        );

        throw new RuntimeException(
            'Nie udało się pobrać ostatnich wydatków.'
        );
    }

    $expenses = [];

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
                    'Błąd odczytu ostatnich wydatków dashboardu'
                );

                throw new RuntimeException(
                    'Nie udało się odczytać ostatnich wydatków.'
                );
            }

            $expenses[] = $row;
        }
    } finally {
        sqlsrv_free_stmt($statement);
    }

    return $expenses;
}
}