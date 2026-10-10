<?php

namespace Application\Ports;

use Domain\Entities\User;

interface TokenIssuer
{
    public function issueToken(User $user): array; // returns ['access_token' => '...', 'expires_in' => 28800]
}
