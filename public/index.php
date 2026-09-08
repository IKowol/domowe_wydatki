<?php

declare(strict_types=1);

require_once dirname(__DIR__)
    . '/src/Auth/auth.php';

if (app_is_authenticated()) {
    app_redirect(
        '/dashboard.php',
        302
    );
}

app_redirect(
    '/login.php',
    302
);