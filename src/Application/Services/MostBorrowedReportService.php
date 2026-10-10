<?php

namespace Application\Services;

use Application\DTO\MostBorrowedRow;
use Application\Ports\MostBorrowedReportReader;
use DateTimeImmutable;
use Domain\ValueObjects\DateRange;

final class MostBorrowedReportService
{
    public function __construct(private readonly MostBorrowedReportReader $reports) {}

    /** @return list<MostBorrowedRow> */
    public function execute(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        return $this->reports->mostBorrowed(new DateRange($from, $to));
    }
}
