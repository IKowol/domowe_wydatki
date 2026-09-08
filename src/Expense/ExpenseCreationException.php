<?php

declare(strict_types=1);

final class ExpenseCreationException extends RuntimeException
{
    public const ACTOR_INACTIVE = 'actor_inactive';
    public const FORBIDDEN_OWNER = 'forbidden_owner';
    public const OWNER_INACTIVE = 'owner_inactive';
    public const STORE_INACTIVE = 'store_inactive';
    public const INVALID_AMOUNT = 'invalid_amount';
    public const DESCRIPTION_TOO_LONG = 'description_too_long';

    public function __construct(
        private readonly string $reason
    ) {
        parent::__construct(
            'Nie udało się utworzyć wydatku: ' . $reason
        );
    }

    public function reason(): string
    {
        return $this->reason;
    }
}