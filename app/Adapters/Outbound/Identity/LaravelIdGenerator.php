<?php

namespace App\Adapters\Outbound\Identity;

use Application\Ports\IdGenerator;
use Illuminate\Support\Str;

final class LaravelIdGenerator implements IdGenerator
{
    public function generate(): string
    {
        return Str::uuid()->toString();
    }
}
