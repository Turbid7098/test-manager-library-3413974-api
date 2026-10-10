<?php

namespace App\Adapters\Outbound\Security;

use Application\Ports\PasswordHasher;
use Illuminate\Support\Facades\Hash;

final class LaravelPasswordHasher implements PasswordHasher
{
    public function hash(string $plainText): string
    {
        return Hash::make($plainText);
    }

    public function verify(string $plainText, string $hash): bool
    {
        return Hash::check($plainText, $hash);
    }
}
