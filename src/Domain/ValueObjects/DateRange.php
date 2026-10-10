<?php

namespace Domain\ValueObjects;

use DateTimeImmutable;
use Domain\Exceptions\ValidationException;

readonly class DateRange
{
    public function __construct(public DateTimeImmutable $start, public DateTimeImmutable $end)
    {
        if ($start > $end) {
            throw new ValidationException(
                'La fecha de fin no puede ser anterior a la fecha de inicio.',
                [['field' => 'date_range', 'issue' => 'rango inválido']],
                'INVALID_DATE_RANGE'
            );
        }
    }
}
