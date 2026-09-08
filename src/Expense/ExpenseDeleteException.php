<?php

declare(strict_types=1);

final class ExpenseDeleteException extends RuntimeException
{
    public const ACTOR_INACTIVE = 'actor_inactive';
    public const NOT_FOUND = 'not_found';
    public const FORBIDDEN = 'forbidden';

    public function __construct(
        private readonly string $reason
    ) {
        parent::__construct(
            'Nie udało się usunąć wydatku: '
            . $reason
        );
    }

    public function reason(): string
    {
        return $this->reason;
    }
}