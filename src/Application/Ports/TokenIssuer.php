<?php

namespace Application\Ports;

use Domain\Entities\User;

interface TokenIssuer
{
    /** @return array{access_token: string, token_type: string, expires_in: int, role: string} */
    public function issueToken(User $user): array;
}
