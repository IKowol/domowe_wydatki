<?php

declare(strict_types=1);

namespace Tests\Expense;

use PHPUnit\Framework\TestCase;

final class ExpenseFiltersTest extends TestCase
{
    public function testAmountWithCommaIsParsed(): void
    {
        $result =
            app_parse_expense_filter_amount(
                '123,45'
            );

        self::assertSame(
            '123.45',
            $result['canonical']
        );

        self::assertSame(
            12345,
            $result['cents']
        );

        self::assertNull(
            $result['error']
        );
    }

    public function testEmptyAmountMeansNoFilter(): void
    {
        $result =
            app_parse_expense_filter_amount(
                ''
            );

        self::assertNull(
            $result['canonical']
        );

        self::assertNull(
            $result['cents']
        );

        self::assertNull(
            $result['error']
        );
    }

    public function testAmountWithThreeDecimalPlacesIsRejected(): void
    {
        $result =
            app_parse_expense_filter_amount(
                '10.123'
            );

        self::assertNotNull(
            $result['error']
        );
    }

    public function testValidLeapDayIsAccepted(): void
    {
        self::assertSame(
            '2024-02-29',
            app_parse_expense_filter_date(
                '2024-02-29'
            )
        );
    }

    public function testInvalidDateIsRejected(): void
    {
        self::assertNull(
            app_parse_expense_filter_date(
                '2026-02-29'
            )
        );
    }

    public function testInvalidSortFallsBackToDateDescending(): void
    {
        $result =
            app_validate_expense_filters(
                [
                    'sort' => 'DROP TABLE',
                ],
                false
            );

        self::assertSame(
            'date_desc',
            $result['sort']
        );
    }

    public function testUserCannotFilterByAnotherOwner(): void
    {
        $result =
            app_validate_expense_filters(
                [
                    'owner_user_id' => '15',
                ],
                false
            );

        self::assertNull(
            $result['filters']['owner_user_id']
        );

        self::assertSame(
            '',
            $result['old_input']['owner_user_id']
        );
    }

    public function testAdminCanFilterByOwner(): void
    {
        $result =
            app_validate_expense_filters(
                [
                    'owner_user_id' => '15',
                ],
                true
            );

        self::assertSame(
            15,
            $result['filters']['owner_user_id']
        );
    }

    public function testReversedDateRangeIsRejected(): void
    {
        $result =
            app_validate_expense_filters(
                [
                    'date_from' => '2026-09-10',
                    'date_to' => '2026-09-01',
                ],
                true
            );

        self::assertArrayHasKey(
            'date_range',
            $result['errors']
        );
    }

    public function testReversedAmountRangeIsRejected(): void
    {
        $result =
            app_validate_expense_filters(
                [
                    'amount_min' => '200',
                    'amount_max' => '100',
                ],
                true
            );

        self::assertArrayHasKey(
            'amount_range',
            $result['errors']
        );
    }
}