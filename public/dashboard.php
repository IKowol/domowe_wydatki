<?php

declare(strict_types=1);

require_once dirname(__DIR__)
    . '/src/Auth/auth.php';

require_once dirname(__DIR__)
    . '/src/Report/ReportRepository.php';

$user = app_require_auth();

header(
    'Cache-Control: no-store, no-cache, '
    . 'must-revalidate, max-age=0'
);

header('Pragma: no-cache');

$csrfToken = app_csrf_token();

$actorUserId = (int) $user['user_id'];

$isAdmin = (
    (string) $user['role']
    === 'ADMIN'
);

/*
 * DataWydatku jest typu DATE,
 * dlatego operujemy na datach kalendarzowych.
 */
$today = new DateTimeImmutable('today');

$currentMonthStart = $today
    ->modify('first day of this month')
    ->format('Y-m-d');

$nextMonthStart = $today
    ->modify('first day of next month')
    ->format('Y-m-d');

$previousMonthStart = $today
    ->modify('first day of last month')
    ->format('Y-m-d');

$currentMonthLabel = $today->format(
    'm/Y'
);

$connection = null;

$kpis = [];
$recentExpenses = [];

$dashboardError = null;

try {
    $connection = require dirname(__DIR__)
        . '/config/database.php';

    $repository = new ReportRepository(
        $connection
    );

    /*
     * ownerUserId = null:
     *
     * USER:
     * repozytorium i tak ogranicza dane
     * do zalogowanego użytkownika.
     *
     * ADMIN:
     * otrzymuje dane całego gospodarstwa.
     */
    $kpis = $repository->getMonthlyKpis(
        $actorUserId,
        null,
        $currentMonthStart,
        $nextMonthStart,
        $previousMonthStart
    );

    $recentExpenses =
        $repository->getRecentExpenses(
            $actorUserId,
            5
        );
} catch (Throwable $exception) {
    error_log(
        'Dashboard: '
        . $exception->getMessage()
    );

    /*
     * Błąd raportu nie powinien pozbawić
     * użytkownika całego panelu.
     */
    $dashboardError =
        'Nie udało się pobrać części danych '
        . 'podsumowania.';
} finally {
    if (is_resource($connection)) {
        sqlsrv_close($connection);
    }
}

/*
 * KPI.
 */
$currentTotal = (float) (
    $kpis['CurrentTotal'] ?? 0
);

$currentCount = (int) (
    $kpis['CurrentCount'] ?? 0
);

$previousTotal = (float) (
    $kpis['PreviousTotal'] ?? 0
);

$changeAmount =
    $currentTotal - $previousTotal;

$changePercent = null;

if ($previousTotal > 0) {
    $changePercent =
        ($changeAmount / $previousTotal) * 100;
}

/*
 * Formatowanie wyłącznie do prezentacji.
 */
$formatMoney = static function (
    float $value
): string {
    return number_format(
        $value,
        2,
        ',',
        ' '
    ) . ' zł';
};

$formatPercent = static function (
    float $value
): string {
    $prefix = $value > 0
        ? '+'
        : '';

    return $prefix
        . number_format(
            $value,
            1,
            ',',
            ' '
        )
        . '%';
};

$formatExpenseDate = static function (
    string $value
): string {
    $date = DateTimeImmutable::createFromFormat(
        'Y-m-d',
        $value
    );

    if ($date === false) {
        return $value;
    }

    return $date->format('d.m.Y');
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

    <title>Panel — Domowe wydatki</title>

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

        <form
            method="post"
            action="/logout.php"
        >
            <input
                type="hidden"
                name="csrf_token"
                value="<?= app_e($csrfToken) ?>"
            >

            <button
                type="submit"
                class="button-secondary"
            >
                Wyloguj się
            </button>
        </form>
    </div>
</header>


<main class="container">

    <!-- =========================
         POWITANIE
    ========================== -->

    <section class="card dashboard-welcome">

        <div class="section-header">
            <div>
                <h1>
                    Witaj,
                    <?= app_e(
                        $user['display_name']
                    ) ?>
                </h1>

                <p class="muted">
                    Podsumowanie finansów —
                    <?= app_e($currentMonthLabel) ?>
                </p>
            </div>
        </div>

        <div class="dashboard-account-summary">
            <span>
                Login:
                <strong>
                    <?= app_e($user['login']) ?>
                </strong>
            </span>

            <span>
                Rola:
                <strong>
                    <?= app_e($user['role']) ?>
                </strong>
            </span>
        </div>

        <?php if ($isAdmin): ?>
            <div class="alert alert-success">
                Widok administratora obejmuje
                całe gospodarstwo domowe.
            </div>
        <?php endif; ?>

        <?php if ($dashboardError !== null): ?>
            <div class="alert alert-warning">
                <?= app_e($dashboardError) ?>
            </div>
        <?php endif; ?>

    </section>


    <!-- =========================
         KPI
    ========================== -->

    <section class="card dashboard-section">

        <div class="section-header">
            <div>
                <h2>Bieżący miesiąc</h2>

                <p class="muted">
                    Najważniejsze informacje
                    o wydatkach.
                </p>
            </div>
        </div>

        <div class="kpi-grid">

            <article class="kpi-card">
                <span class="kpi-label">
                    Wydatki
                </span>

                <strong class="kpi-value">
                    <?= app_e(
                        $formatMoney(
                            $currentTotal
                        )
                    ) ?>
                </strong>
            </article>


            <article class="kpi-card">
                <span class="kpi-label">
                    Liczba wydatków
                </span>

                <strong class="kpi-value">
                    <?= $currentCount ?>
                </strong>
            </article>


            <article class="kpi-card">
                <span class="kpi-label">
                    Zmiana względem
                    poprzedniego miesiąca
                </span>

                <strong class="kpi-value">
                    <?= app_e(
                        $formatMoney(
                            $changeAmount
                        )
                    ) ?>
                </strong>

                <span class="kpi-detail">

                    <?php if (
                        $changePercent !== null
                    ): ?>

                        <?= app_e(
                            $formatPercent(
                                $changePercent
                            )
                        ) ?>

                    <?php elseif (
                        $currentTotal > 0
                    ): ?>

                        Poprzedni miesiąc:
                        0,00 zł

                    <?php else: ?>

                        Brak zmiany.

                    <?php endif; ?>

                </span>
            </article>

        </div>

    </section>


    <!-- =========================
         OSTATNIE WYDATKI
    ========================== -->

    <section class="card dashboard-section">

        <div class="section-header">

            <div>
                <h2>Ostatnie wydatki</h2>

                <p class="muted">
                    5 ostatnio zarejestrowanych
                    wydatków.
                </p>
            </div>

            <a
                href="/expenses.php"
                class="button-link button-secondary"
            >
                Wszystkie wydatki
            </a>

        </div>


        <?php if ($recentExpenses === []): ?>

            <div class="alert alert-warning">
                Nie ma jeszcze żadnych wydatków
                do wyświetlenia.
            </div>

        <?php else: ?>

            <div class="table-wrapper">

                <table>
                    <thead>
                    <tr>
                        <th>Data</th>

                        <th>Sklep</th>

                        <?php if ($isAdmin): ?>
                            <th>Użytkownik</th>
                        <?php endif; ?>

                        <th>Opis</th>

                        <th>Kwota</th>
                    </tr>
                    </thead>

                    <tbody>

                    <?php foreach (
                        $recentExpenses
                        as $expense
                    ): ?>

                        <?php
                        $storeLabel =
                            (string) $expense[
                                'SklepNazwa'
                            ];

                        if (
                            $expense[
                                'SklepMiasto'
                            ] !== null
                        ) {
                            $storeLabel .= ' — '
                                . (string) $expense[
                                    'SklepMiasto'
                                ];
                        }

                        $description = trim(
                            (string) (
                                $expense['Opis']
                                ?? ''
                            )
                        );

                        if ($description === '') {
                            $description = '—';
                        }

                        $expenseUserName = trim(
                            (string) $expense[
                                'UzytkownikImie'
                            ]
                            . ' '
                            . (string) $expense[
                                'UzytkownikNazwisko'
                            ]
                        );

                        if ($expenseUserName === '') {
                            $expenseUserName =
                                (string) $expense[
                                    'UzytkownikLogin'
                                ];
                        }
                        ?>

                        <tr>

                            <td>
                                <?= app_e(
                                    $formatExpenseDate(
                                        (string) $expense[
                                            'DataWydatkuTekst'
                                        ]
                                    )
                                ) ?>
                            </td>

                            <td>
                                <?= app_e(
                                    $storeLabel
                                ) ?>
                            </td>

                            <?php if ($isAdmin): ?>
                                <td>
                                    <?= app_e(
                                        $expenseUserName
                                    ) ?>

                                    <div class="muted">
                                        <?= app_e(
                                            $expense[
                                                'UzytkownikLogin'
                                            ]
                                        ) ?>
                                    </div>
                                </td>
                            <?php endif; ?>

                            <td>
                                <?= app_e(
                                    $description
                                ) ?>
                            </td>

                            <td>
                                <strong>
                                    <?= app_e(
                                        $formatMoney(
                                            (float) $expense[
                                                'Kwota'
                                            ]
                                        )
                                    ) ?>
                                </strong>
                            </td>

                        </tr>

                    <?php endforeach; ?>

                    </tbody>
                </table>

            </div>

        <?php endif; ?>

    </section>


    <!-- =========================
         SZYBKIE AKCJE
    ========================== -->

    <section class="card dashboard-section">

        <div class="section-header">
            <div>
                <h2>Szybkie akcje</h2>

                <p class="muted">
                    Najczęściej używane funkcje
                    aplikacji.
                </p>
            </div>
        </div>


        <div class="dashboard-action-grid">

            <a
                href="/expense-create.php"
                class="button-link dashboard-action-primary"
            >
                Dodaj wydatek
            </a>

            <a
                href="/expenses.php"
                class="button-link button-secondary"
            >
                Lista wydatków
            </a>

            <a
                href="/reports.php"
                class="button-link button-secondary"
            >
                Raporty
            </a>

            <a
                href="/stores.php"
                class="button-link button-secondary"
            >
                Sklepy
            </a>

            <a
                href="/user-edit.php"
                class="button-link button-secondary"
            >
                Edytuj mój profil
            </a>

            <a
                href="/password-change.php"
                class="button-link button-secondary"
            >
                Zmień hasło
            </a>

            <?php if ($isAdmin): ?>

                <a
                    href="/users.php"
                    class="button-link button-secondary"
                >
                    Zarządzaj użytkownikami
                </a>

                <a
                    href="/password-reset.php"
                    class="button-link button-secondary"
                >
                    Resetuj hasło użytkownika
                </a>

            <?php endif; ?>

        </div>

    </section>

</main>

</body>
</html>