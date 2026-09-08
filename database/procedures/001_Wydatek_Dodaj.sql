USE [domowe_wydatki_update];
GO

CREATE OR ALTER PROCEDURE app.Wydatek_Dodaj
    @AktorUzytkownikId INT,
    @WlascicielUzytkownikId INT,
    @SklepId INT,
    @DataWydatku DATE,
    @Kwota DECIMAL(12, 2),
    @Opis NVARCHAR(MAX) = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    BEGIN TRY
        /*
         * Pusty lub składający się ze spacji opis
         * zapisujemy jako NULL.
         */
        SET @Opis = NULLIF(
            LTRIM(RTRIM(@Opis)),
            N''
        );

        IF @Kwota <= 0
        BEGIN
            THROW 50005,
                N'Kwota wydatku musi być większa od zera.',
                1;
        END;

        IF @Opis IS NOT NULL
           AND LEN(@Opis) > 500
        BEGIN
            THROW 50006,
                N'Opis wydatku może mieć maksymalnie 500 znaków.',
                1;
        END;

        BEGIN TRANSACTION;

        DECLARE @AktorRola NVARCHAR(20);

        /*
         * Blokujemy konto aktora do zakończenia transakcji.
         * Dzięki temu jego rola lub status nie mogą zostać
         * zmienione w trakcie operacji.
         */
        SELECT
            @AktorRola = r.Nazwa
        FROM app.Uzytkownicy AS u
            WITH (UPDLOCK, HOLDLOCK)
        INNER JOIN app.Role AS r
            ON r.RolaId = u.RolaId
        WHERE u.UzytkownikId = @AktorUzytkownikId
          AND u.CzyAktywny = 1;

        IF @AktorRola IS NULL
        BEGIN
            THROW 50001,
                N'Konto wykonujące operację nie jest aktywne.',
                1;
        END;

        /*
         * USER może tworzyć wyłącznie własne wydatki.
         * ADMIN może wskazać innego właściciela.
         */
        IF @AktorRola <> N'ADMIN'
           AND @AktorUzytkownikId
               <> @WlascicielUzytkownikId
        BEGIN
            THROW 50002,
                N'Brak uprawnień do wskazanego użytkownika.',
                1;
        END;

        /*
         * Właściciel wydatku musi istnieć i być aktywny.
         */
        IF NOT EXISTS
        (
            SELECT 1
            FROM app.Uzytkownicy AS ownerUser
                WITH (UPDLOCK, HOLDLOCK)
            WHERE ownerUser.UzytkownikId =
                @WlascicielUzytkownikId
              AND ownerUser.CzyAktywny = 1
        )
        BEGIN
            THROW 50003,
                N'Wskazany użytkownik nie istnieje lub jest nieaktywny.',
                1;
        END;

        /*
         * Nowy wydatek można przypisać wyłącznie
         * do aktywnego sklepu.
         */
        IF NOT EXISTS
        (
            SELECT 1
            FROM app.Sklepy AS s
                WITH (UPDLOCK, HOLDLOCK)
            WHERE s.SklepId = @SklepId
              AND s.CzyAktywny = 1
        )
        BEGIN
            THROW 50004,
                N'Wskazany sklep nie istnieje lub jest nieaktywny.',
                1;
        END;

        /*
         * Wynik INSERT zapisujemy najpierw do zmiennej tabelarycznej.
         * Do klienta zostanie zwrócony dopiero po poprawnym COMMIT.
         */
        DECLARE @NowyWydatek TABLE
        (
            WydatekId INT NOT NULL,
            UzytkownikId INT NOT NULL,
            SklepId INT NOT NULL,
            DataWydatku DATE NOT NULL,
            Kwota DECIMAL(12, 2) NOT NULL,
            Opis NVARCHAR(500) NULL,
            UtworzonoUtc DATETIME2(0) NOT NULL,
            ZmienionoUtc DATETIME2(0) NULL
        );

        INSERT INTO app.Wydatki
        (
            UzytkownikId,
            SklepId,
            DataWydatku,
            Kwota,
            Opis
        )
        OUTPUT
            INSERTED.WydatekId,
            INSERTED.UzytkownikId,
            INSERTED.SklepId,
            INSERTED.DataWydatku,
            INSERTED.Kwota,
            INSERTED.Opis,
            INSERTED.UtworzonoUtc,
            INSERTED.ZmienionoUtc
        INTO @NowyWydatek
        VALUES
        (
            @WlascicielUzytkownikId,
            @SklepId,
            @DataWydatku,
            @Kwota,
            @Opis
        );

        COMMIT TRANSACTION;

        SELECT
            WydatekId,
            UzytkownikId,
            SklepId,
            DataWydatku,
            Kwota,
            Opis,
            UtworzonoUtc,
            ZmienionoUtc
        FROM @NowyWydatek;
    END TRY
    BEGIN CATCH
        IF XACT_STATE() <> 0
        BEGIN
            ROLLBACK TRANSACTION;
        END;

        THROW;
    END CATCH;
END;
GO

/*
 * Konto aplikacji może wykonywać procedurę,
 * ale nie może samodzielnie wykonywać INSERT
 * bez przejścia przez reguły procedury.
 */
GRANT EXECUTE
    ON OBJECT::app.Wydatek_Dodaj
    TO app_runtime;
GO

DENY INSERT
    ON OBJECT::app.Wydatki
    TO app_runtime;
GO