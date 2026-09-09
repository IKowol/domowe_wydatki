<?php

declare(strict_types=1);

namespace Tests\Store;

use PHPUnit\Framework\TestCase;

final class StoreValidationTest extends TestCase
{
    public function testValidStoreIsAcceptedAndTrimmed(): void
    {
        $result = app_validate_store_input([
            'name' => '  Lidl  ',
            'city' => '  Gdańsk  ',
            'street' => '  Długa  ',
            'building_number' => '  12A  ',
            'phone' => '  123456789  ',
        ]);

        self::assertSame(
            [],
            $result['errors']
        );

        self::assertSame(
            'Lidl',
            $result['data']['name']
        );

        self::assertSame(
            'Gdańsk',
            $result['data']['city']
        );

        self::assertSame(
            'Długa',
            $result['data']['street']
        );

        self::assertSame(
            '12A',
            $result['data']['building_number']
        );

        self::assertSame(
            '123456789',
            $result['data']['phone']
        );
    }

    public function testEmptyOptionalFieldsBecomeNull(): void
    {
        $result = app_validate_store_input([
            'name' => 'Lidl',
            'city' => ' ',
            'street' => '',
            'building_number' => '',
            'phone' => '',
        ]);

        self::assertSame(
            [],
            $result['errors']
        );

        self::assertNull(
            $result['data']['city']
        );

        self::assertNull(
            $result['data']['street']
        );

        self::assertNull(
            $result['data']['building_number']
        );

        self::assertNull(
            $result['data']['phone']
        );
    }

    public function testEmptyStoreNameIsRejected(): void
    {
        $result = app_validate_store_input([
            'name' => '   ',
        ]);

        self::assertArrayHasKey(
            'name',
            $result['errors']
        );
    }

    public function testStoreNameLongerThan120CharactersIsRejected(): void
    {
        $result = app_validate_store_input([
            'name' => str_repeat('A', 121),
        ]);

        self::assertArrayHasKey(
            'name',
            $result['errors']
        );
    }

    public function testInvalidPhoneIsRejected(): void
    {
        $result = app_validate_store_input([
            'name' => 'Test',
            'phone' => 'abcdefghi',
        ]);

        self::assertArrayHasKey(
            'phone',
            $result['errors']
        );
    }
}