<?php

namespace Application\Ports;

interface TokenVerifier
{
    /** @return array{sub: string, role: string}|null */
    public function verify(string $token): ?array;
}
