<?php

namespace Tests\Feature;

use Application\Ports\PasswordHasher;
use Application\Ports\TokenIssuer;
use Application\Ports\UserRepository;
use Application\Services\LoginService;
use Domain\Entities\User;
use Domain\Exceptions\DomainException;
use Tests\TestCase;

final class AuthLoginTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.jwt.secret' => str_repeat('feature-test-secret-', 3)]);

        $user = new User('user-1', 'admin', password_hash('local-test-password', PASSWORD_BCRYPT), 'admin');
        $this->app->instance(UserRepository::class, new class($user) implements UserRepository
        {
            public function __construct(private readonly User $user) {}

            public function findById(string $id): ?User
            {
                return $id === $this->user->id ? $this->user : null;
            }

            public function findByUsername(string $username): ?User
            {
                return strtolower(trim($username)) === $this->user->username() ? $this->user : null;
            }

            public function save(User $user): void {}
        });
        $this->app->instance(PasswordHasher::class, new class implements PasswordHasher
        {
            public function hash(string $plainText): string
            {
                return password_hash($plainText, PASSWORD_BCRYPT);
            }

            public function verify(string $plainText, string $hash): bool
            {
                return password_verify($plainText, $hash);
            }
        });
        $this->app->instance(TokenIssuer::class, new class implements TokenIssuer
        {
            public function issueToken(User $user): array
            {
                return [
                    'access_token' => 'test-jwt',
                    'token_type' => 'Bearer',
                    'expires_in' => 28800,
                    'role' => $user->role,
                ];
            }
        });
    }

    public function test_login_returns_the_documented_token_shape_without_user_or_password_data(): void
    {
        $this->postJson('/api/v1/auth/login', [
            'username' => ' ADMIN ',
            'password' => 'local-test-password',
        ])
            ->assertOk()
            ->assertExactJson([
                'access_token' => 'test-jwt',
                'token_type' => 'Bearer',
                'expires_in' => 28800,
                'role' => 'admin',
            ]);
    }

    public function test_login_uses_the_same_generic_error_for_unknown_user_and_wrong_password(): void
    {
        foreach ([
            ['username' => 'unknown', 'password' => 'anything'],
            ['username' => 'admin', 'password' => 'wrong'],
        ] as $credentials) {
            $this->postJson('/api/v1/auth/login', $credentials)
                ->assertUnauthorized()
                ->assertExactJson([
                    'code' => 'INVALID_CREDENTIALS',
                    'message' => 'Las credenciales son inválidas.',
                    'details' => [],
                ]);
        }
    }

    public function test_login_password_is_redacted_from_exception_traces(): void
    {
        try {
            $this->app->make(LoginService::class)->execute('admin', 'trace-redaction-password');
            self::fail('Invalid credentials should be rejected.');
        } catch (DomainException $exception) {
            self::assertStringNotContainsString(
                'trace-redaction-password',
                json_encode($exception->getTrace(), JSON_THROW_ON_ERROR)
            );
        }
    }

    public function test_login_validation_uses_the_standard_error_body(): void
    {
        $this->postJson('/api/v1/auth/login', ['username' => ''])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'VALIDATION_ERROR')
            ->assertJsonPath('message', 'Los datos enviados no son válidos.')
            ->assertJsonStructure([
                'code',
                'message',
                'details' => [['field', 'issue']],
            ]);
    }
}
