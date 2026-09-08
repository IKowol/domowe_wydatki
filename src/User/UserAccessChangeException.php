<?php

declare(strict_types=1);

final class UserAccessChangeException extends RuntimeException
{
    public const NOT_FOUND_OR_FORBIDDEN =
        'not_found_or_forbidden';

    public const SELF_CHANGE =
        'self_change';

    public const LAST_ACTIVE_ADMIN =
        'last_active_admin';

    public function __construct(
        private readonly string $reason
    ) {
        parent::__construct(
            'Nie udało się zmienić dostępu użytkownika: '
            . $reason
        );
    }

    public function reason(): string
    {
        return $this->reason;
    }
}