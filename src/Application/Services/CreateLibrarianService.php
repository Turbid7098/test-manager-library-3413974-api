<?php

namespace Application\Services;

use Application\Ports\IdGenerator;
use Application\Ports\PasswordHasher;
use Application\Ports\UserRepository;
use Domain\Entities\User;
use Domain\Exceptions\ConflictException;
use Domain\Exceptions\ValidationException;

final class CreateLibrarianService
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly PasswordHasher $passwords,
        private readonly IdGenerator $ids
    ) {}

    public function execute(string $username, string $password, string $role = 'librarian'): User
    {
        $username = strtolower(trim($username));
        if ($role !== 'librarian') {
            throw new ValidationException(
                'La API solo permite crear usuarios con rol librarian.',
                [['field' => 'role', 'issue' => 'rol no permitido']]
            );
        }
        if ($username === '' || $password === '') {
            throw new ValidationException('El usuario y la contraseña son obligatorios.');
        }
        if ($this->users->findByUsername($username) !== null) {
            throw new ConflictException('El nombre de usuario ya existe.', 'USERNAME_TAKEN');
        }

        $user = new User($this->ids->generate(), $username, $this->passwords->hash($password), 'librarian');
        $this->users->save($user);

        return $user;
    }
}
