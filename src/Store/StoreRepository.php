<?php

declare(strict_types=1);

require_once dirname(__DIR__)
    . '/Database/errors.php';

final class StoreRepository
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
     * Pobiera stronę sklepów i ich całkowitą liczbę.
     *
     * Administrator może pobierać wszystkie sklepy.
     * Zwykły użytkownik otrzymuje tylko aktywne sklepy.
     *
     * @return array{
     *     stores: list<array<string, mixed>>,
     *     total_rows: int
     * }
     */
    public function getPaginated(
        int $page,
        int $perPage,
        bool $includeInactive
    ): array {
        if ($page < 1 || $perPage < 1) {
            throw new InvalidArgumentException(
                'Parametry paginacji muszą być dodatnie.'
            );
        }

        $offset = ($page - 1) * $perPage;

        $sql = <<<'SQL'
            WITH FilteredStores AS
            (
                SELECT
                    s.SklepId,
                    s.Nazwa,
                    s.Miasto,
                    s.Ulica,
                    s.NumerBudynku,
                    s.Telefon,
                    s.CzyAktywny,
                    s.UtworzonoUtc
                FROM app.Sklepy AS s
                WHERE
                    (? = 1)
                    OR s.CzyAktywny = 1
            ),
            CountedStores AS
            (
                SELECT
                    COUNT_BIG(*) AS TotalRows
                FROM FilteredStores
            ),
            PagedStores AS
            (
                SELECT
                    SklepId,
                    Nazwa,
                    Miasto,
                    Ulica,
                    NumerBudynku,
                    Telefon,
                    CzyAktywny,
                    UtworzonoUtc
                FROM FilteredStores
                ORDER BY SklepId
                OFFSET ? ROWS
                FETCH NEXT ? ROWS ONLY
            )
            SELECT
                c.TotalRows,
                p.SklepId,
                p.Nazwa,
                p.Miasto,
                p.Ulica,
                p.NumerBudynku,
                p.Telefon,
                p.CzyAktywny,
                p.UtworzonoUtc
            FROM CountedStores AS c
            LEFT JOIN PagedStores AS p
                ON 1 = 1
            ORDER BY p.SklepId;
        SQL;

        $params = [
            [
                $includeInactive ? 1 : 0,
                SQLSRV_PARAM_IN,
                SQLSRV_PHPTYPE_INT,
                SQLSRV_SQLTYPE_INT,
            ],
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
                'Błąd pobierania listy sklepów'
            );

            throw new RuntimeException(
                'Nie udało się pobrać listy sklepów.'
            );
        }

        $stores = [];
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
                        'Błąd odczytu listy sklepów'
                    );

                    throw new RuntimeException(
                        'Nie udało się odczytać sklepów.'
                    );
                }

                $totalRows = (int) $row['TotalRows'];

                /*
                 * Przy pustej stronie CountedStores nadal
                 * zwraca liczbę rekordów, ale pola sklepu
                 * mają wartość NULL.
                 */
                if ($row['SklepId'] === null) {
                    continue;
                }

                unset($row['TotalRows']);

                $stores[] = $row;
            }
        } finally {
            sqlsrv_free_stmt($statement);
        }

        return [
            'stores' => $stores,
            'total_rows' => $totalRows,
        ];
    }

    /**
     * Tworzy aktywny sklep i zwraca jego ID.
     */
    public function create(
        string $name,
        ?string $city,
        ?string $street,
        ?string $buildingNumber,
        ?string $phone
    ): int {
        $sql = <<<'SQL'
            INSERT INTO app.Sklepy
            (
                Nazwa,
                Miasto,
                Ulica,
                NumerBudynku,
                Telefon,
                CzyAktywny
            )
            OUTPUT INSERTED.SklepId
            VALUES
            (
                ?,
                ?,
                ?,
                ?,
                ?,
                1
            );
        SQL;

        $statement = sqlsrv_query(
            $this->connection,
            $sql,
            [
                $name,
                $city,
                $street,
                $buildingNumber,
                $phone,
            ]
        );

        if ($statement === false) {
            app_log_sqlsrv_errors(
                'Błąd dodawania sklepu'
            );

            throw new RuntimeException(
                'Nie udało się utworzyć sklepu.'
            );
        }

        try {
            $result = sqlsrv_fetch_array(
                $statement,
                SQLSRV_FETCH_ASSOC
            );

            if ($result === false || $result === null) {
                app_log_sqlsrv_errors(
                    'Błąd odczytu ID nowego sklepu'
                );

                throw new RuntimeException(
                    'Nie udało się odczytać ID sklepu.'
                );
            }

            return (int) $result['SklepId'];
        } finally {
            sqlsrv_free_stmt($statement);
        }
    }
    /**
 * Pobiera sklep, jeśli aktor jest aktywnym administratorem.
 *
 * @return array<string, mixed>|null
 */
public function findEditableById(
    int $storeId,
    int $actorUserId
): ?array {
    $sql = <<<'SQL'
        SELECT
            s.SklepId,
            s.Nazwa,
            s.Miasto,
            s.Ulica,
            s.NumerBudynku,
            s.Telefon,
            s.CzyAktywny,
            s.UtworzonoUtc
        FROM app.Sklepy AS s
        WHERE s.SklepId = ?
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
            $storeId,
            $actorUserId,
        ]
    );

    if ($statement === false) {
        app_log_sqlsrv_errors(
            'Błąd pobierania sklepu do edycji'
        );

        throw new RuntimeException(
            'Nie udało się pobrać sklepu.'
        );
    }

    try {
        $result = sqlsrv_fetch_array(
            $statement,
            SQLSRV_FETCH_ASSOC
        );

        if ($result === false) {
            app_log_sqlsrv_errors(
                'Błąd odczytu sklepu do edycji'
            );

            throw new RuntimeException(
                'Nie udało się odczytać sklepu.'
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
 * Aktualizuje dane i status sklepu.
 *
 * @return array<string, mixed>|null
 */
public function update(
    int $storeId,
    int $actorUserId,
    string $name,
    ?string $city,
    ?string $street,
    ?string $buildingNumber,
    ?string $phone,
    bool $isActive
): ?array {
    $sql = <<<'SQL'
        UPDATE store
        SET
            Nazwa = ?,
            Miasto = ?,
            Ulica = ?,
            NumerBudynku = ?,
            Telefon = ?,
            CzyAktywny = ?
        OUTPUT
            INSERTED.SklepId,
            INSERTED.Nazwa,
            INSERTED.Miasto,
            INSERTED.Ulica,
            INSERTED.NumerBudynku,
            INSERTED.Telefon,
            INSERTED.CzyAktywny
        FROM app.Sklepy AS store
        WHERE store.SklepId = ?
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

    $params = [
        $name,
        $city,
        $street,
        $buildingNumber,
        $phone,
        $isActive ? 1 : 0,
        $storeId,
        $actorUserId,
    ];

    $statement = sqlsrv_query(
        $this->connection,
        $sql,
        $params
    );

    if ($statement === false) {
        app_log_sqlsrv_errors(
            'Błąd aktualizacji sklepu'
        );

        throw new RuntimeException(
            'Nie udało się zaktualizować sklepu.'
        );
    }

    try {
        $result = sqlsrv_fetch_array(
            $statement,
            SQLSRV_FETCH_ASSOC
        );

        if ($result === false) {
            app_log_sqlsrv_errors(
                'Błąd odczytu wyniku aktualizacji sklepu'
            );

            throw new RuntimeException(
                'Nie udało się odczytać wyniku aktualizacji.'
            );
        }

        return is_array($result)
            ? $result
            : null;
    } finally {
        sqlsrv_free_stmt($statement);
    }
}
}