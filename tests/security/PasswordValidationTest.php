<?php

declare(strict_types=1);

namespace Tests\Security;

use PHPUnit\Framework\TestCase;

final class PasswordValidationTest extends TestCase
{
    public function testValidPasswordIsAccepted(): void
    {
        $password = 'abcdefghijkl';

        $errors = app_validate_new_password(
            $password,
            $password
        );

        self::assertSame([], $errors);
    }

    public function testPasswordShorterThanTwelveCharactersIsRejected(): void
    {
        $password = 'abcdefghijk';

        $errors = app_validate_new_password(
            $password,
            $password
        );

        self::assertArrayHasKey(
            'password',
            $errors
        );
    }

    public function testDifferentConfirmationIsRejected(): void
    {
        $errors = app_validate_new_password(
            'abcdefghijkl',
            'abcdefghijklX'
        );

        self::assertArrayHasKey(
            'password_confirmation',
            $errors
        );
    }

    public function testPasswordLongerThanSeventyTwoBytesIsRejected(): void
    {
        $password = str_repeat('ą', 37);

        self::assertGreaterThan(
            APP_PASSWORD_MAX_BYTES,
            strlen($password)
        );

        $errors = app_validate_new_password(
            $password,
            $password
        );

        self::assertArrayHasKey(
            'password',
            $errors
        );
    }

    public function testUnicodePasswordAtSeventyTwoBytesIsAccepted(): void
    {
        /*
         * "ą" zajmuje 2 bajty w UTF-8.
         * 36 znaków = 72 bajty.
         */
        $password = str_repeat('ą', 36);

        self::assertSame(
            72,
            strlen($password)
        );

        self::assertSame(
            36,
            mb_strlen($password, 'UTF-8')
        );

        $errors = app_validate_new_password(
            $password,
            $password
        );

        self::assertSame([], $errors);
    }
}