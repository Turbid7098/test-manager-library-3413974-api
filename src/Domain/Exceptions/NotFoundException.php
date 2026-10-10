<?php

namespace Domain\Exceptions;

class NotFoundException extends DomainException
{
    public function __construct(string $message, array $details = [])
    {
        parent::__construct($message, 'NOT_FOUND', $details);
    }
}
