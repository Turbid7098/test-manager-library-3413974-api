<?php

namespace App\Adapters\Outbound\Time;

use Application\Ports\Clock;
use DateTimeImmutable;
use DateTimeZone;
use Domain\Entities\User;
use Domain\Exceptions\ValidationException;
use Illuminate\Http\Request;

final class LaravelClock implements Clock
{
    public function __construct(private readonly Request $request) {}

    public function now(): DateTimeImmutable
    {
        $testTime = $this->request->header('X-Test-Time');
        if (
            is_string($testTime)
            && app()->environment(['local', 'testing'])
            && $this->request->attributes->get('auth_user') instanceof User
        ) {
            $normalized = preg_replace('/Z$/', '+00:00', $testTime);
            $time = is_string($normalized)
                ? DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', $normalized)
                : false;
            $errors = DateTimeImmutable::getLastErrors();

            if (
                $time === false
                || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
                || $time->format('Y-m-d\TH:i:sP') !== $normalized
            ) {
                throw new ValidationException(
                    'El encabezado X-Test-Time debe ser una fecha ISO 8601 válida.',
                    [['field' => 'X-Test-Time', 'issue' => 'fecha inválida']],
                    'INVALID_TEST_TIME'
                );
            }

            return $time->setTimezone(new DateTimeZone('UTC'));
        }

        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
