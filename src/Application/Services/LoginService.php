<?php

namespace Application\Services;

use Application\Ports\PasswordHasher;
use Application\Ports\TokenIssuer;
use Application\Ports\UserRepository;
use Domain\Exceptions\DomainException;

final class LoginService
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly PasswordHasher $passwords,
        private readonly TokenIssuer $tokens
    ) {}

    /** @return array{access_token: string, token_type: string, expires_in: int, role: string} */
    public function execute(string $username, string $password): array
    {
        $user = $this->users->findByUsername(strtolower(trim($username)));
        if ($user === null || ! $this->passwords->verify($password, $user->passwordHash)) {
            throw new DomainException(
                'Las credenciales son inválidas.',
                'INVALID_CREDENTIALS'
            );
        }

        return $this->tokens->issueToken($user);
    }
}
