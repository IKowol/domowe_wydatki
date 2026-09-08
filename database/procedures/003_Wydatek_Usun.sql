USE [domowe_wydatki_update];
GO

CREATE OR ALTER PROCEDURE app.Wydatek_Usun
    @AktorUzytkownikId INT,
    @WydatekId INT
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    BEGIN TRY
        BEGIN TRANSACTION;

        DECLARE @AktorRola NVARCHAR(20);

        /*
         * Pobieramy i blokujemy konto aktora.
         *
         * Dzięki UPDLOCK + HOLDLOCK jego status lub rola
         * nie mogą zostać zmienione w trakcie operacji.
         */
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
            THROW 50201,
                N'Konto wykonujące operację nie jest aktywne.',
                1;
        END;

        DECLARE @WlascicielUzytkownikId INT;

        /*
         * Pobieramy i blokujemy wydatek.
         */
        SELECT
            @WlascicielUzytkownikId = w.UzytkownikId
        FROM app.Wydatki AS w
            WITH (UPDLOCK, HOLDLOCK)
        WHERE w.WydatekId = @WydatekId;

        IF @WlascicielUzytkownikId IS NULL
        BEGIN
            THROW 50202,
                N'Wydatek nie istnieje.',
                1;
        END;

        /*
         * Zwykły użytkownik może usunąć wyłącznie
         * własny wydatek.
         */
        IF @AktorRola <> N'ADMIN'
           AND @WlascicielUzytkownikId
               <> @AktorUzytkownikId
        BEGIN
            THROW 50203,
                N'Brak uprawnień do usunięcia tego wydatku.',
                1;
        END;

        /*
         * Zachowujemy dane usuniętego rekordu,
         * aby zwrócić je aplikacji po COMMIT.
         */
        DECLARE @UsunietyWydatek TABLE
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

        DELETE FROM app.Wydatki
        OUTPUT
            DELETED.WydatekId,
            DELETED.UzytkownikId,
            DELETED.SklepId,
            DELETED.DataWydatku,
            DELETED.Kwota,
            DELETED.Opis,
            DELETED.UtworzonoUtc,
            DELETED.ZmienionoUtc
        INTO @UsunietyWydatek
        WHERE WydatekId = @WydatekId;

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
        FROM @UsunietyWydatek;
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
    ON OBJECT::app.Wydatek_Usun
    TO app_runtime;
GO

/*
 * Konto PHP nie może wykonywać dowolnego DELETE
 * bez przejścia przez procedurę i jej autoryzację.
 */
DENY DELETE
    ON OBJECT::app.Wydatki
    TO app_runtime;
GO