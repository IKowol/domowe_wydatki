<?php

declare(strict_types=1);

require_once dirname(__DIR__)
    . '/src/Auth/auth.php';

require_once dirname(__DIR__)
    . '/src/Support/pagination.php';

require_once dirname(__DIR__)
    . '/src/User/UserRepository.php';

$admin = app_require_role('ADMIN');

header(
    'Cache-Control: no-store, no-cache, '
    . 'must-revalidate, max-age=0'
);

header('Pragma: no-cache');

$page = app_query_positive_int(
    'page',
    1
);

$perPage = 20;

$connection = null;

try {
    $connection = require dirname(__DIR__)
        . '/config/database.php';

    $repository = new UserRepository(
        $connection
    );

    $result = $repository->getPaginated(
        $page,
        $perPage
    );
} catch (Throwable $exception) {
    error_log(
        'Strona listy użytkowników: '
        . $exception->getMessage()
    );

    http_response_code(500);

    echo 'Nie udało się wyświetlić listy użytkowników.';
    exit;
} finally {
    if (is_resource($connection)) {
        sqlsrv_close($connection);
    }
}

$users = $result['users'];
$totalRows = $result['total_rows'];

$totalPages = app_total_pages(
    $totalRows,
    $perPage
);

$successMessages = app_consume_flash(
    'success'
);

$errorMessages = app_consume_flash(
    'error'
);

/*
 * Jeżeli ktoś poda np. ?page=999,
 * przekierowujemy go na ostatnią istniejącą stronę.
 */
if ($page > $totalPages) {
    app_redirect(
        '/users.php?page=' . $totalPages,
        302
    );
}

/**
 * Formatuje DATETIME2 zwrócony przez sterownik SQLSRV.
 */
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

    <title>Użytkownicy — Domowe wydatki</title>

    <link
        rel="stylesheet"
        href="/assets/css/app.css"
    >
</head>
<body>
<header class="topbar">
    <div>
        <a
            href="/dashboard.php"
            class="brand-link"
        >
            Domowe wydatki
        </a>
    </div>

    <div class="topbar-actions">
        <span>
            <?= app_e($admin['display_name']) ?>
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
                <h1>Użytkownicy</h1>

                <p class="muted">
                    Łączna liczba kont:
                    <?= $totalRows ?>
                </p>
            </div>

            <a
                href="/user-create.php"
                class="button-link"
            >
                Dodaj użytkownika
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

        <?php if ($users === []): ?>
            <div class="alert alert-warning">
                Nie znaleziono użytkowników.
            </div>
        <?php else: ?>
            <div class="table-wrapper">
                <table>
                    <thead>
                    <tr>
                        <th>ID</th>
                        <th>Login</th>
                        <th>Imię i nazwisko</th>
                        <th>E-mail</th>
                        <th>Telefon</th>
                        <th>Rola</th>
                        <th>Status</th>
                        <th>Utworzono UTC</th>
                        <th>Akcje</th>
                    </tr>
                    </thead>

                    <tbody>
                    <?php foreach ($users as $user): ?>
                        <tr>
                            <td>
                                <?= (int) $user['UzytkownikId'] ?>
                            </td>

                            <td>
                                <?= app_e($user['Login']) ?>
                            </td>

                            <td>
                                <?= app_e(
                                    trim(
                                        $user['Imie']
                                        . ' '
                                        . $user['Nazwisko']
                                    )
                                ) ?>
                            </td>

                            <td>
                                <?= app_e($user['Email']) ?>
                            </td>

                            <td>
                                <?= $user['Telefon'] !== null
                                    ? app_e($user['Telefon'])
                                    : '—'
                                ?>
                            </td>

                            <td>
                                <?= app_e($user['Rola']) ?>
                            </td>

                            <td>
                                <?php if (
                                    (int) $user['CzyAktywny'] === 1
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
                                        $user['UtworzonoUtc']
                                    )
                                ) ?>
                            </td>
                            <td>
                                <div class="table-actions">
                                    <a
                                        href="/user-edit.php?id=<?= (int) $user['UzytkownikId'] ?>"
                                        class="button-link button-small button-secondary"
                                    >
                                        Edytuj
                                    </a>
                                    <?php if (
                                        (int) $user['UzytkownikId']
                                        !== (int) $admin['user_id']
                                    ): ?>
                                        <a
                                            href="/user-access.php?id=<?= (int) $user['UzytkownikId'] ?>"
                                            class="button-link button-small"
                                        >
                                            Dostęp
                                        </a>
                                    <?php else: ?>
                                        <span class="muted">
                                            Bieżące konto
                                        </span>
                                    <?php endif; ?>
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
                aria-label="Paginacja użytkowników"
            >
                <?php if ($page > 1): ?>
                    <a
                        href="/users.php?page=<?= $page - 1 ?>"
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
                        href="/users.php?page=<?= $page + 1 ?>"
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