<?php

declare(strict_types=1);

require_once dirname(__DIR__)
    . '/Database/errors.php';

require_once __DIR__
    . '/ExpenseCreationException.php';

require_once __DIR__
    . '/ExpenseUpdateException.php';

require_once __DIR__
    . '/ExpenseDeleteException.php';

final class ExpenseRepository
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
     * @return list<array<string, mixed>>
     */
    public function getActiveStores(): array
    {
        $sql = <<<'SQL'
            SELECT
                s.SklepId,
                s.Nazwa,
                s.Miasto,
                s.Ulica,
                s.NumerBudynku
            FROM app.Sklepy AS s
            WHERE s.CzyAktywny = 1
            ORDER BY
                s.Nazwa,
                s.Miasto,
                s.Ulica,
                s.NumerBudynku,
                s.SklepId;
        SQL;

        $statement = sqlsrv_query(
            $this->connection,
            $sql
        );

        if ($statement === false) {
            app_log_sqlsrv_errors(
                'Błąd pobierania aktywnych sklepów'
            );

            throw new RuntimeException(
                'Nie udało się pobrać sklepów.'
            );
        }

        $stores = [];

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
                        'Błąd odczytu aktywnych sklepów'
                    );

                    throw new RuntimeException(
                        'Nie udało się odczytać sklepów.'
                    );
                }

                $stores[] = $row;
            }
        } finally {
            sqlsrv_free_stmt($statement);
        }

        return $stores;
    }

    /**
     * Lista aktywnych użytkowników dostępna administratorowi.
     *
     * @return list<array<string, mixed>>
     */
    public function getActiveUsers(): array
    {
        $sql = <<<'SQL'
            SELECT
                u.UzytkownikId,
                u.Login,
                u.Imie,
                u.Nazwisko,
                r.Nazwa AS Rola
            FROM app.Uzytkownicy AS u
            INNER JOIN app.Role AS r
                ON r.RolaId = u.RolaId
            WHERE u.CzyAktywny = 1
            ORDER BY
                u.Nazwisko,
                u.Imie,
                u.Login,
                u.UzytkownikId;
        SQL;

        $statement = sqlsrv_query(
            $this->connection,
            $sql
        );

        if ($statement === false) {
            app_log_sqlsrv_errors(
                'Błąd pobierania aktywnych użytkowników'
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
                        'Błąd odczytu aktywnych użytkowników'
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
     * Wywołuje procedurę dodającą wydatek.
     *
     * @return array<string, mixed>
     */
    public function create(
        int $actorUserId,
        int $ownerUserId,
        int $storeId,
        string $expenseDate,
        string $amount,
        ?string $description
    ): array {
        $sql = <<<'SQL'
            EXEC app.Wydatek_Dodaj
                @AktorUzytkownikId = ?,
                @WlascicielUzytkownikId = ?,
                @SklepId = ?,
                @DataWydatku = ?,
                @Kwota = ?,
                @Opis = ?;
        SQL;

        $statement = sqlsrv_query(
            $this->connection,
            $sql,
            [
                $actorUserId,
                $ownerUserId,
                $storeId,
                $expenseDate,
                $amount,
                $description,
            ]
        );

        if ($statement === false) {
            $errors = sqlsrv_errors(
                SQLSRV_ERR_ALL
            );

            if (is_array($errors)) {
                foreach ($errors as $error) {
                    $code = (int) (
                        $error['code'] ?? 0
                    );

                    $reason = match ($code) {
                        50001 =>
                            ExpenseCreationException::ACTOR_INACTIVE,

                        50002 =>
                            ExpenseCreationException::FORBIDDEN_OWNER,

                        50003 =>
                            ExpenseCreationException::OWNER_INACTIVE,

                        50004 =>
                            ExpenseCreationException::STORE_INACTIVE,

                        50005 =>
                            ExpenseCreationException::INVALID_AMOUNT,

                        50006 =>
                            ExpenseCreationException::DESCRIPTION_TOO_LONG,

                        default => null,
                    };

                    if ($reason !== null) {
                        throw new ExpenseCreationException(
                            $reason
                        );
                    }
                }
            }

            app_log_sqlsrv_errors(
                'Błąd wykonywania procedury dodawania wydatku'
            );

            throw new RuntimeException(
                'Nie udało się utworzyć wydatku.'
            );
        }

        try {
            $result = sqlsrv_fetch_array(
                $statement,
                SQLSRV_FETCH_ASSOC
            );

            if ($result === false || $result === null) {
                app_log_sqlsrv_errors(
                    'Procedura nie zwróciła nowego wydatku'
                );

                throw new RuntimeException(
                    'Nie udało się odczytać nowego wydatku.'
                );
            }

            return $result;
        } finally {
            sqlsrv_free_stmt($statement);
        }
    }
    /**
 * @param array{
 *     owner_user_id: ?int,
 *     store_id: ?int,
 *     date_from: ?string,
 *     date_to: ?string,
 *     amount_min: ?string,
 *     amount_max: ?string
 * } $filters
 *
 * @return array{
 *     expenses: list<array<string, mixed>>,
 *     total_rows: int
 * }
 */
public function getPaginated(
    int $actorUserId,
    bool $isAdmin,
    array $filters,
    string $sort,
    int $page,
    int $perPage
): array {
    if ($page < 1 || $perPage < 1) {
        throw new InvalidArgumentException(
            'Parametry paginacji muszą być dodatnie.'
        );
    }

    $sortMap = [
        'date_desc' => [
            'inner' =>
                'DataWydatku DESC, WydatekId DESC',

            'outer' =>
                'p.DataWydatku DESC, p.WydatekId DESC',
        ],

        'date_asc' => [
            'inner' =>
                'DataWydatku ASC, WydatekId ASC',

            'outer' =>
                'p.DataWydatku ASC, p.WydatekId ASC',
        ],

        'amount_desc' => [
            'inner' =>
                'Kwota DESC, WydatekId DESC',

            'outer' =>
                'p.Kwota DESC, p.WydatekId DESC',
        ],

        'amount_asc' => [
            'inner' =>
                'Kwota ASC, WydatekId ASC',

            'outer' =>
                'p.Kwota ASC, p.WydatekId ASC',
        ],

        'created_desc' => [
            'inner' =>
                'UtworzonoUtc DESC, WydatekId DESC',

            'outer' =>
                'p.UtworzonoUtc DESC, p.WydatekId DESC',
        ],

        'created_asc' => [
            'inner' =>
                'UtworzonoUtc ASC, WydatekId ASC',

            'outer' =>
                'p.UtworzonoUtc ASC, p.WydatekId ASC',
        ],
    ];

    $selectedSort = $sortMap[$sort]
        ?? $sortMap['date_desc'];

    $where = [];
    $params = [];

    /*
     * Zwykły użytkownik zawsze jest ograniczony
     * do własnych wydatków.
     */
    if ($isAdmin) {
        if ($filters['owner_user_id'] !== null) {
            $where[] = 'w.UzytkownikId = ?';
            $params[] = $filters['owner_user_id'];
        }
    } else {
        $where[] = 'w.UzytkownikId = ?';
        $params[] = $actorUserId;
    }

    if ($filters['store_id'] !== null) {
        $where[] = 'w.SklepId = ?';
        $params[] = $filters['store_id'];
    }

    if ($filters['date_from'] !== null) {
        $where[] =
            'w.DataWydatku >= CAST(? AS DATE)';

        $params[] = $filters['date_from'];
    }

    if ($filters['date_to'] !== null) {
        $where[] =
            'w.DataWydatku <= CAST(? AS DATE)';

        $params[] = $filters['date_to'];
    }

    if ($filters['amount_min'] !== null) {
        $where[] =
            'w.Kwota >= CAST(? AS DECIMAL(12, 2))';

        $params[] = $filters['amount_min'];
    }

    if ($filters['amount_max'] !== null) {
        $where[] =
            'w.Kwota <= CAST(? AS DECIMAL(12, 2))';

        $params[] = $filters['amount_max'];
    }

    $whereSql = $where !== []
        ? 'WHERE ' . implode(' AND ', $where)
        : '';

    $offset = ($page - 1) * $perPage;

    $innerOrder = $selectedSort['inner'];
    $outerOrder = $selectedSort['outer'];

    $sql = <<<SQL
        WITH FilteredExpenses AS
        (
            SELECT
                w.WydatekId,
                w.UzytkownikId,
                w.SklepId,
                w.DataWydatku,
                w.Kwota,
                w.Opis,
                w.UtworzonoUtc,
                w.ZmienionoUtc,

                u.Login AS UzytkownikLogin,
                u.Imie AS UzytkownikImie,
                u.Nazwisko AS UzytkownikNazwisko,
                u.CzyAktywny AS UzytkownikAktywny,

                s.Nazwa AS SklepNazwa,
                s.Miasto AS SklepMiasto,
                s.CzyAktywny AS SklepAktywny
            FROM app.Wydatki AS w
            INNER JOIN app.Uzytkownicy AS u
                ON u.UzytkownikId = w.UzytkownikId
            INNER JOIN app.Sklepy AS s
                ON s.SklepId = w.SklepId
            {$whereSql}
        ),
        CountedExpenses AS
        (
            SELECT
                COUNT_BIG(*) AS TotalRows
            FROM FilteredExpenses
        ),
        PagedExpenses AS
        (
            SELECT
                WydatekId,
                UzytkownikId,
                SklepId,
                DataWydatku,
                Kwota,
                Opis,
                UtworzonoUtc,
                ZmienionoUtc,
                UzytkownikLogin,
                UzytkownikImie,
                UzytkownikNazwisko,
                UzytkownikAktywny,
                SklepNazwa,
                SklepMiasto,
                SklepAktywny
            FROM FilteredExpenses
            ORDER BY {$innerOrder}
            OFFSET ? ROWS
            FETCH NEXT ? ROWS ONLY
        )
        SELECT
            c.TotalRows,
            p.WydatekId,
            p.UzytkownikId,
            p.SklepId,
            p.DataWydatku,
            p.Kwota,
            p.Opis,
            p.UtworzonoUtc,
            p.ZmienionoUtc,
            p.UzytkownikLogin,
            p.UzytkownikImie,
            p.UzytkownikNazwisko,
            p.UzytkownikAktywny,
            p.SklepNazwa,
            p.SklepMiasto,
            p.SklepAktywny
        FROM CountedExpenses AS c
        LEFT JOIN PagedExpenses AS p
            ON 1 = 1
        ORDER BY {$outerOrder};
    SQL;

    $params[] = [
        $offset,
        SQLSRV_PARAM_IN,
        SQLSRV_PHPTYPE_INT,
        SQLSRV_SQLTYPE_INT,
    ];

    $params[] = [
        $perPage,
        SQLSRV_PARAM_IN,
        SQLSRV_PHPTYPE_INT,
        SQLSRV_SQLTYPE_INT,
    ];

    $statement = sqlsrv_query(
        $this->connection,
        $sql,
        $params
    );

    if ($statement === false) {
        app_log_sqlsrv_errors(
            'Błąd pobierania listy wydatków'
        );

        throw new RuntimeException(
            'Nie udało się pobrać wydatków.'
        );
    }

    $expenses = [];
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
                    'Błąd odczytu listy wydatków'
                );

                throw new RuntimeException(
                    'Nie udało się odczytać wydatków.'
                );
            }

            $totalRows = (int) $row['TotalRows'];

            if ($row['WydatekId'] === null) {
                continue;
            }

            unset($row['TotalRows']);

            $expenses[] = $row;
        }
    } finally {
        sqlsrv_free_stmt($statement);
    }

    return [
        'expenses' => $expenses,
        'total_rows' => $totalRows,
    ];
}
/**
 * Zwraca sklepy występujące w wydatkach widocznych
 * dla danego użytkownika.
 *
 * @return list<array<string, mixed>>
 */
public function getFilterStores(
    int $actorUserId,
    bool $isAdmin
): array {
    $whereSql = $isAdmin
        ? ''
        : 'WHERE w.UzytkownikId = ?';

    $params = $isAdmin
        ? []
        : [$actorUserId];

    $sql = <<<SQL
        SELECT DISTINCT
            s.SklepId,
            s.Nazwa,
            s.Miasto,
            s.CzyAktywny
        FROM app.Wydatki AS w
        INNER JOIN app.Sklepy AS s
            ON s.SklepId = w.SklepId
        {$whereSql}
        ORDER BY
            s.Nazwa,
            s.Miasto,
            s.SklepId;
    SQL;

    $statement = sqlsrv_query(
        $this->connection,
        $sql,
        $params
    );

    if ($statement === false) {
        app_log_sqlsrv_errors(
            'Błąd pobierania sklepów do filtrów'
        );

        throw new RuntimeException(
            'Nie udało się pobrać sklepów do filtrów.'
        );
    }

    $stores = [];

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
                throw new RuntimeException(
                    'Nie udało się odczytać sklepów.'
                );
            }

            $stores[] = $row;
        }
    } finally {
        sqlsrv_free_stmt($statement);
    }

    return $stores;
}
/**
 * @return list<array<string, mixed>>
 */
public function getFilterUsers(
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
            ON u.UzytkownikId = w.UzytkownikId
        WHERE EXISTS
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
            'Błąd pobierania użytkowników do filtrów'
        );

        throw new RuntimeException(
            'Nie udało się pobrać użytkowników do filtrów.'
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
 * Pobiera wydatek, jeżeli aktor może go edytować.
 *
 * USER może edytować własny wydatek.
 * ADMIN może edytować dowolny wydatek.
 *
 * @return array<string, mixed>|null
 */
public function findEditableById(
    int $expenseId,
    int $actorUserId
): ?array {
    $sql = <<<'SQL'
        SELECT
            w.WydatekId,
            w.UzytkownikId,
            w.SklepId,

            CONVERT(
                CHAR(10),
                w.DataWydatku,
                23
            ) AS DataWydatkuTekst,

            CONVERT(
                VARCHAR(30),
                w.Kwota
            ) AS KwotaTekst,

            w.Opis,
            w.UtworzonoUtc,
            w.ZmienionoUtc,

            ownerUser.Login AS UzytkownikLogin,
            ownerUser.Imie AS UzytkownikImie,
            ownerUser.Nazwisko AS UzytkownikNazwisko,
            ownerUser.CzyAktywny AS UzytkownikAktywny,

            ownerRole.Nazwa AS UzytkownikRola,

            s.Nazwa AS SklepNazwa,
            s.Miasto AS SklepMiasto,
            s.Ulica AS SklepUlica,
            s.NumerBudynku AS SklepNumerBudynku,
            s.CzyAktywny AS SklepAktywny

        FROM app.Wydatki AS w

        INNER JOIN app.Uzytkownicy AS ownerUser
            ON ownerUser.UzytkownikId =
                w.UzytkownikId

        INNER JOIN app.Role AS ownerRole
            ON ownerRole.RolaId =
                ownerUser.RolaId

        INNER JOIN app.Sklepy AS s
            ON s.SklepId = w.SklepId

        WHERE w.WydatekId = ?
          AND EXISTS
          (
              SELECT 1
              FROM app.Uzytkownicy AS actor

              INNER JOIN app.Role AS actorRole
                  ON actorRole.RolaId =
                      actor.RolaId

              WHERE actor.UzytkownikId = ?
                AND actor.CzyAktywny = 1
                AND
                (
                    actorRole.Nazwa = N'ADMIN'
                    OR w.UzytkownikId =
                        actor.UzytkownikId
                )
          );
    SQL;

    $statement = sqlsrv_query(
        $this->connection,
        $sql,
        [
            $expenseId,
            $actorUserId,
        ]
    );

    if ($statement === false) {
        app_log_sqlsrv_errors(
            'Błąd pobierania wydatku do edycji'
        );

        throw new RuntimeException(
            'Nie udało się pobrać wydatku.'
        );
    }

    try {
        $result = sqlsrv_fetch_array(
            $statement,
            SQLSRV_FETCH_ASSOC
        );

        if ($result === false) {
            app_log_sqlsrv_errors(
                'Błąd odczytu wydatku do edycji'
            );

            throw new RuntimeException(
                'Nie udało się odczytać wydatku.'
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
 * Aktualizuje wydatek przez procedurę SQL Servera.
 *
 * @return array<string, mixed>
 */
public function update(
    int $actorUserId,
    int $expenseId,
    int $ownerUserId,
    int $storeId,
    string $expenseDate,
    string $amount,
    ?string $description
): array {
    $sql = <<<'SQL'
        EXEC app.Wydatek_Edytuj
            @AktorUzytkownikId = ?,
            @WydatekId = ?,
            @WlascicielUzytkownikId = ?,
            @SklepId = ?,
            @DataWydatku = ?,
            @Kwota = ?,
            @Opis = ?;
    SQL;

    $statement = sqlsrv_query(
        $this->connection,
        $sql,
        [
            $actorUserId,
            $expenseId,
            $ownerUserId,
            $storeId,
            $expenseDate,
            $amount,
            $description,
        ]
    );

    if ($statement === false) {
        $errors = sqlsrv_errors(
            SQLSRV_ERR_ALL
        );

        if (is_array($errors)) {
            foreach ($errors as $error) {
                $code = (int) (
                    $error['code'] ?? 0
                );

                $reason = match ($code) {
                    50101 =>
                        ExpenseUpdateException::INVALID_DATE,

                    50102 =>
                        ExpenseUpdateException::ACTOR_INACTIVE,

                    50103 =>
                        ExpenseUpdateException::NOT_FOUND,

                    50104 =>
                        ExpenseUpdateException::FORBIDDEN,

                    50105 =>
                        ExpenseUpdateException::OWNER_INACTIVE,

                    50106 =>
                        ExpenseUpdateException::INVALID_AMOUNT,

                    50107 =>
                        ExpenseUpdateException::DESCRIPTION_TOO_LONG,

                    50108 =>
                        ExpenseUpdateException::STORE_INACTIVE,

                    default => null,
                };

                if ($reason !== null) {
                    throw new ExpenseUpdateException(
                        $reason
                    );
                }
            }
        }

        app_log_sqlsrv_errors(
            'Błąd procedury edycji wydatku'
        );

        throw new RuntimeException(
            'Nie udało się zaktualizować wydatku.'
        );
    }

    try {
        $result = sqlsrv_fetch_array(
            $statement,
            SQLSRV_FETCH_ASSOC
        );

        if ($result === false || $result === null) {
            app_log_sqlsrv_errors(
                'Procedura edycji nie zwróciła wydatku'
            );

            throw new RuntimeException(
                'Nie udało się odczytać '
                . 'zaktualizowanego wydatku.'
            );
        }

        return $result;
    } finally {
        sqlsrv_free_stmt($statement);
    }
}
/**
 * Pobiera wydatek, jeżeli aktor może go usunąć.
 *
 * USER może usuwać własne wydatki.
 * ADMIN może usuwać dowolne wydatki.
 *
 * @return array<string, mixed>|null
 */
public function findDeletableById(
    int $expenseId,
    int $actorUserId
): ?array {
    $sql = <<<'SQL'
        SELECT
            w.WydatekId,
            w.UzytkownikId,
            w.SklepId,

            CONVERT(
                CHAR(10),
                w.DataWydatku,
                23
            ) AS DataWydatkuTekst,

            CONVERT(
                VARCHAR(30),
                CAST(w.Kwota AS DECIMAL(12, 2))
            ) AS KwotaTekst,

            w.Opis,
            w.UtworzonoUtc,

            u.Login AS UzytkownikLogin,
            u.Imie AS UzytkownikImie,
            u.Nazwisko AS UzytkownikNazwisko,

            s.Nazwa AS SklepNazwa,
            s.Miasto AS SklepMiasto

        FROM app.Wydatki AS w

        INNER JOIN app.Uzytkownicy AS u
            ON u.UzytkownikId =
                w.UzytkownikId

        INNER JOIN app.Sklepy AS s
            ON s.SklepId =
                w.SklepId

        WHERE w.WydatekId = ?
          AND EXISTS
          (
              SELECT 1
              FROM app.Uzytkownicy AS actor

              INNER JOIN app.Role AS actorRole
                  ON actorRole.RolaId =
                      actor.RolaId

              WHERE actor.UzytkownikId = ?
                AND actor.CzyAktywny = 1
                AND
                (
                    actorRole.Nazwa = N'ADMIN'
                    OR w.UzytkownikId =
                        actor.UzytkownikId
                )
          );
    SQL;

    $statement = sqlsrv_query(
        $this->connection,
        $sql,
        [
            $expenseId,
            $actorUserId,
        ]
    );

    if ($statement === false) {
        app_log_sqlsrv_errors(
            'Błąd pobierania wydatku do usunięcia'
        );

        throw new RuntimeException(
            'Nie udało się pobrać wydatku.'
        );
    }

    try {
        $result = sqlsrv_fetch_array(
            $statement,
            SQLSRV_FETCH_ASSOC
        );

        if ($result === false) {
            app_log_sqlsrv_errors(
                'Błąd odczytu wydatku do usunięcia'
            );

            throw new RuntimeException(
                'Nie udało się odczytać wydatku.'
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
 * Usuwa wydatek za pomocą procedury SQL Servera.
 *
 * @return array<string, mixed>
 */
public function delete(
    int $actorUserId,
    int $expenseId
): array {
    $sql = <<<'SQL'
        EXEC app.Wydatek_Usun
            @AktorUzytkownikId = ?,
            @WydatekId = ?;
    SQL;

    $statement = sqlsrv_query(
        $this->connection,
        $sql,
        [
            $actorUserId,
            $expenseId,
        ]
    );

    if ($statement === false) {
        $errors = sqlsrv_errors(
            SQLSRV_ERR_ALL
        );

        if (is_array($errors)) {
            foreach ($errors as $error) {
                $code = (int) (
                    $error['code'] ?? 0
                );

                $reason = match ($code) {
                    50201 =>
                        ExpenseDeleteException::ACTOR_INACTIVE,

                    50202 =>
                        ExpenseDeleteException::NOT_FOUND,

                    50203 =>
                        ExpenseDeleteException::FORBIDDEN,

                    default => null,
                };

                if ($reason !== null) {
                    throw new ExpenseDeleteException(
                        $reason
                    );
                }
            }
        }

        app_log_sqlsrv_errors(
            'Błąd procedury usuwania wydatku'
        );

        throw new RuntimeException(
            'Nie udało się usunąć wydatku.'
        );
    }

    try {
        $result = sqlsrv_fetch_array(
            $statement,
            SQLSRV_FETCH_ASSOC
        );

        if ($result === false || $result === null) {
            app_log_sqlsrv_errors(
                'Procedura usuwania nie zwróciła rekordu'
            );

            throw new RuntimeException(
                'Nie udało się potwierdzić usunięcia wydatku.'
            );
        }

        return $result;
    } finally {
        sqlsrv_free_stmt($statement);
    }
}
}