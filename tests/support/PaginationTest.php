<?php

declare(strict_types=1);

namespace Tests\Support;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class PaginationTest extends TestCase
{
    protected function tearDown(): void
    {
        $_GET = [];
    }

    public function testPositiveQueryIntegerIsReturned(): void
    {
        $_GET['page'] = '7';

        self::assertSame(
            7,
            app_query_positive_int(
                'page'
            )
        );
    }

    public function testInvalidQueryValueUsesDefault(): void
    {
        $_GET['page'] = '-4';

        self::assertSame(
            1,
            app_query_positive_int(
                'page'
            )
        );
    }

    public function testMissingQueryValueUsesCustomDefault(): void
    {
        self::assertSame(
            3,
            app_query_positive_int(
                'page',
                3
            )
        );
    }

    public function testTotalPagesAreCalculatedCorrectly(): void
    {
        self::assertSame(
            3,
            app_total_pages(
                21,
                10
            )
        );
    }

    public function testZeroRowsStillProducesOnePage(): void
    {
        self::assertSame(
            1,
            app_total_pages(
                0,
                10
            )
        );
    }

    public function testInvalidPageSizeThrowsException(): void
    {
        $this->expectException(
            InvalidArgumentException::class
        );

        app_total_pages(
            10,
            0
        );
    }
}