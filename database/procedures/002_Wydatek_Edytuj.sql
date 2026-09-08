USE [domowe_wydatki_update];
GO

CREATE OR ALTER PROCEDURE app.Wydatek_Edytuj
    @AktorUzytkownikId INT,
    @WydatekId INT,
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
        SET @Opis = NULLIF(
            LTRIM(RTRIM(@Opis)),
            N''
        );

        IF @DataWydatku IS NULL
        BEGIN
            THROW 50101,
                N'Data wydatku jest wymagana.',
                1;
        END;

        IF @Kwota <= 0
        BEGIN
            THROW 50106,
                N'Kwota wydatku musi być większa od zera.',
                1;
        END;

        IF @Opis IS NOT NULL
           AND LEN(@Opis) > 500
        BEGIN
            THROW 50107,
                N'Opis wydatku może mieć maksymalnie 500 znaków.',
                1;
        END;

        BEGIN TRANSACTION;

        DECLARE @AktorRola NVARCHAR(20);

        SELECT
            @AktorRola = r.Nazwa
        FROM app.Uzytkownicy AS actor
            WITH (UPDLOCK, HOLDLOCK)
        INNER JOIN app.Role AS r
            ON r.RolaId = actor.RolaId
        WHERE actor.UzytkownikId = @AktorUzytkownikId
          AND actor.CzyAktywny = 1;

        IF @AktorRola IS NULL
        BEGIN
            THROW 50102,
                N'Konto wykonujące operację nie jest aktywne.',
                1;
        END;

        DECLARE @BiezacyWlascicielId INT;
        DECLARE @BiezacySklepId INT;

        /*
         * Pobieramy i blokujemy edytowany wydatek.
         */
        SELECT
            @BiezacyWlascicielId = w.UzytkownikId,
            @BiezacySklepId = w.SklepId
        FROM app.Wydatki AS w
            WITH (UPDLOCK, HOLDLOCK)
        WHERE w.WydatekId = @WydatekId;

        IF @BiezacyWlascicielId IS NULL
        BEGIN
            THROW 50103,
                N'Wydatek nie istnieje.',
                1;
        END;

        /*
         * Zwykły użytkownik może edytować tylko
         * własny wydatek.
         */
        IF @AktorRola <> N'ADMIN'
           AND @BiezacyWlascicielId <> @AktorUzytkownikId
        BEGIN
            THROW 50104,
                N'Brak uprawnień do edycji tego wydatku.',
                1;
        END;

        /*
         * USER nie może zmienić właściciela wydatku.
         */
        IF @AktorRola <> N'ADMIN'
           AND @WlascicielUzytkownikId <> @AktorUzytkownikId
        BEGIN
            THROW 50104,
                N'Brak uprawnień do zmiany właściciela wydatku.',
                1;
        END;

        /*
         * Jeżeli właściciel pozostaje bez zmian,
         * może być obecnie nieaktywny. Jest to istotne
         * dla historycznych danych.
         *
         * Jeżeli właściciel ma zostać zmieniony,
         * nowy użytkownik musi być aktywny.
         */
        IF @WlascicielUzytkownikId <> @BiezacyWlascicielId
        BEGIN
            IF NOT EXISTS
            (
                SELECT 1
                FROM app.Uzytkownicy AS newOwner
                    WITH (UPDLOCK, HOLDLOCK)
                WHERE newOwner.UzytkownikId =
                    @WlascicielUzytkownikId
                  AND newOwner.CzyAktywny = 1
            )
            BEGIN
                THROW 50105,
                    N'Nowy właściciel nie istnieje lub jest nieaktywny.',
                    1;
            END;
        END;

        /*
         * Analogicznie ze sklepem:
         *
         * - obecny nieaktywny sklep można zachować,
         * - przy zmianie nowy sklep musi być aktywny.
         */
        IF @SklepId <> @BiezacySklepId
        BEGIN
            IF NOT EXISTS
            (
                SELECT 1
                FROM app.Sklepy AS newStore
                    WITH (UPDLOCK, HOLDLOCK)
                WHERE newStore.SklepId = @SklepId
                  AND newStore.CzyAktywny = 1
            )
            BEGIN
                THROW 50108,
                    N'Nowy sklep nie istnieje lub jest nieaktywny.',
                    1;
            END;
        END;

        DECLARE @ZaktualizowanyWydatek TABLE
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

        UPDATE w
        SET
            UzytkownikId = @WlascicielUzytkownikId,
            SklepId = @SklepId,
            DataWydatku = @DataWydatku,
            Kwota = @Kwota,
            Opis = @Opis,
            ZmienionoUtc = CONVERT(
                DATETIME2(0),
                SYSUTCDATETIME()
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
        INTO @ZaktualizowanyWydatek
        FROM app.Wydatki AS w
        WHERE w.WydatekId = @WydatekId;

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
        FROM @ZaktualizowanyWydatek;
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

GRANT EXECUTE
    ON OBJECT::app.Wydatek_Edytuj
    TO app_runtime;
GO

/*
 * PHP nie może omijać procedury i wykonywać
 * dowolnego UPDATE bezpośrednio na tabeli.
 */
DENY UPDATE
    ON OBJECT::app.Wydatki
    TO app_runtime;
GO