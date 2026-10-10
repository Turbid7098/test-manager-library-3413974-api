<?php

namespace Domain\ValueObjects;

use Domain\Exceptions\ValidationException;

readonly class Quantity
{
    public function __construct(public int $value)
    {
        if ($value < 0) {
            throw new ValidationException('La cantidad no puede ser menor a cero.', [['field' => 'quantity', 'issue' => 'negativo']]);
        }
    }

    public function subtract(Quantity $other): Quantity
    {
        return new self($this->value - $other->value);
    }

    public function add(Quantity $other): Quantity
    {
        return new self($this->value + $other->value);
    }

    public function equals(Quantity $other): bool
    {
        return $this->value === $other->value;
    }

    public function isGreaterThanOrEqual(Quantity $other): bool
    {
        return $this->value >= $other->value;
    }
}
