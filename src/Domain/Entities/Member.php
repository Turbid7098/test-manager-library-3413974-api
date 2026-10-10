<?php

namespace Domain\Entities;

use Domain\Exceptions\ValidationException;
use Domain\ValueObjects\DocumentNumber;

class Member
{
    private string $fullName;

    public function __construct(
        public readonly string $id,
        string $fullName,
        public readonly DocumentNumber $documentNumber
    ) {
        $this->fullName = trim($fullName);
        if ($this->fullName === '') {
            throw new ValidationException('El nombre completo no puede estar vacío.', [['field' => 'full_name', 'issue' => 'vacío']]);
        }
    }

    public function fullName(): string
    {
        return $this->fullName;
    }
}
