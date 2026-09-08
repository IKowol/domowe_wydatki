/*
 * ============================================================
 * DOMOWE WYDATKI
 * Finalny schemat bazy danych
 * ============================================================
 *
 * Skrypt służy do utworzenia struktury aplikacji
 * w pustej bazie danych.
 *
 * Tworzy:
 * - schema app
 * - tabele
 * - dane bazowe ról
 * - PK / FK / UNIQUE / CHECK / DEFAULT
 * - indeksy
 * - rolę app_runtime
 * - uprawnienia
 * - procedury:
 *      app.Wydatek_Dodaj
 *      app.Wydatek_Edytuj
 *      app.Wydatek_Usun
 *
 * UWAGA:
 * Nie uruchamiać na działającej bazie zawierającej
 * już tabele aplikacji.
 * ============================================================
 */

SET NOCOUNT ON;
SET XACT_ABORT ON;

BEGIN TRY
    BEGIN TRANSACTION;


    /* ========================================================
       SCHEMA APP
    ======================================================== */

    IF SCHEMA_ID(N'app') IS NULL
    BEGIN
        EXEC (
            N'CREATE SCHEMA app AUTHORIZATION dbo;'
        );
    END;


    /*
     * schema.sql jest snapshotem do fresh install.
     * Jeżeli istnieje już którakolwiek tabela aplikacji,
     * przerywamy zamiast ryzykować modyfikację danych.
     */
    IF
        OBJECT_ID(N'app.Role', N'U') IS NOT NULL
        OR OBJECT_ID(N'app.Uzytkownicy', N'U') IS NOT NULL
        OR OBJECT_ID(N'app.Sklepy', N'U') IS NOT NULL
        OR OBJECT_ID(N'app.Wydatki', N'U') IS NOT NULL
    BEGIN
        THROW 50000,
            N'Tabele aplikacji już istnieją. Nie uruchamiaj schema.sql na istniejącej instalacji.',
            1;
    END;


    /* ========================================================
       ROLE
    ======================================================== */

    CREATE TABLE app.Role
    (
        RolaId tinyint NOT NULL,

        Nazwa nvarchar(20) NOT NULL,

        CONSTRAINT PK_Role
            PRIMARY KEY CLUSTERED
            (
                RolaId
            ),

        CONSTRAINT UQ_Role_Nazwa
            UNIQUE NONCLUSTERED
            (
                Nazwa
            ),

        CONSTRAINT CK_Role_Nazwa
            CHECK
            (
                Nazwa IN
                (
                    N'USER',
                    N'ADMIN'
                )
            )
    );


    /*
     * Identyfikatory ról są stałe,
     * ponieważ korzysta z nich logika aplikacji.
     */
    INSERT INTO app.Role
    (
        RolaId,
        Nazwa
    )
    VALUES
        (1, N'ADMIN'),
        (2, N'USER');


    /* ========================================================
       SKLEPY
    ======================================================== */

    CREATE TABLE app.Sklepy
    (
        SklepId int
            IDENTITY(1, 1)
            NOT NULL,

        Nazwa nvarchar(120)
            NOT NULL,

        Miasto nvarchar(100)
            NULL,

        Ulica nvarchar(120)
            NULL,

        NumerBudynku nvarchar(20)
            NULL,

        Telefon varchar(20)
            NULL,

        CzyAktywny bit
            NOT NULL
            CONSTRAINT DF_Sklepy_CzyAktywny
            DEFAULT (1),

        UtworzonoUtc datetime2(0)
            NOT NULL
            CONSTRAINT DF_Sklepy_UtworzonoUtc
            DEFAULT (sysutcdatetime()),

        CONSTRAINT PK_Sklepy
            PRIMARY KEY CLUSTERED
            (
                SklepId
            ),

        CONSTRAINT CK_Sklepy_Nazwa_NotBlank
            CHECK
            (
                LEN(
                    LTRIM(
                        RTRIM(Nazwa)
                    )
                ) > 0
            )
    );


    /* ========================================================
       UŻYTKOWNICY
    ======================================================== */

    CREATE TABLE app.Uzytkownicy
    (
        UzytkownikId int
            IDENTITY(1, 1)
            NOT NULL,

        RolaId tinyint
            NOT NULL,

        Login nvarchar(50)
            NOT NULL,

        HasloHash varchar(255)
            NOT NULL,

        Imie nvarchar(50)
            NOT NULL,

        Nazwisko nvarchar(80)
            NOT NULL,

        Email nvarchar(254)
            NOT NULL,

        Telefon varchar(20)
            NULL,

        CzyAktywny bit
            NOT NULL
            CONSTRAINT DF_Uzytkownicy_CzyAktywny
            DEFAULT (1),

        UtworzonoUtc datetime2(0)
            NOT NULL
            CONSTRAINT DF_Uzytkownicy_UtworzonoUtc
            DEFAULT (sysutcdatetime()),

        SesjaWersja int
            NOT NULL
            CONSTRAINT DF_Uzytkownicy_SesjaWersja
            DEFAULT (1),

        CONSTRAINT PK_Uzytkownicy
            PRIMARY KEY CLUSTERED
            (
                UzytkownikId
            ),

        CONSTRAINT UQ_Uzytkownicy_Login
            UNIQUE NONCLUSTERED
            (
                Login
            ),

        CONSTRAINT UQ_Uzytkownicy_Email
            UNIQUE NONCLUSTERED
            (
                Email
            ),

        CONSTRAINT FK_Uzytkownicy_Role
            FOREIGN KEY
            (
                RolaId
            )
            REFERENCES app.Role
            (
                RolaId
            ),

        CONSTRAINT CK_Uzytkownicy_Login_NotBlank
            CHECK
            (
                LEN(
                    LTRIM(
                        RTRIM(Login)
                    )
                ) >= 3
            ),

        CONSTRAINT CK_Uzytkownicy_Imie_NotBlank
            CHECK
            (
                LEN(
                    LTRIM(
                        RTRIM(Imie)
                    )
                ) > 0
            ),

        CONSTRAINT CK_Uzytkownicy_Nazwisko_NotBlank
            CHECK
            (
                LEN(
                    LTRIM(
                        RTRIM(Nazwisko)
                    )
                ) > 0
            ),

        CONSTRAINT CK_Uzytkownicy_Email_NotBlank
            CHECK
            (
                LEN(
                    LTRIM(
                        RTRIM(Email)
                    )
                ) > 0
            ),

        CONSTRAINT CK_Uzytkownicy_SesjaWersja
            CHECK
            (
                SesjaWersja >= 1
            )
    );


    /* ========================================================
       WYDATKI
    ======================================================== */

    CREATE TABLE app.Wydatki
    (
        WydatekId int
            IDENTITY(1, 1)
            NOT NULL,

        UzytkownikId int
            NOT NULL,

        SklepId int
            NOT NULL,

        DataWydatku date
            NOT NULL,

        Kwota decimal(12, 2)
            NOT NULL,

        Opis nvarchar(500)
            NULL,

        UtworzonoUtc datetime2(0)
            NOT NULL
            CONSTRAINT DF_Wydatki_UtworzonoUtc
            DEFAULT (sysutcdatetime()),

        ZmienionoUtc datetime2(0)
            NULL,

        CONSTRAINT PK_Wydatki
            PRIMARY KEY CLUSTERED
            (
                WydatekId
            ),

        CONSTRAINT FK_Wydatki_Uzytkownicy
            FOREIGN KEY
            (
                UzytkownikId
            )
            REFERENCES app.Uzytkownicy
            (
                UzytkownikId
            ),

        CONSTRAINT FK_Wydatki_Sklepy
            FOREIGN KEY
            (
                SklepId
            )
            REFERENCES app.Sklepy
            (
                SklepId
            ),

        CONSTRAINT CK_Wydatki_Kwota_Dodatnia
            CHECK
            (
                Kwota > 0
            ),

        CONSTRAINT CK_Wydatki_Opis_NotBlank
            CHECK
            (
                Opis IS NULL
                OR LEN(
                    LTRIM(
                        RTRIM(Opis)
                    )
                ) > 0
            )
    );


    /* ========================================================
       INDEKSY WYDATKÓW
    ======================================================== */

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


    /* ========================================================
       ROLA RUNTIME APLIKACJI
    ======================================================== */

    IF DATABASE_PRINCIPAL_ID(
        N'app_runtime'
    ) IS NULL
    BEGIN
        CREATE ROLE app_runtime
            AUTHORIZATION dbo;
    END;


    /*
     * ROLE
     */
    GRANT SELECT
        ON OBJECT::app.Role
        TO app_runtime;


    /*
     * UŻYTKOWNICY
     *
     * Obecne repozytorium aplikacji korzysta
     * bezpośrednio z SELECT / INSERT / UPDATE.
     */
    GRANT SELECT, INSERT, UPDATE
        ON OBJECT::app.Uzytkownicy
        TO app_runtime;


    /*
     * SKLEPY
     */
    GRANT SELECT, INSERT, UPDATE
        ON OBJECT::app.Sklepy
        TO app_runtime;


    /*
     * WYDATKI
     *
     * Bezpośrednio dostępny jest wyłącznie SELECT.
     * INSERT / UPDATE / DELETE przechodzą przez
     * procedury składowane.
     */
    GRANT SELECT
        ON OBJECT::app.Wydatki
        TO app_runtime;

    DENY INSERT
        ON OBJECT::app.Wydatki
        TO app_runtime;

    DENY UPDATE
        ON OBJECT::app.Wydatki
        TO app_runtime;

    DENY DELETE
        ON OBJECT::app.Wydatki
        TO app_runtime;


    COMMIT TRANSACTION;
END TRY
BEGIN CATCH
    IF @@TRANCOUNT > 0
    BEGIN
        ROLLBACK TRANSACTION;
    END;

    THROW;
END CATCH;
GO


/* ============================================================
   PROCEDURA: WYDATEK_DODAJ
============================================================ */

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

        SELECT
            @AktorRola = r.Nazwa
        FROM app.Uzytkownicy AS u
            WITH (UPDLOCK, HOLDLOCK)
        INNER JOIN app.Role AS r
            ON r.RolaId = u.RolaId
        WHERE u.UzytkownikId =
            @AktorUzytkownikId
          AND u.CzyAktywny = 1;

        IF @AktorRola IS NULL
        BEGIN
            THROW 50001,
                N'Konto wykonujące operację nie jest aktywne.',
                1;
        END;

        IF @AktorRola <> N'ADMIN'
           AND @AktorUzytkownikId
               <> @WlascicielUzytkownikId
        BEGIN
            THROW 50002,
                N'Brak uprawnień do wskazanego użytkownika.',
                1;
        END;

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

GRANT EXECUTE
    ON OBJECT::app.Wydatek_Dodaj
    TO app_runtime;
GO


/* ============================================================
   PROCEDURA: WYDATEK_EDYTUJ
============================================================ */

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
        WHERE actor.UzytkownikId =
            @AktorUzytkownikId
          AND actor.CzyAktywny = 1;

        IF @AktorRola IS NULL
        BEGIN
            THROW 50102,
                N'Konto wykonujące operację nie jest aktywne.',
                1;
        END;

        DECLARE @BiezacyWlascicielId INT;
        DECLARE @BiezacySklepId INT;

        SELECT
            @BiezacyWlascicielId =
                w.UzytkownikId,

            @BiezacySklepId =
                w.SklepId

        FROM app.Wydatki AS w
            WITH (UPDLOCK, HOLDLOCK)

        WHERE w.WydatekId =
            @WydatekId;

        IF @BiezacyWlascicielId IS NULL
        BEGIN
            THROW 50103,
                N'Wydatek nie istnieje.',
                1;
        END;

        IF @AktorRola <> N'ADMIN'
           AND @BiezacyWlascicielId
               <> @AktorUzytkownikId
        BEGIN
            THROW 50104,
                N'Brak uprawnień do edycji tego wydatku.',
                1;
        END;

        IF @AktorRola <> N'ADMIN'
           AND @WlascicielUzytkownikId
               <> @AktorUzytkownikId
        BEGIN
            THROW 50104,
                N'Brak uprawnień do zmiany właściciela wydatku.',
                1;
        END;

        IF @WlascicielUzytkownikId
            <> @BiezacyWlascicielId
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

        IF @SklepId <> @BiezacySklepId
        BEGIN
            IF NOT EXISTS
            (
                SELECT 1
                FROM app.Sklepy AS newStore
                    WITH (UPDLOCK, HOLDLOCK)
                WHERE newStore.SklepId =
                    @SklepId
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
            UzytkownikId =
                @WlascicielUzytkownikId,

            SklepId =
                @SklepId,

            DataWydatku =
                @DataWydatku,

            Kwota =
                @Kwota,

            Opis =
                @Opis,

            ZmienionoUtc =
                CONVERT(
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

        WHERE w.WydatekId =
            @WydatekId;

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


/* ============================================================
   PROCEDURA: WYDATEK_USUN
============================================================ */

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

        SELECT
            @AktorRola = r.Nazwa
        FROM app.Uzytkownicy AS actor
            WITH (UPDLOCK, HOLDLOCK)
        INNER JOIN app.Role AS r
            ON r.RolaId = actor.RolaId
        WHERE actor.UzytkownikId =
            @AktorUzytkownikId
          AND actor.CzyAktywny = 1;

        IF @AktorRola IS NULL
        BEGIN
            THROW 50201,
                N'Konto wykonujące operację nie jest aktywne.',
                1;
        END;

        DECLARE @WlascicielUzytkownikId INT;

        SELECT
            @WlascicielUzytkownikId =
                w.UzytkownikId
        FROM app.Wydatki AS w
            WITH (UPDLOCK, HOLDLOCK)
        WHERE w.WydatekId =
            @WydatekId;

        IF @WlascicielUzytkownikId IS NULL
        BEGIN
            THROW 50202,
                N'Wydatek nie istnieje.',
                1;
        END;

        IF @AktorRola <> N'ADMIN'
           AND @WlascicielUzytkownikId
               <> @AktorUzytkownikId
        BEGIN
            THROW 50203,
                N'Brak uprawnień do usunięcia tego wydatku.',
                1;
        END;

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
        WHERE WydatekId =
            @WydatekId;

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