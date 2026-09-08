<?php

declare(strict_types=1);

require_once dirname(__DIR__)
    . '/src/Auth/auth.php';

app_require_post();

app_require_auth();

$csrfToken = $_POST['csrf_token'] ?? null;

if (!app_verify_csrf_token($csrfToken)) {
    http_response_code(403);

    echo 'Nieprawidłowy token bezpieczeństwa.';
    exit;
}

app_logout_user();

app_redirect(
    '/login.php?logged_out=1'
);