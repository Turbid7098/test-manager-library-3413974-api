<?php

namespace Domain\ValueObjects;

use Domain\Exceptions\ValidationException;

readonly class Isbn
{
    public function __construct(public string $value)
    {
        if (! preg_match('/^[0-9]{13}$/D', $value)) {
            throw new ValidationException('El ISBN debe tener exactamente 13 dígitos.', [['field' => 'isbn', 'issue' => 'formato inválido']]);
        }
    }

    public function equals(Isbn $other): bool
    {
        return $this->value === $other->value;
    }
}
