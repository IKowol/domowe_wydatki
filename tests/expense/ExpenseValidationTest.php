<?php

declare(strict_types=1);

namespace Tests\Expense;

use PHPUnit\Framework\TestCase;

final class ExpenseValidationTest extends TestCase
{
    public function testValidUserExpenseIsNormalized(): void
    {
        $result = app_validate_expense_input(
            [
                'owner_user_id' => '999',
                'store_id' => '2',
                'expense_date' => '2026-09-09',
                'amount' => '123,4',
                'description' => '  Zakupy  ',
            ],
            false,
            7
        );

        self::assertSame(
            [],
            $result['errors']
        );

        /*
         * USER nie może ustawić właściciela
         * przez dane formularza.
         */
        self::assertSame(
            7,
            $result['data']['owner_user_id']
        );

        self::assertSame(
            2,
            $result['data']['store_id']
        );

        self::assertSame(
            '123.40',
            $result['data']['amount']
        );

        self::assertSame(
            'Zakupy',
            $result['data']['description']
        );
    }

    public function testAdminMustProvideValidOwner(): void
    {
        $result = app_validate_expense_input(
            [
                'owner_user_id' => '0',
                'store_id' => '1',
                'expense_date' => '2026-09-09',
                'amount' => '10',
            ],
            true,
            1
        );

        self::assertArrayHasKey(
            'owner_user_id',
            $result['errors']
        );
    }

    public function testInvalidStoreIsRejected(): void
    {
        $result = app_validate_expense_input(
            [
                'store_id' => '0',
                'expense_date' => '2026-09-09',
                'amount' => '10',
            ],
            false,
            5
        );

        self::assertArrayHasKey(
            'store_id',
            $result['errors']
        );
    }

    public function testImpossibleDateIsRejected(): void
    {
        $result = app_validate_expense_input(
            [
                'store_id' => '1',
                'expense_date' => '2026-02-31',
                'amount' => '10',
            ],
            false,
            5
        );

        self::assertArrayHasKey(
            'expense_date',
            $result['errors']
        );
    }

    public function testZeroAmountIsRejected(): void
    {
        $result = app_validate_expense_input(
            [
                'store_id' => '1',
                'expense_date' => '2026-09-09',
                'amount' => '0',
            ],
            false,
            5
        );

        self::assertArrayHasKey(
            'amount',
            $result['errors']
        );
    }

    public function testAmountWithMoreThanTwoDecimalPlacesIsRejected(): void
    {
        $result = app_validate_expense_input(
            [
                'store_id' => '1',
                'expense_date' => '2026-09-09',
                'amount' => '12.345',
            ],
            false,
            5
        );

        self::assertArrayHasKey(
            'amount',
            $result['errors']
        );
    }

    public function testAmountAboveDatabaseLimitIsRejected(): void
    {
        $result = app_validate_expense_input(
            [
                'store_id' => '1',
                'expense_date' => '2026-09-09',
                'amount' => '10000000000',
            ],
            false,
            5
        );

        self::assertArrayHasKey(
            'amount',
            $result['errors']
        );
    }

    public function testDescriptionLongerThan500CharactersIsRejected(): void
    {
        $result = app_validate_expense_input(
            [
                'store_id' => '1',
                'expense_date' => '2026-09-09',
                'amount' => '10',
                'description' => str_repeat(
                    'A',
                    501
                ),
            ],
            false,
            5
        );

        self::assertArrayHasKey(
            'description',
            $result['errors']
        );
    }

    public function testEmptyDescriptionBecomesNull(): void
    {
        $result = app_validate_expense_input(
            [
                'store_id' => '1',
                'expense_date' => '2026-09-09',
                'amount' => '10',
                'description' => '   ',
            ],
            false,
            5
        );

        self::assertNull(
            $result['data']['description']
        );
    }
}