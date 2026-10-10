<?php

namespace Domain\Exceptions;

class DomainException extends \Exception
{
    public function __construct(string $message, public readonly string $errorCode, public readonly array $details = [])
    {
        parent::__construct($message);
    }
}
