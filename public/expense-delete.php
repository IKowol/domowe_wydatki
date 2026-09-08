<?php

declare(strict_types=1);

require_once dirname(__DIR__)
    . '/src/Auth/auth.php';

require_once dirname(__DIR__)
    . '/src/Expense/ExpenseRepository.php';

$user = app_require_auth();

header(
    'Cache-Control: no-store, no-cache, '
    . 'must-revalidate, max-age=0'
);

header('Pragma: no-cache');

$method = strtoupper(
    (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')
);

if (!in_array($method, ['GET', 'POST'], true)) {
    header('Allow: GET, POST');

    http_response_code(405);

    echo 'Niedozwolona metoda żądania.';
    exit;
}

$actorUserId = (int) $user['user_id'];
$isAdmin = (string) $user['role'] === 'ADMIN';

/*
 * GET:
 *     ID pochodzi z adresu i służy tylko
 *     do wyświetlenia ekranu potwierdzenia.
 *
 * POST:
 *     ID pochodzi z formularza.
 *
 * Obie wartości są niezaufane.
 */
$rawExpenseId = $method === 'POST'
    ? ($_POST['expense_id'] ?? null)
    : ($_GET['id'] ?? null);

$expenseId = filter_var(
    $rawExpenseId,
    FILTER_VALIDATE_INT,
    [
        'options' => [
            'min_range' => 1,
        ],
    ]
);

if (!is_int($expenseId)) {
    http_response_code(400);

    echo 'Nieprawidłowy identyfikator wydatku.';
    exit;
}

/*
 * Właściwe usunięcie wykonujemy WYŁĄCZNIE
 * dla żądania POST.
 */
if ($method === 'POST') {
    if (
        !app_verify_csrf_token(
            $_POST['csrf_token'] ?? null
        )
    ) {
        app_flash(
            'error',
            'Sesja formularza wygasła. '
            . 'Wydatek nie został usunięty.'
        );

        app_redirect(
            '/expense-delete.php?id='
            . $expenseId
        );
    }

    $connection = null;
    $deletedExpense = null;

    try {
        $connection = require dirname(__DIR__)
            . '/config/database.php';

        $repository = new ExpenseRepository(
            $connection
        );

        $deletedExpense = $repository->delete(
            $actorUserId,
            $expenseId
        );
    } catch (
        ExpenseDeleteException $exception
    ) {
        switch ($exception->reason()) {
            case ExpenseDeleteException::ACTOR_INACTIVE:
                app_logout_user();

                app_redirect(
                    '/login.php?revoked=1'
                );

            case ExpenseDeleteException::NOT_FOUND:
                http_response_code(404);

                echo 'Wydatek nie istnieje.';
                exit;

            case ExpenseDeleteException::FORBIDDEN:
                http_response_code(403);

                echo 'Nie masz uprawnień do '
                    . 'usunięcia tego wydatku.';
                exit;
        }
    } catch (Throwable $exception) {
        error_log(
            'Usuwanie wydatku: '
            . $exception->getMessage()
        );

        app_flash(
            'error',
            'Nie udało się usunąć wydatku. '
            . 'Spróbuj ponownie.'
        );

        app_redirect(
            '/expense-delete.php?id='
            . $expenseId
        );
    } finally {
        if (is_resource($connection)) {
            sqlsrv_close($connection);
        }
    }

    if (!is_array($deletedExpense)) {
        http_response_code(500);

        echo 'Nie udało się potwierdzić usunięcia wydatku.';
        exit;
    }

    app_flash(
        'success',
        'Wydatek ID '
        . (int) $deletedExpense['WydatekId']
        . ' został usunięty.'
    );

    app_redirect('/expenses.php');
}

/*
 * GET — przygotowanie ekranu potwierdzenia.
 */
$connection = null;
$expense = null;

try {
    $connection = require dirname(__DIR__)
        . '/config/database.php';

    $repository = new ExpenseRepository(
        $connection
    );

    $expense = $repository->findDeletableById(
        $expenseId,
        $actorUserId
    );
} catch (Throwable $exception) {
    error_log(
        'Pobieranie wydatku przed usunięciem: '
        . $exception->getMessage()
    );

    http_response_code(500);

    echo 'Nie udało się pobrać danych wydatku.';
    exit;
} finally {
    if (is_resource($connection)) {
        sqlsrv_close($connection);
    }
}

if (!is_array($expense)) {
    /*
     * Nie ujawniamy, czy rekord istnieje,
     * ale należy do innego użytkownika.
     */
    http_response_code(404);

    echo 'Nie znaleziono wydatku albo nie masz do niego dostępu.';
    exit;
}

$errorMessages = app_consume_flash(
    'error'
);

$csrfToken = app_csrf_token();

$ownerName = trim(
    (string) $expense['UzytkownikImie']
    . ' '
    . (string) $expense['UzytkownikNazwisko']
);

$storeName = (string)
    $expense['SklepNazwa'];

if ($expense['SklepMiasto'] !== null) {
    $storeName .= ' — '
        . (string) $expense['SklepMiasto'];
}

$amount = str_replace(
    '.',
    ',',
    (string) $expense['KwotaTekst']
) . ' zł';

?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1"
    >

    <title>
        Usuń wydatek — Domowe wydatki
    </title>

    <link
        rel="stylesheet"
        href="/assets/css/app.css"
    >
</head>
<body>
<header class="topbar">
    <a
        href="/dashboard.php"
        class="brand-link"
    >
        Domowe wydatki
    </a>

    <div class="topbar-actions">
        <span>
            <?= app_e($user['display_name']) ?>
        </span>

        <a
            href="/expenses.php"
            class="button-link button-secondary"
        >
            Wydatki
        </a>
    </div>
</header>

<main class="container">
    <section class="card form-card">
        <div class="section-header">
            <div>
                <h1>Usuń wydatek</h1>

                <p class="muted">
                    ID: <?= $expenseId ?>
                </p>
            </div>
        </div>

        <?php foreach ($errorMessages as $message): ?>
            <div class="alert alert-error">
                <?= app_e($message) ?>
            </div>
        <?php endforeach; ?>

        <div class="alert alert-warning">
            Ta operacja trwale usunie wydatek z bazy.
            Nie można jej cofnąć z poziomu aplikacji.
        </div>

        <div class="delete-summary">
            <dl>
                <div>
                    <dt>Data</dt>

                    <dd>
                        <?= app_e(
                            $expense['DataWydatkuTekst']
                        ) ?>
                    </dd>
                </div>

                <?php if ($isAdmin): ?>
                    <div>
                        <dt>Użytkownik</dt>

                        <dd>
                            <?= app_e($ownerName) ?>
                            —
                            <?= app_e(
                                $expense['UzytkownikLogin']
                            ) ?>
                        </dd>
                    </div>
                <?php endif; ?>

                <div>
                    <dt>Sklep</dt>

                    <dd>
                        <?= app_e($storeName) ?>
                    </dd>
                </div>

                <div>
                    <dt>Kwota</dt>

                    <dd>
                        <strong>
                            <?= app_e($amount) ?>
                        </strong>
                    </dd>
                </div>

                <div>
                    <dt>Opis</dt>

                    <dd>
                        <?= $expense['Opis'] !== null
                            ? app_e($expense['Opis'])
                            : '—'
                        ?>
                    </dd>
                </div>
            </dl>
        </div>

        <form
            method="post"
            action="/expense-delete.php"
            class="form"
        >
            <input
                type="hidden"
                name="csrf_token"
                value="<?= app_e($csrfToken) ?>"
            >

            <input
                type="hidden"
                name="expense_id"
                value="<?= $expenseId ?>"
            >

            <div class="form-actions">
                <a
                    href="/expenses.php"
                    class="button-link button-secondary"
                >
                    Anuluj
                </a>

                <button
                    type="submit"
                    class="button-danger"
                >
                    Usuń wydatek
                </button>
            </div>
        </form>
    </section>
</main>
</body>
</html>