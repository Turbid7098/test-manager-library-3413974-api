<?php

namespace Domain\Entities;

use Domain\Exceptions\ValidationException;

class User
{
    private string $username;

    public function __construct(
        public readonly string $id,
        string $username,
        public readonly string $passwordHash,
        public readonly string $role
    ) {
        $this->username = strtolower(trim($username));
        if ($this->username === '') {
            throw new ValidationException('El nombre de usuario no puede estar vacío.', [['field' => 'username', 'issue' => 'vacío']]);
        }
        if ($this->passwordHash === '') {
            throw new ValidationException('El hash de la contraseña no puede estar vacío.');
        }
        if (! in_array($role, ['admin', 'librarian'])) {
            throw new ValidationException('El rol debe ser admin o librarian.', [['field' => 'role', 'issue' => 'rol inválido']]);
        }
    }

    public function username(): string
    {
        return $this->username;
    }
}
