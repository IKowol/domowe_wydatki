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

$actorUserId = (int) $user['user_id'];

$isAdmin = (
    (string) $user['role']
    === 'ADMIN'
);

/*
 * DataWydatku jest typu DATE, więc w tym raporcie
 * interesuje nas data kalendarzowa, a nie czas UTC.
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

$trendStart = $today
    ->modify('first day of this month')
    ->modify('-5 months')
    ->format('Y-m-d');

/*
 * USER nie może sterować owner_user_id.
 */
$ownerUserId = null;

$rawOwnerUserId = $_GET[
    'owner_user_id'
] ?? null;

if ($isAdmin && $rawOwnerUserId !== null) {
    $ownerInput = is_scalar($rawOwnerUserId)
        ? trim((string) $rawOwnerUserId)
        : '';

    if ($ownerInput !== '') {
        $parsedOwner = filter_var(
            $ownerInput,
            FILTER_VALIDATE_INT,
            [
                'options' => [
                    'min_range' => 1,
                ],
            ]
        );

        if (is_int($parsedOwner)) {
            $ownerUserId = $parsedOwner;
        }
    }
}

$connection = null;

$kpis = [];
$filterUsers = [];

$storeBreakdown = [];
$monthlyTrendRows = [];
$userBreakdown = [];

try {
    $connection = require dirname(__DIR__)
        . '/config/database.php';

    $repository = new ReportRepository(
        $connection
    );

    $kpis = $repository->getMonthlyKpis(
        $actorUserId,
        $ownerUserId,
        $currentMonthStart,
        $nextMonthStart,
        $previousMonthStart
    );

    $storeBreakdown =
        $repository->getStoreBreakdown(
            $actorUserId,
            $ownerUserId,
            $currentMonthStart,
            $nextMonthStart
        );

    $monthlyTrendRows =
        $repository->getMonthlyTrend(
            $actorUserId,
            $ownerUserId,
            $trendStart,
            $nextMonthStart
        );

    if ($isAdmin) {
        $filterUsers =
            $repository->getUsersForReportFilter(
                $actorUserId
            );

        /*
         * Ranking użytkowników ma sens tylko
         * dla widoku całego gospodarstwa.
         */
        if ($ownerUserId === null) {
            $userBreakdown =
                $repository->getUserBreakdown(
                    $actorUserId,
                    $currentMonthStart,
                    $nextMonthStart
                );
        }
    }
} catch (Throwable $exception) {
    error_log(
        'Raport miesięczny: '
        . $exception->getMessage()
    );

    http_response_code(500);

    echo 'Nie udało się przygotować raportu.';
    exit;
} finally {
    if (is_resource($connection)) {
        sqlsrv_close($connection);
    }
}

$currentTotal = (float) (
    $kpis['CurrentTotal'] ?? 0
);

$currentCount = (int) (
    $kpis['CurrentCount'] ?? 0
);

$currentAverage = $kpis[
    'CurrentAverage'
] !== null
    ? (float) $kpis['CurrentAverage']
    : 0.0;

$currentMaximum = $kpis[
    'CurrentMaximum'
] !== null
    ? (float) $kpis['CurrentMaximum']
    : 0.0;

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

$currentMonthLabel = $today->format(
    'm/Y'
);

$previousMonthLabel = $today
    ->modify('first day of last month')
    ->format('m/Y');

/*
 * SQL zwraca tylko miesiące posiadające wydatki.
 * Uzupełniamy brakujące miesiące zerami.
 */
$trendByMonth = [];

foreach ($monthlyTrendRows as $row) {
    $trendByMonth[
        (string) $row['MonthStart']
    ] = $row;
}

$monthlyTrend = [];

$trendCursor = new DateTimeImmutable(
    $trendStart
);

for (
    $monthIndex = 0;
    $monthIndex < 6;
    $monthIndex++
) {
    $monthKey = $trendCursor->format(
        'Y-m-d'
    );

    $trendRow =
        $trendByMonth[$monthKey] ?? null;

    $monthlyTrend[] = [
        'month_start' => $monthKey,

        'label' =>
            $trendCursor->format('m/Y'),

        'expense_count' =>
            is_array($trendRow)
                ? (int) $trendRow['ExpenseCount']
                : 0,

        'total_amount' =>
            is_array($trendRow)
                ? (float) $trendRow['TotalAmount']
                : 0.0,
    ];

    $trendCursor =
        $trendCursor->modify('+1 month');
}

/*
 * Największy miesiąc wyznacza skalę
 * pasków w trendzie.
 */
$maximumTrendAmount = 0.0;

foreach ($monthlyTrend as $trendMonth) {
    $maximumTrendAmount = max(
        $maximumTrendAmount,
        (float) $trendMonth['total_amount']
    );
}

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
        Raporty — Domowe wydatki
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
                <h1>Raport miesięczny</h1>

                <p class="muted">
                    Bieżący miesiąc:
                    <?= app_e($currentMonthLabel) ?>
                </p>
            </div>
        </div>

        <?php if ($isAdmin): ?>
            <form
                method="get"
                action="/reports.php"
                class="filter-form"
            >
                <div class="form-field">
                    <label for="owner_user_id">
                        Użytkownik
                    </label>

                    <select
                        id="owner_user_id"
                        name="owner_user_id"
                    >
                        <option value="">
                            Całe gospodarstwo
                        </option>

                        <?php foreach (
                            $filterUsers as $filterUser
                        ): ?>
                            <?php
                            $filterUserId = (int)
                                $filterUser['UzytkownikId'];

                            $label = trim(
                                (string) $filterUser['Imie']
                                . ' '
                                . (string) $filterUser['Nazwisko']
                            );

                            $label .= ' — '
                                . (string) $filterUser['Login'];

                            if (
                                (int) $filterUser['CzyAktywny']
                                !== 1
                            ) {
                                $label .= ' [nieaktywny]';
                            }
                            ?>

                            <option
                                value="<?= $filterUserId ?>"
                                <?= $ownerUserId
                                    === $filterUserId
                                    ? 'selected'
                                    : ''
                                ?>
                            >
                                <?= app_e($label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-actions">
                    <a
                        href="/reports.php"
                        class="button-link button-secondary"
                    >
                        Całe gospodarstwo
                    </a>

                    <button type="submit">
                        Pokaż
                    </button>
                </div>
            </form>
        <?php endif; ?>


        <!-- =========================
             KPI
        ========================== -->

        <div class="kpi-grid">

            <article class="kpi-card">
                <span class="kpi-label">
                    Wydatki w miesiącu
                </span>

                <strong class="kpi-value">
                    <?= app_e(
                        $formatMoney($currentTotal)
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
                    Średni wydatek
                </span>

                <strong class="kpi-value">
                    <?= app_e(
                        $formatMoney($currentAverage)
                    ) ?>
                </strong>
            </article>

            <article class="kpi-card">
                <span class="kpi-label">
                    Największy wydatek
                </span>

                <strong class="kpi-value">
                    <?= app_e(
                        $formatMoney($currentMaximum)
                    ) ?>
                </strong>
            </article>

            <article class="kpi-card">
                <span class="kpi-label">
                    Poprzedni miesiąc
                    (<?= app_e(
                        $previousMonthLabel
                    ) ?>)
                </span>

                <strong class="kpi-value">
                    <?= app_e(
                        $formatMoney($previousTotal)
                    ) ?>
                </strong>
            </article>

            <article class="kpi-card">
                <span class="kpi-label">
                    Zmiana miesiąc do miesiąca
                </span>

                <strong class="kpi-value">
                    <?= app_e(
                        $formatMoney($changeAmount)
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
                        Brak podstawy procentowej —
                        poprzedni miesiąc wynosił 0 zł.
                    <?php else: ?>
                        Brak zmiany.
                    <?php endif; ?>
                </span>
            </article>

        </div>


        <!-- =========================
             TOP SKLEPÓW
        ========================== -->

        <section class="report-section">
            <div class="section-header">
                <div>
                    <h2>Najwięcej wydajesz w</h2>

                    <p class="muted">
                        TOP 5 sklepów w bieżącym miesiącu.
                    </p>
                </div>
            </div>

            <?php if ($storeBreakdown === []): ?>
                <div class="alert alert-warning">
                    Brak wydatków w bieżącym miesiącu.
                </div>
            <?php else: ?>
                <div class="table-wrapper">
                    <table>
                        <thead>
                        <tr>
                            <th>Sklep</th>
                            <th>Transakcje</th>
                            <th>Średni wydatek</th>
                            <th>Łącznie</th>
                            <th>Udział</th>
                        </tr>
                        </thead>

                        <tbody>
                        <?php foreach (
                            $storeBreakdown as $store
                        ): ?>
                            <?php
                            $share = max(
                                0.0,
                                min(
                                    100.0,
                                    (float) $store[
                                        'SharePercent'
                                    ]
                                )
                            );

                            $storeLabel =
                                (string) $store['Nazwa'];

                            if (
                                $store['Miasto'] !== null
                            ) {
                                $storeLabel .= ' — '
                                    . (string) $store[
                                        'Miasto'
                                    ];
                            }
                            ?>

                            <tr>
                                <td>
                                    <?= app_e($storeLabel) ?>

                                    <?php if (
                                        (int) $store[
                                            'CzyAktywny'
                                        ] !== 1
                                    ): ?>
                                        <div class="muted">
                                            sklep nieaktywny
                                        </div>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <?= (int) $store[
                                        'ExpenseCount'
                                    ] ?>
                                </td>

                                <td>
                                    <?= app_e(
                                        $formatMoney(
                                            (float) $store[
                                                'AverageAmount'
                                            ]
                                        )
                                    ) ?>
                                </td>

                                <td>
                                    <strong>
                                        <?= app_e(
                                            $formatMoney(
                                                (float) $store[
                                                    'TotalAmount'
                                                ]
                                            )
                                        ) ?>
                                    </strong>
                                </td>

                                <td class="share-cell">
                                    <div class="share-value">
                                        <?= app_e(
                                            number_format(
                                                $share,
                                                1,
                                                ',',
                                                ' '
                                            )
                                        ) ?>%
                                    </div>

                                    <div
                                        class="report-bar"
                                        aria-hidden="true"
                                    >
                                        <div
                                            class="report-bar-fill"
                                            style="width: <?= $share ?>%"
                                        ></div>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>


        <!-- =========================
             TREND 6 MIESIĘCY
        ========================== -->

        <section class="report-section">
            <div class="section-header">
                <div>
                    <h2>Trend 6 miesięcy</h2>

                    <p class="muted">
                        Łączne wydatki w kolejnych miesiącach.
                    </p>
                </div>
            </div>

            <div class="trend-list">

                <?php foreach (
                    $monthlyTrend as $trendMonth
                ): ?>
                    <?php
                    $trendAmount = (float)
                        $trendMonth['total_amount'];

                    $barWidth = $maximumTrendAmount > 0
                        ? (
                            $trendAmount
                            / $maximumTrendAmount
                        ) * 100
                        : 0;

                    $barWidth = max(
                        0.0,
                        min(
                            100.0,
                            $barWidth
                        )
                    );
                    ?>

                    <div class="trend-row">

                        <div class="trend-label">
                            <?= app_e(
                                $trendMonth['label']
                            ) ?>
                        </div>

                        <div class="trend-visual">

                            <div
                                class="report-bar report-bar-large"
                                aria-hidden="true"
                            >
                                <div
                                    class="report-bar-fill"
                                    style="width: <?= $barWidth ?>%"
                                ></div>
                            </div>

                            <div class="trend-meta">
                                <strong>
                                    <?= app_e(
                                        $formatMoney(
                                            $trendAmount
                                        )
                                    ) ?>
                                </strong>

                                <span class="muted">
                                    <?= (int) $trendMonth[
                                        'expense_count'
                                    ] ?>
                                    transakcji
                                </span>
                            </div>

                        </div>
                    </div>

                <?php endforeach; ?>

            </div>
        </section>


        <!-- =========================
             RANKING UŻYTKOWNIKÓW
             TYLKO ADMIN / CAŁY DOM
        ========================== -->

        <?php if (
            $isAdmin
            && $ownerUserId === null
        ): ?>

            <section class="report-section">

                <div class="section-header">
                    <div>
                        <h2>Wydatki użytkowników</h2>

                        <p class="muted">
                            TOP 5 w bieżącym miesiącu.
                        </p>
                    </div>
                </div>

                <?php if ($userBreakdown === []): ?>

                    <div class="alert alert-warning">
                        Brak wydatków użytkowników
                        w bieżącym miesiącu.
                    </div>

                <?php else: ?>

                    <div class="table-wrapper">
                        <table>
                            <thead>
                            <tr>
                                <th>Użytkownik</th>
                                <th>Transakcje</th>
                                <th>Średni wydatek</th>
                                <th>Łącznie</th>
                            </tr>
                            </thead>

                            <tbody>

                            <?php foreach (
                                $userBreakdown as $reportUser
                            ): ?>
                                <?php
                                $userLabel = trim(
                                    (string) $reportUser[
                                        'Imie'
                                    ]
                                    . ' '
                                    . (string) $reportUser[
                                        'Nazwisko'
                                    ]
                                );
                                ?>

                                <tr>
                                    <td>
                                        <?= app_e($userLabel) ?>

                                        <div class="muted">
                                            <?= app_e(
                                                $reportUser[
                                                    'Login'
                                                ]
                                            ) ?>

                                            <?php if (
                                                (int) $reportUser[
                                                    'CzyAktywny'
                                                ] !== 1
                                            ): ?>
                                                · nieaktywny
                                            <?php endif; ?>
                                        </div>
                                    </td>

                                    <td>
                                        <?= (int) $reportUser[
                                            'ExpenseCount'
                                        ] ?>
                                    </td>

                                    <td>
                                        <?= app_e(
                                            $formatMoney(
                                                (float) $reportUser[
                                                    'AverageAmount'
                                                ]
                                            )
                                        ) ?>
                                    </td>

                                    <td>
                                        <strong>
                                            <?= app_e(
                                                $formatMoney(
                                                    (float) $reportUser[
                                                        'TotalAmount'
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

        <?php endif; ?>

    </section>
</main>

</body>
</html>