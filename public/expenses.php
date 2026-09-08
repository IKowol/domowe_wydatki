<?php

declare(strict_types=1);

require_once dirname(__DIR__)
    . '/src/Auth/auth.php';

require_once dirname(__DIR__)
    . '/src/Support/pagination.php';

require_once dirname(__DIR__)
    . '/src/Expense/expense-filters.php';

require_once dirname(__DIR__)
    . '/src/Expense/ExpenseRepository.php';

$user = app_require_auth();

header(
    'Cache-Control: no-store, no-cache, '
    . 'must-revalidate, max-age=0'
);

header('Pragma: no-cache');

$actorUserId = (int) $user['user_id'];
$isAdmin = (string) $user['role'] === 'ADMIN';

$page = app_query_positive_int('page', 1);
$perPage = 20;

$filterResult = app_validate_expense_filters(
    $_GET,
    $isAdmin
);

$filters = $filterResult['filters'];
$filterValues = $filterResult['old_input'];
$filterErrors = $filterResult['errors'];
$sort = $filterResult['sort'];

$connection = null;
$expenses = [];
$filterStores = [];
$filterUsers = [];
$totalRows = 0;

try {
    $connection = require dirname(__DIR__)
        . '/config/database.php';

    $repository = new ExpenseRepository(
        $connection
    );

    $result = $repository->getPaginated(
        $actorUserId,
        $isAdmin,
        $filters,
        $sort,
        $page,
        $perPage
    );

    $expenses = $result['expenses'];
    $totalRows = $result['total_rows'];

    $filterStores = $repository->getFilterStores(
        $actorUserId,
        $isAdmin
    );

    if ($isAdmin) {
        $filterUsers = $repository->getFilterUsers(
            $actorUserId
        );
    }
} catch (Throwable $exception) {
    error_log(
        'Lista wydatków: '
        . $exception->getMessage()
    );

    http_response_code(500);

    echo 'Nie udało się wyświetlić listy wydatków.';
    exit;
} finally {
    if (is_resource($connection)) {
        sqlsrv_close($connection);
    }
}

$totalPages = app_total_pages(
    $totalRows,
    $perPage
);

$queryState = [
    'owner_user_id' =>
        $filterValues['owner_user_id'],

    'store_id' =>
        $filterValues['store_id'],

    'date_from' =>
        $filterValues['date_from'],

    'date_to' =>
        $filterValues['date_to'],

    'amount_min' =>
        $filterValues['amount_min'],

    'amount_max' =>
        $filterValues['amount_max'],

    'sort' => $sort,
];

$buildExpensesUrl = static function (
    array $overrides = []
) use ($queryState): string {
    $parameters = array_merge(
        $queryState,
        $overrides
    );

    foreach ($parameters as $key => $value) {
        if ($value === '' || $value === null) {
            unset($parameters[$key]);
        }
    }

    $query = http_build_query($parameters);

    return '/expenses.php'
        . ($query !== '' ? '?' . $query : '');
};

if ($page > $totalPages) {
    app_redirect(
        $buildExpensesUrl([
            'page' => $totalPages,
        ]),
        302
    );
}

$successMessages = app_consume_flash(
    'success'
);

$errorMessages = app_consume_flash(
    'error'
);

$formatDate = static function (
    mixed $value,
    string $format
): string {
    if ($value instanceof DateTimeInterface) {
        return $value->format($format);
    }

    return (string) $value;
};

$formatAmount = static function (
    mixed $value
): string {
    $raw = str_replace(
        ',',
        '.',
        trim((string) $value)
    );

    if (
        preg_match(
            '/\A(\d+)(?:\.(\d+))?\z/',
            $raw,
            $matches
        ) !== 1
    ) {
        return (string) $value;
    }

    $wholePart = preg_replace(
        '/\B(?=(\d{3})+(?!\d))/',
        ' ',
        $matches[1]
    );

    $fractionPart = str_pad(
        substr($matches[2] ?? '', 0, 2),
        2,
        '0'
    );

    return $wholePart
        . ','
        . $fractionPart
        . ' zł';
};

?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1"
    >

    <title>Wydatki — Domowe wydatki</title>

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
            href="/dashboard.php"
            class="button-link button-secondary"
        >
            Panel
        </a>
    </div>
</header>

<main class="container">
    <section class="card">
        <div class="section-header">
            <div>
                <h1>Wydatki</h1>

                <p class="muted">
                    Liczba znalezionych wydatków:
                    <?= $totalRows ?>
                </p>
            </div>

            <a
                href="/expense-create.php"
                class="button-link"
            >
                Dodaj wydatek
            </a>
        </div>

        <?php foreach ($successMessages as $message): ?>
            <div class="alert alert-success">
                <?= app_e($message) ?>
            </div>
        <?php endforeach; ?>

        <?php foreach ($errorMessages as $message): ?>
            <div class="alert alert-error">
                <?= app_e($message) ?>
            </div>
        <?php endforeach; ?>

        <?php if ($filterErrors !== []): ?>
            <div class="alert alert-warning">
                <strong>
                    Niektóre filtry zostały pominięte:
                </strong>

                <ul>
                    <?php foreach (
                        $filterErrors as $message
                    ): ?>
                        <li>
                            <?= app_e($message) ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form
            method="get"
            action="/expenses.php"
            class="filter-form"
        >
            <div class="form-grid">
                <?php if ($isAdmin): ?>
                    <div class="form-field">
                        <label for="owner_user_id">
                            Użytkownik
                        </label>

                        <select
                            id="owner_user_id"
                            name="owner_user_id"
                        >
                            <option value="">
                                Wszyscy użytkownicy
                            </option>

                            <?php foreach (
                                $filterUsers as $filterUser
                            ): ?>
                                <?php
                                $filterUserId = (int)
                                    $filterUser['UzytkownikId'];

                                $filterUserLabel = trim(
                                    (string) $filterUser['Imie']
                                    . ' '
                                    . (string) $filterUser['Nazwisko']
                                );

                                $filterUserLabel .= ' — '
                                    . $filterUser['Login'];

                                if (
                                    (int) $filterUser['CzyAktywny']
                                    !== 1
                                ) {
                                    $filterUserLabel .=
                                        ' [nieaktywny]';
                                }
                                ?>

                                <option
                                    value="<?= $filterUserId ?>"
                                    <?= $filterValues[
                                        'owner_user_id'
                                    ] === (string) $filterUserId
                                        ? 'selected'
                                        : ''
                                    ?>
                                >
                                    <?= app_e($filterUserLabel) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>

                <div class="form-field">
                    <label for="store_id">
                        Sklep
                    </label>

                    <select
                        id="store_id"
                        name="store_id"
                    >
                        <option value="">
                            Wszystkie sklepy
                        </option>

                        <?php foreach (
                            $filterStores as $filterStore
                        ): ?>
                            <?php
                            $filterStoreId = (int)
                                $filterStore['SklepId'];

                            $filterStoreLabel = (string)
                                $filterStore['Nazwa'];

                            if (
                                $filterStore['Miasto'] !== null
                            ) {
                                $filterStoreLabel .= ' — '
                                    . $filterStore['Miasto'];
                            }

                            if (
                                (int) $filterStore['CzyAktywny']
                                !== 1
                            ) {
                                $filterStoreLabel .=
                                    ' [nieaktywny]';
                            }
                            ?>

                            <option
                                value="<?= $filterStoreId ?>"
                                <?= $filterValues['store_id']
                                    === (string) $filterStoreId
                                    ? 'selected'
                                    : ''
                                ?>
                            >
                                <?= app_e($filterStoreLabel) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-field">
                    <label for="date_from">
                        Data od
                    </label>

                    <input
                        id="date_from"
                        name="date_from"
                        type="date"
                        value="<?= app_e(
                            $filterValues['date_from']
                        ) ?>"
                    >
                </div>

                <div class="form-field">
                    <label for="date_to">
                        Data do
                    </label>

                    <input
                        id="date_to"
                        name="date_to"
                        type="date"
                        value="<?= app_e(
                            $filterValues['date_to']
                        ) ?>"
                    >
                </div>

                <div class="form-field">
                    <label for="amount_min">
                        Kwota od
                    </label>

                    <input
                        id="amount_min"
                        name="amount_min"
                        type="text"
                        inputmode="decimal"
                        placeholder="0,00"
                        value="<?= app_e(
                            $filterValues['amount_min']
                        ) ?>"
                    >
                </div>

                <div class="form-field">
                    <label for="amount_max">
                        Kwota do
                    </label>

                    <input
                        id="amount_max"
                        name="amount_max"
                        type="text"
                        inputmode="decimal"
                        placeholder="0,00"
                        value="<?= app_e(
                            $filterValues['amount_max']
                        ) ?>"
                    >
                </div>

                <div class="form-field">
                    <label for="sort">
                        Sortowanie
                    </label>

                    <select id="sort" name="sort">
                        <option
                            value="date_desc"
                            <?= $sort === 'date_desc'
                                ? 'selected'
                                : ''
                            ?>
                        >
                            Data: od najnowszych
                        </option>

                        <option
                            value="date_asc"
                            <?= $sort === 'date_asc'
                                ? 'selected'
                                : ''
                            ?>
                        >
                            Data: od najstarszych
                        </option>

                        <option
                            value="amount_desc"
                            <?= $sort === 'amount_desc'
                                ? 'selected'
                                : ''
                            ?>
                        >
                            Kwota: malejąco
                        </option>

                        <option
                            value="amount_asc"
                            <?= $sort === 'amount_asc'
                                ? 'selected'
                                : ''
                            ?>
                        >
                            Kwota: rosnąco
                        </option>

                        <option
                            value="created_desc"
                            <?= $sort === 'created_desc'
                                ? 'selected'
                                : ''
                            ?>
                        >
                            Dodano: od najnowszych
                        </option>

                        <option
                            value="created_asc"
                            <?= $sort === 'created_asc'
                                ? 'selected'
                                : ''
                            ?>
                        >
                            Dodano: od najstarszych
                        </option>
                    </select>
                </div>
            </div>

            <div class="filter-actions">
                <a
                    href="/expenses.php"
                    class="button-link button-secondary"
                >
                    Wyczyść filtry
                </a>

                <button type="submit">
                    Filtruj
                </button>
            </div>
        </form>

        <?php if ($expenses === []): ?>
            <div class="alert alert-warning">
                Nie znaleziono wydatków spełniających
                wybrane kryteria.
            </div>
        <?php else: ?>
            <div class="table-wrapper">
                <table>
                    <thead>
                    <tr>
                        <th>ID</th>
                        <th>Data</th>

                        <?php if ($isAdmin): ?>
                            <th>Użytkownik</th>
                        <?php endif; ?>

                        <th>Sklep</th>
                        <th>Opis</th>
                        <th>Kwota</th>
                        <th>Utworzono UTC</th>
                        <th>Akcje</th>
                    </tr>
                    </thead>

                    <tbody>
                    <?php foreach ($expenses as $expense): ?>
                        <tr>
                            <td>
                                <?= (int) $expense['WydatekId'] ?>
                            </td>

                            <td>
                                <?= app_e(
                                    $formatDate(
                                        $expense['DataWydatku'],
                                        'Y-m-d'
                                    )
                                ) ?>
                            </td>

                            <?php if ($isAdmin): ?>
                                <td>
                                    <?= app_e(
                                        trim(
                                            (string) $expense[
                                                'UzytkownikImie'
                                            ]
                                            . ' '
                                            . (string) $expense[
                                                'UzytkownikNazwisko'
                                            ]
                                        )
                                    ) ?>

                                    <div class="muted">
                                        <?= app_e(
                                            $expense[
                                                'UzytkownikLogin'
                                            ]
                                        ) ?>

                                        <?php if (
                                            (int) $expense[
                                                'UzytkownikAktywny'
                                            ] !== 1
                                        ): ?>
                                            · nieaktywny
                                        <?php endif; ?>
                                    </div>
                                </td>
                            <?php endif; ?>

                            <td>
                                <?= app_e(
                                    $expense['SklepNazwa']
                                ) ?>

                                <?php if (
                                    $expense['SklepMiasto']
                                    !== null
                                ): ?>
                                    <div class="muted">
                                        <?= app_e(
                                            $expense['SklepMiasto']
                                        ) ?>
                                    </div>
                                <?php endif; ?>

                                <?php if (
                                    (int) $expense[
                                        'SklepAktywny'
                                    ] !== 1
                                ): ?>
                                    <div class="muted">
                                        Sklep nieaktywny
                                    </div>
                                <?php endif; ?>
                            </td>

                            <td class="table-cell-wrap">
                                <?= $expense['Opis'] !== null
                                    ? app_e($expense['Opis'])
                                    : '—'
                                ?>
                            </td>

                            <td>
                                <strong>
                                    <?= app_e(
                                        $formatAmount(
                                            $expense['Kwota']
                                        )
                                    ) ?>
                                </strong>
                            </td>

                            <td>
                                <?= app_e(
                                    $formatDate(
                                        $expense['UtworzonoUtc'],
                                        'Y-m-d H:i:s'
                                    )
                                ) ?>
                            </td>
                           <td>
                                <div class="table-actions">
                                    <a
                                        href="/expense-edit.php?id=<?= (int) $expense['WydatekId'] ?>"
                                        class="button-link button-small button-secondary"
                                    >
                                        Edytuj
                                    </a>

                                    <a
                                        href="/expense-delete.php?id=<?= (int) $expense['WydatekId'] ?>"
                                        class="button-link button-small button-danger-link"
                                    >
                                        Usuń
                                    </a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <?php if ($totalPages > 1): ?>
            <nav
                class="pagination"
                aria-label="Paginacja wydatków"
            >
                <?php if ($page > 1): ?>
                    <a
                        href="<?= app_e(
                            $buildExpensesUrl([
                                'page' => $page - 1,
                            ])
                        ) ?>"
                        class="button-link button-secondary"
                    >
                        Poprzednia
                    </a>
                <?php endif; ?>

                <span>
                    Strona <?= $page ?>
                    z <?= $totalPages ?>
                </span>

                <?php if ($page < $totalPages): ?>
                    <a
                        href="<?= app_e(
                            $buildExpensesUrl([
                                'page' => $page + 1,
                            ])
                        ) ?>"
                        class="button-link button-secondary"
                    >
                        Następna
                    </a>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
    </section>
</main>
</body>
</html>