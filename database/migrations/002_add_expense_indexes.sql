USE [domowe_wydatki_update];
GO


/*
 * 1. Wydatki konkretnego użytkownika.
 *
 * Używany m.in. przez:
 * - listę wydatków USER-a,
 * - raporty użytkownika,
 * - dashboard USER-a,
 * - zakresy dat.
 */
IF NOT EXISTS
(
    SELECT 1
    FROM sys.indexes
    WHERE object_id =
        OBJECT_ID(N'app.Wydatki')
      AND name =
        N'IX_Wydatki_Uzytkownik_Data'
)
BEGIN
    CREATE NONCLUSTERED INDEX
        IX_Wydatki_Uzytkownik_Data
    ON app.Wydatki
    (
        UzytkownikId ASC,
        DataWydatku DESC,
        UtworzonoUtc DESC,
        WydatekId DESC
    )
    INCLUDE
    (
        SklepId,
        Kwota,
        Opis
    );
END;
GO


/*
 * 2. Zapytania obejmujące całe gospodarstwo.
 *
 * Używany m.in. przez:
 * - raport miesięczny ADMIN-a,
 * - trend,
 * - KPI,
 * - ostatnie wydatki.
 */
IF NOT EXISTS
(
    SELECT 1
    FROM sys.indexes
    WHERE object_id =
        OBJECT_ID(N'app.Wydatki')
      AND name =
        N'IX_Wydatki_Data'
)
BEGIN
    CREATE NONCLUSTERED INDEX
        IX_Wydatki_Data
    ON app.Wydatki
    (
        DataWydatku DESC,
        UtworzonoUtc DESC,
        WydatekId DESC
    )
    INCLUDE
    (
        UzytkownikId,
        SklepId,
        Kwota,
        Opis
    );
END;
GO


/*
 * 3. Wydatki według sklepu.
 *
 * Używany m.in. przez:
 * - filtr sklepu,
 * - analizę wydatków według sklepów,
 * - zapytania po relacji FK.
 */
IF NOT EXISTS
(
    SELECT 1
    FROM sys.indexes
    WHERE object_id =
        OBJECT_ID(N'app.Wydatki')
      AND name =
        N'IX_Wydatki_Sklep_Data'
)
BEGIN
    CREATE NONCLUSTERED INDEX
        IX_Wydatki_Sklep_Data
    ON app.Wydatki
    (
        SklepId ASC,
        DataWydatku DESC,
        WydatekId DESC
    )
    INCLUDE
    (
        UzytkownikId,
        Kwota,
        Opis,
        UtworzonoUtc
    );
END;
GO