<?php

namespace Domain\ValueObjects;

use Domain\Exceptions\ValidationException;

readonly class DocumentNumber
{
    public string $value;

    public function __construct(string $value)
    {
        $value = trim($value);
        if ($value === '') {
            throw new ValidationException('El número de documento no puede estar vacío.', [['field' => 'document_number', 'issue' => 'vacío']]);
        }
        $this->value = $value;
    }
}
