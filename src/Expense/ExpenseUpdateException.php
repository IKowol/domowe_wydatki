<?php

declare(strict_types=1);

final class ExpenseUpdateException extends RuntimeException
{
    public const INVALID_DATE = 'invalid_date';
    public const ACTOR_INACTIVE = 'actor_inactive';
    public const NOT_FOUND = 'not_found';
    public const FORBIDDEN = 'forbidden';
    public const OWNER_INACTIVE = 'owner_inactive';
    public const INVALID_AMOUNT = 'invalid_amount';
    public const DESCRIPTION_TOO_LONG = 'description_too_long';
    public const STORE_INACTIVE = 'store_inactive';

    public function __construct(
        private readonly string $reason
    ) {
        parent::__construct(
            'Nie udało się zaktualizować wydatku: '
            . $reason
        );
    }

    public function reason(): string
    {
        return $this->reason;
    }
}