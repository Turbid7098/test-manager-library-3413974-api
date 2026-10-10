<?php

namespace Tests\Unit;

use App\Adapters\Outbound\Security\JwtTokenService;
use App\Http\Middleware\AuthenticateJwt;
use Application\Ports\Clock;
use Application\Ports\TokenVerifier;
use Application\Ports\UserRepository;
use DateTimeImmutable;
use Domain\Entities\User;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

final class TokenClock implements Clock
{
    public function __construct(public DateTimeImmutable $instant) {}

    public function now(): DateTimeImmutable
    {
        return $this->instant;
    }
}

final class JwtAuthenticationTest extends TestCase
{
    public function test_hs256_token_contains_only_subject_and_role_and_expires_in_eight_hours(): void
    {
        $clock = new TokenClock(new DateTimeImmutable('2026-10-10T12:00:00Z'));
        $tokens = $this->tokens($clock);
        $user = new User('user-1', 'operator', 'not-in-token', 'librarian');

        $issued = $tokens->issueToken($user);
        $segments = explode('.', $issued['access_token']);
        self::assertCount(3, $segments);
        self::assertSame(28800, $issued['expires_in']);
        self::assertSame('Bearer', $issued['token_type']);
        self::assertSame('librarian', $issued['role']);
        self::assertSame(['alg' => 'HS256', 'typ' => 'JWT'], json_decode(base64_decode(strtr($segments[0], '-_', '+/')), true));
        self::assertSame(
            ['sub' => 'user-1', 'role' => 'librarian', 'iat' => $clock->now()->getTimestamp(), 'exp' => $clock->now()->getTimestamp() + 28800],
            json_decode(base64_decode(strtr($segments[1], '-_', '+/')), true)
        );
        self::assertSame(['sub' => 'user-1', 'role' => 'librarian'], $tokens->verify($issued['access_token']));
    }

    public function test_invalid_tampered_malformed_and_expired_tokens_are_rejected(): void
    {
        $clock = new TokenClock(new DateTimeImmutable('2026-10-10T12:00:00Z'));
        $tokens = $this->tokens($clock);
        $issued = $tokens->issueToken(new User('user-1', 'operator', 'hash', 'admin'));

        self::assertNull($tokens->verify('not.a.jwt'));
        self::assertNull($tokens->verify(str_repeat('a', 4097)));
        self::assertNull($tokens->verify($issued['access_token'].'.extra'));
        $segments = explode('.', $issued['access_token']);
        $segments[2] = ($segments[2][0] === 'A' ? 'B' : 'A').substr($segments[2], 1);
        $tampered = implode('.', $segments);
        self::assertNull($tokens->verify($tampered));

        $clock->instant = $clock->now()->modify('+8 hours');
        self::assertNull($tokens->verify($issued['access_token']));
    }

    public function test_authentication_middleware_returns_standard_401_and_403_and_accepts_current_role(): void
    {
        $users = new class implements UserRepository
        {
            public ?User $user = null;

            public function findById(string $id): ?User
            {
                return $this->user?->id === $id ? $this->user : null;
            }

            public function findByUsername(string $username): ?User
            {
                return null;
            }

            public function save(User $user): void {}
        };
        $users->user = new User('user-1', 'operator', 'hash', 'librarian');
        $verifier = new class implements TokenVerifier
        {
            public function verify(string $token): ?array
            {
                return $token === 'valid' ? ['sub' => 'user-1', 'role' => 'librarian'] : null;
            }
        };
        $middleware = new AuthenticateJwt($verifier, $users);

        $missing = $middleware->handle(Request::create('/api/v1/books', 'GET'), static fn () => new Response);
        self::assertSame(401, $missing->getStatusCode());
        self::assertSame('UNAUTHENTICATED', json_decode($missing->getContent(), true)['code']);

        $forbiddenRequest = Request::create('/api/v1/reports/most-borrowed', 'GET');
        $forbiddenRequest->headers->set('Authorization', 'Bearer valid');
        $forbidden = $middleware->handle(
            $forbiddenRequest,
            static fn () => new Response,
            'admin'
        );
        self::assertSame(403, $forbidden->getStatusCode());
        self::assertSame('FORBIDDEN_ROLE', json_decode($forbidden->getContent(), true)['code']);

        $allowedRequest = Request::create('/api/v1/books', 'GET');
        $allowedRequest->headers->set('Authorization', 'Bearer valid');
        $allowed = $middleware->handle(
            $allowedRequest,
            static fn (Request $request) => new JsonResponse([
                'user_id' => $request->attributes->get('auth_user')->id,
            ])
        );
        self::assertSame(200, $allowed->getStatusCode());
        self::assertSame(['user_id' => 'user-1'], json_decode($allowed->getContent(), true));
    }

    private function tokens(TokenClock $clock): JwtTokenService
    {
        return new JwtTokenService(
            new ConfigRepository(['services' => ['jwt' => ['secret' => str_repeat('test-secret-', 4)]]]),
            $clock
        );
    }
}
