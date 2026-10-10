<?php

namespace App\Providers;

use App\Adapters\Outbound\Identity\LaravelIdGenerator;
use App\Adapters\Outbound\Persistence\DatabaseBookRepository;
use App\Adapters\Outbound\Persistence\DatabaseCategoryRepository;
use App\Adapters\Outbound\Persistence\DatabaseLoanRepository;
use App\Adapters\Outbound\Persistence\DatabaseMemberRepository;
use App\Adapters\Outbound\Persistence\DatabaseTransactionManager;
use App\Adapters\Outbound\Persistence\DatabaseUserRepository;
use App\Adapters\Outbound\Security\JwtTokenService;
use App\Adapters\Outbound\Security\LaravelPasswordHasher;
use App\Adapters\Outbound\Storage\FilesystemCoverStorage;
use Application\Ports\BookRepository;
use Application\Ports\CategoryRepository;
use Application\Ports\Clock;
use Application\Ports\CoverStorage;
use Application\Ports\IdGenerator;
use Application\Ports\LoanRepository;
use Application\Ports\MemberRepository;
use Application\Ports\PasswordHasher;
use Application\Ports\TokenIssuer;
use Application\Ports\TokenVerifier;
use Application\Ports\TransactionManager;
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
        $this->app->singleton(BookRepository::class, DatabaseBookRepository::class);
        $this->app->singleton(LoanRepository::class, DatabaseLoanRepository::class);
        $this->app->singleton(TransactionManager::class, DatabaseTransactionManager::class);
        $this->app->singleton(IdGenerator::class, LaravelIdGenerator::class);
        $this->app->singleton(MemberRepository::class, DatabaseMemberRepository::class);
        $this->app->singleton(CategoryRepository::class, DatabaseCategoryRepository::class);
        $this->app->singleton(PasswordHasher::class, LaravelPasswordHasher::class);
        $this->app->singleton(CoverStorage::class, FilesystemCoverStorage::class);
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
