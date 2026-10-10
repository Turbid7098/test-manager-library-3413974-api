<?php

namespace Tests\Feature;

use App\Adapters\Outbound\Time\LaravelClock;
use Domain\Entities\User;
use Domain\Exceptions\ValidationException;
use Illuminate\Http\Request;
use Tests\TestCase;

final class LaravelClockTest extends TestCase
{
    public function test_authenticated_local_request_can_supply_an_iso_test_time(): void
    {
        $request = Request::create('/api/v1/loans', 'POST');
        $request->headers->set('X-Test-Time', '2020-01-02T03:04:05Z');
        $request->attributes->set('auth_user', new User('user-1', 'librarian', 'hash', 'librarian'));

        self::assertSame('2020-01-02T03:04:05+00:00', (new LaravelClock($request))->now()->format(DATE_ATOM));
    }

    public function test_test_time_is_ignored_without_authenticated_local_context(): void
    {
        $request = Request::create('/api/v1/auth/login', 'POST');
        $request->headers->set('X-Test-Time', '2020-01-02T03:04:05Z');
        $now = (new LaravelClock($request))->now();

        self::assertGreaterThan(0, $now->getTimestamp() - (new \DateTimeImmutable('2021-01-01T00:00:00Z'))->getTimestamp());
    }

    public function test_invalid_test_time_fails_explicitly(): void
    {
        $request = Request::create('/api/v1/loans', 'POST');
        $request->headers->set('X-Test-Time', 'not-a-date');
        $request->attributes->set('auth_user', new User('user-1', 'librarian', 'hash', 'librarian'));

        $this->expectException(ValidationException::class);

        (new LaravelClock($request))->now();
    }
}
