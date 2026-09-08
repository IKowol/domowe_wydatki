<?php

declare(strict_types=1);

final class DuplicateUserFieldException extends RuntimeException
{
    public function __construct(
        private readonly string $field
    ) {
        parent::__construct(
            'Powielona wartość pola użytkownika: ' . $field
        );
    }

    public function field(): string
    {
        return $this->field;
    }
}