<?php

namespace Application\Ports;

use Application\DTO\MostBorrowedRow;
use Domain\ValueObjects\DateRange;

interface MostBorrowedReportReader
{
    /**
     * Groups by book id and frozen title/category; the result must contain no member or operator data.
     *
     * @return list<MostBorrowedRow>
     */
    public function mostBorrowed(DateRange $range): array;
}
