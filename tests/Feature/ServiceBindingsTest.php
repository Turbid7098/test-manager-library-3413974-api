<?php

namespace Tests\Feature;

use App\Adapters\Outbound\Persistence\DatabaseUserRepository;
use App\Adapters\Outbound\Security\JwtTokenService;
use App\Adapters\Outbound\Security\LaravelPasswordHasher;
use Application\Ports\PasswordHasher;
use Application\Ports\TokenIssuer;
use Application\Ports\TokenVerifier;
use Application\Ports\UserRepository;
use Tests\TestCase;

final class ServiceBindingsTest extends TestCase
{
    public function test_authentication_ports_resolve_to_their_production_adapters(): void
    {
        config(['services.jwt.secret' => str_repeat('feature-test-secret-', 3)]);

        self::assertInstanceOf(DatabaseUserRepository::class, $this->app->make(UserRepository::class));
        self::assertInstanceOf(LaravelPasswordHasher::class, $this->app->make(PasswordHasher::class));

        $tokens = $this->app->make(JwtTokenService::class);
        self::assertSame($tokens, $this->app->make(TokenIssuer::class));
        self::assertSame($tokens, $this->app->make(TokenVerifier::class));
    }
}
