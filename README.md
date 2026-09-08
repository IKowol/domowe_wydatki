# Home Expenses

A web application for managing household expenses, built with **PHP 8.3** and **Microsoft SQL Server**.

The project was created as a portfolio application with a focus on backend architecture, relational database design, authorization, security, data integrity and reporting.

The user interface is currently available in Polish.

---

## Features

### Authentication and authorization

- Secure login with password hashing
- Session-based authentication
- Role-based access control:
  - `ADMIN`
  - `USER`
- Automatic session invalidation after:
  - password change
  - administrator password reset
  - account deactivation
- Protection against unauthorized access to other users' resources

### User management

Administrators can:

- create users
- edit user profiles
- change roles
- activate and deactivate accounts
- reset user passwords

Users can:

- edit their own profile
- change their own password

The system protects against removing or deactivating the last active administrator.

### Store management

- Create stores
- Edit store information
- Activate and deactivate stores
- Preserve inactive stores in historical expense data

### Expense management

Full expense CRUD:

- create
- list
- filter
- sort
- paginate
- edit
- delete

Authorization rules are enforced both in PHP and at the database level.

A regular user can manage only their own expenses.

An administrator can manage expenses for all users.

### Reports

The reporting module includes:

- current month total
- number of expenses
- average expense
- largest expense
- comparison with the previous month
- month-to-month percentage change
- TOP 5 stores
- store spending share
- six-month spending trend
- TOP users for administrators

### Dashboard

The dashboard provides:

- current month spending summary
- expense count
- month-to-month change
- five most recent expenses
- role-specific quick actions

---

## Technology stack

- **PHP 8.3**
- **Microsoft SQL Server**
- **SQLSRV PHP extension**
- **HTML5**
- **CSS3**
- **Composer**
- **vlucas/phpdotenv**

The application does not use a PHP framework.

---

## Architecture

The application is divided into several layers:

```text
config/
    application bootstrap
    database connection
    session configuration

public/
    HTTP entry points
    forms
    views
    CSS assets

src/
    Auth/
    Database/
    Expense/
    Report/
    Security/
    Store/
    Support/
    User/

database/
    schema.sql
    migrations/
    procedures/

scripts/
    create-admin.php

storage/
    logs/
```

The web server document root should always point to:

```text
public/
```

This prevents configuration files, application source code, logs and environment variables from being directly accessible through HTTP.

---

## Database model

The main database schema is:

```text
app
```

It contains four domain tables:

```text
Role
Uzytkownicy
Sklepy
Wydatki
```

Relationships:

```text
Role
  1
  |
  N
Uzytkownicy
  1
  |
  N
Wydatki
  N
  |
  1
Sklepy
```

The schema uses:

- primary keys
- foreign keys
- unique constraints
- check constraints
- default constraints
- indexes
- stored procedures

The model is normalized to **Third Normal Form (3NF)**.

---

## Database security

The application uses a dedicated SQL Server login instead of an administrative database account.

The database role:

```text
app_runtime
```

has limited permissions.

For the `Wydatki` table:

```text
SELECT  -> allowed
INSERT  -> denied
UPDATE  -> denied
DELETE  -> denied
```

Expense modifications are performed through stored procedures:

```text
app.Wydatek_Dodaj
app.Wydatek_Edytuj
app.Wydatek_Usun
```

The procedures enforce authorization and business rules before modifying data.

---

## Security measures

The application includes:

- parameterized SQL queries
- CSRF protection
- output escaping against XSS
- authorization checks
- IDOR protection
- password hashing with `password_hash()`
- password verification with `password_verify()`
- session ID regeneration
- session versioning
- secure session cookie configuration
- server-side validation
- database constraints
- database-level authorization
- application error logging
- disabled error display in HTTP responses
- secrets stored outside the repository in `.env`

Detailed technical errors are written to:

```text
storage/logs/app.log
```

and are not intentionally exposed to application users.

---

# Installation

## Requirements

The project requires:

- PHP 8.3+
- Microsoft SQL Server
- SQL Server Authentication enabled
- Composer
- PHP extension:
  - `sqlsrv`
  - `mbstring`

The development version was built using SQL Server Express on Windows.

---

## 1. Clone the repository

```bash
git clone <repository-url>
cd domowe-wydatki
```

---

## 2. Install PHP dependencies

```bash
composer install
```

Composer verifies that the required PHP version and PHP extensions are available.

---

## 3. Create the database

Create an empty SQL Server database, for example:

```sql
CREATE DATABASE domowe_wydatki;
GO
```

Select the newly created database and execute:

```text
database/schema.sql
```

The script creates the complete current database structure, including:

- schema `app`
- tables
- constraints
- indexes
- application database role
- stored procedures
- base `ADMIN` and `USER` roles

### Important

Files inside:

```text
database/migrations/
```

represent the development history of an existing database.

They should **not** be executed after `schema.sql` during a fresh installation because the final schema already contains those changes.

Files inside:

```text
database/procedures/
```

contain standalone versions of the stored procedures for maintenance and development.

The procedures are already included in `schema.sql`.

---

## 4. Create the SQL Server application login

Create a dedicated SQL Server login.

Example:

```sql
USE master;
GO

CREATE LOGIN domowe_wydatki_app
WITH PASSWORD = N'REPLACE_WITH_A_STRONG_PASSWORD',
     CHECK_POLICY = ON;
GO
```

Switch to the application database:

```sql
USE domowe_wydatki;
GO

CREATE USER domowe_wydatki_app
FOR LOGIN domowe_wydatki_app;
GO

ALTER ROLE app_runtime
ADD MEMBER domowe_wydatki_app;
GO

GRANT CONNECT
TO domowe_wydatki_app;
GO
```

Do not use an administrative SQL Server account as the application's database user.

---

## 5. Configure environment variables

Copy:

```text
.env.example
```

to:

```text
.env
```

Example configuration:

```dotenv
APP_ENV=development
APP_DEBUG=false

DB_SERVER=YOUR_SERVER\SQLEXPRESS
DB_DATABASE=domowe_wydatki
DB_USERNAME=domowe_wydatki_app
DB_PASSWORD=YOUR_PASSWORD

DB_ENCRYPT=true
DB_TRUST_SERVER_CERTIFICATE=true
```

`DB_TRUST_SERVER_CERTIFICATE=true` is convenient for a local development environment using SQL Server Express.

For a production environment with a properly configured trusted certificate, this should normally be set to:

```dotenv
DB_TRUST_SERVER_CERTIFICATE=false
```

The `.env` file is ignored by Git and must never be committed.

---

## 6. Create the first administrator

Run:

```bash
php scripts/create-admin.php
```

Follow the prompts displayed by the script.

The initial administrator is created with a securely hashed password.

Once an administrator exists, additional users can be created from the application interface.

---

## 7. Start the development server

From the project root:

```bash
php -S 127.0.0.1:8000 -t public
```

Open:

```text
http://127.0.0.1:8000
```

The important part is:

```text
-t public
```

The project root itself should not be exposed as the web document root.

---

# Development

## PHP syntax check

Example for checking all application PHP files in PowerShell:

```powershell
Get-ChildItem `
    ".\config", `
    ".\public", `
    ".\scripts", `
    ".\src" `
    -Recurse `
    -Filter "*.php" |
ForEach-Object {
    php -l $_.FullName
}
```

---

## Composer validation

```bash
composer validate --strict
```

---

## Database migrations

The `database/migrations` directory documents changes that were applied while developing the application.

Current migrations include:

```text
001_add_session_version.sql
002_add_expense_indexes.sql
```

For a new installation, use:

```text
database/schema.sql
```

instead of manually applying historical migrations.

---

# Authorization model

## USER

A regular user can:

- log in
- edit their own profile
- change their own password
- create their own expenses
- view their own expenses
- edit their own expenses
- delete their own expenses
- view their own reports
- view active stores

A regular user cannot access another user's expense by manually modifying an ID in the URL or form request.

---

## ADMIN

An administrator can additionally:

- manage users
- create users
- change user roles
- activate and deactivate users
- reset passwords
- manage stores
- view all expenses
- create and edit expenses assigned to other users
- view household-wide reports

Critical authorization rules are checked server-side and are not based only on whether a button is visible in the interface.

---

# Data integrity

The database protects important business rules independently from PHP.

Examples include:

- role restricted to `ADMIN` or `USER`
- unique login
- unique email
- positive expense amount
- non-empty required text values
- valid foreign key relationships
- session version greater than or equal to 1

Foreign keys use `NO ACTION` instead of cascading deletion to protect historical financial data.

---

# Performance

The expense table includes dedicated nonclustered indexes for common application query patterns:

```text
IX_Wydatki_Uzytkownik_Data
IX_Wydatki_Data
IX_Wydatki_Sklep_Data
```

They support:

- user expense history
- date-range filtering
- dashboards
- monthly reports
- store-based filtering and reporting

The indexes include selected non-key columns to reduce additional lookups for common queries.

---

# Project status

The application currently includes:

- authentication
- authorization
- user management
- store management
- complete expense CRUD
- password management
- session invalidation
- dashboard
- reporting
- database hardening
- application logging
- database indexing
- reproducible database schema

---

## License

This project is currently marked as:

```text
proprietary
```

in `composer.json`.

It is intended primarily as a portfolio project.