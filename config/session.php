<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

if (session_status() === PHP_SESSION_NONE) {
    if (headers_sent($file, $line)) {
        throw new RuntimeException(
            sprintf(
                'Nie można uruchomić sesji. Nagłówki wysłano w %s:%d.',
                $file,
                $line
            )
        );
    }

    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');

    session_name('domowe_wydatki_session');

    $httpsValue = strtolower(
        (string) ($_SERVER['HTTPS'] ?? '')
    );

    $isHttps = $httpsValue !== ''
        && $httpsValue !== 'off';

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    if (!session_start()) {
        throw new RuntimeException(
            'Nie udało się uruchomić sesji.'
        );
    }
}