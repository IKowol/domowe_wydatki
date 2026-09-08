USE [domowe_wydatki_update];
GO

IF COL_LENGTH(
    N'app.Uzytkownicy',
    N'SesjaWersja'
) IS NULL
BEGIN
    ALTER TABLE app.Uzytkownicy
    ADD SesjaWersja INT
        NOT NULL
        CONSTRAINT DF_Uzytkownicy_SesjaWersja
        DEFAULT (1);
END;
GO

IF NOT EXISTS
(
    SELECT 1
    FROM sys.check_constraints
    WHERE name = N'CK_Uzytkownicy_SesjaWersja'
)
BEGIN
    ALTER TABLE app.Uzytkownicy
    ADD CONSTRAINT CK_Uzytkownicy_SesjaWersja
        CHECK (SesjaWersja >= 1);
END;
GO