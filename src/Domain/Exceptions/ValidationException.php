<?php

namespace Domain\Exceptions;

class ValidationException extends DomainException
{
    public function __construct(string $message, array $details = [], string $errorCode = 'VALIDATION_ERROR')
    {
        parent::__construct($message, $errorCode, $details);
    }
}
