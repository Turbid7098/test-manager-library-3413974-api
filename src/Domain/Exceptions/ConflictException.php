<?php

namespace Domain\Exceptions;

class ConflictException extends DomainException
{
    public function __construct(string $message, string $errorCode, array $details = [])
    {
        parent::__construct($message, $errorCode, $details);
    }
}
