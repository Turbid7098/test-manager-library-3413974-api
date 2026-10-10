<?php

namespace App\Providers;

use App\Adapters\Outbound\Persistence\DatabaseUserRepository;
use App\Adapters\Outbound\Security\JwtTokenService;
use App\Adapters\Outbound\Security\LaravelPasswordHasher;
use Application\Ports\Clock;
use Application\Ports\PasswordHasher;
use Application\Ports\TokenIssuer;
use Application\Ports\TokenVerifier;
use Application\Ports\UserRepository;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(UserRepository::class, DatabaseUserRepository::class);
        $this->app->singleton(PasswordHasher::class, LaravelPasswordHasher::class);
        $this->app->singleton(JwtTokenService::class);
        $this->app->alias(JwtTokenService::class, TokenIssuer::class);
        $this->app->alias(JwtTokenService::class, TokenVerifier::class);
        $this->app->singleton(Clock::class, static fn () => new class implements Clock
        {
            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            }
        });
    }

    public function boot(): void {}
}
