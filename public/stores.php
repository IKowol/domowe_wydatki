<?php

declare(strict_types=1);

require_once dirname(__DIR__)
    . '/src/Auth/auth.php';

require_once dirname(__DIR__)
    . '/src/Support/pagination.php';

require_once dirname(__DIR__)
    . '/src/Store/StoreRepository.php';

$user = app_require_auth();

header(
    'Cache-Control: no-store, no-cache, '
    . 'must-revalidate, max-age=0'
);

header('Pragma: no-cache');

$isAdmin = (
    (string) $user['role']
    === 'ADMIN'
);

$page = app_query_positive_int(
    'page',
    1
);

$perPage = 20;
$connection = null;

try {
    $connection = require dirname(__DIR__)
        . '/config/database.php';

    $repository = new StoreRepository(
        $connection
    );

    $result = $repository->getPaginated(
        $page,
        $perPage,
        $isAdmin
    );
} catch (Throwable $exception) {
    error_log(
        'Strona listy sklepów: '
        . $exception->getMessage()
    );

    http_response_code(500);

    echo 'Nie udało się wyświetlić listy sklepów.';
    exit;
} finally {
    if (is_resource($connection)) {
        sqlsrv_close($connection);
    }
}

$stores = $result['stores'];
$totalRows = $result['total_rows'];

$totalPages = app_total_pages(
    $totalRows,
    $perPage
);

if ($page > $totalPages) {
    app_redirect(
        '/stores.php?page=' . $totalPages,
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
    mixed $value
): string {
    if ($value instanceof DateTimeInterface) {
        return $value->format(
            'Y-m-d H:i:s'
        );
    }

    return (string) $value;
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

    <title>Sklepy — Domowe wydatki</title>

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
                <h1>Sklepy</h1>

                <p class="muted">
                    Liczba widocznych sklepów:
                    <?= $totalRows ?>
                </p>

                <?php if (!$isAdmin): ?>
                    <p class="muted">
                        Wyświetlane są tylko aktywne sklepy.
                    </p>
                <?php endif; ?>
            </div>

            <?php if ($isAdmin): ?>
                <a
                    href="/store-create.php"
                    class="button-link"
                >
                    Dodaj sklep
                </a>
            <?php endif; ?>
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

        <?php if ($stores === []): ?>
            <div class="alert alert-warning">
                Nie znaleziono sklepów.
            </div>
        <?php else: ?>
            <div class="table-wrapper">
                <table>
                    <thead>
                    <tr>
                        <th>ID</th>
                        <th>Nazwa</th>
                        <th>Miasto</th>
                        <th>Adres</th>
                        <th>Telefon</th>
                        <th>Status</th>
                        <th>Utworzono UTC</th>
                        <?php if ($isAdmin): ?>
                            <th>Akcje</th>
                        <?php endif; ?>
                    </tr>
                    </thead>

                    <tbody>
                    <?php foreach ($stores as $store): ?>
                        <?php
                        $street = trim(
                            (string) ($store['Ulica'] ?? '')
                        );

                        $buildingNumber = trim(
                            (string) (
                                $store['NumerBudynku'] ?? ''
                            )
                        );

                        $address = trim(
                            $street . ' ' . $buildingNumber
                        );
                        ?>

                        <tr>
                            <td>
                                <?= (int) $store['SklepId'] ?>
                            </td>

                            <td>
                                <?= app_e($store['Nazwa']) ?>
                            </td>

                            <td>
                                <?= $store['Miasto'] !== null
                                    ? app_e($store['Miasto'])
                                    : '—'
                                ?>
                            </td>

                            <td>
                                <?= $address !== ''
                                    ? app_e($address)
                                    : '—'
                                ?>
                            </td>

                            <td>
                                <?= $store['Telefon'] !== null
                                    ? app_e($store['Telefon'])
                                    : '—'
                                ?>
                            </td>

                            <td>
                                <?php if (
                                    (int) $store['CzyAktywny'] === 1
                                ): ?>
                                    <span class="status status-active">
                                        Aktywny
                                    </span>
                                <?php else: ?>
                                    <span class="status status-inactive">
                                        Nieaktywny
                                    </span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <?= app_e(
                                    $formatDate(
                                        $store['UtworzonoUtc']
                                    )
                                ) ?>
                            </td>
                            <?php if ($isAdmin): ?>
                                <td>
                                    <a
                                        href="/store-edit.php?id=<?= (int) $store['SklepId'] ?>"
                                        class="button-link button-small button-secondary"
                                    >
                                        Edytuj
                                    </a>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <?php if ($totalPages > 1): ?>
            <nav
                class="pagination"
                aria-label="Paginacja sklepów"
            >
                <?php if ($page > 1): ?>
                    <a
                        href="/stores.php?page=<?= $page - 1 ?>"
                        class="button-link button-secondary"
                    >
                        Poprzednia
                    </a>
                <?php endif; ?>

                <span>
                    Strona
                    <?= $page ?>
                    z
                    <?= $totalPages ?>
                </span>

                <?php if ($page < $totalPages): ?>
                    <a
                        href="/stores.php?page=<?= $page + 1 ?>"
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