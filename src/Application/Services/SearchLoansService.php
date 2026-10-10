<?php

namespace Application\Services;

use Application\DTO\PageResult;
use Application\Ports\Clock;
use Application\Ports\LoanRepository;
use DateTimeImmutable;
use Domain\Exceptions\ValidationException;
use Domain\ValueObjects\DateRange;

final class SearchLoansService
{
    public function __construct(
        private readonly LoanRepository $loans,
        private readonly Clock $clock
    ) {}

    public function execute(
        ?DateTimeImmutable $from,
        ?DateTimeImmutable $to,
        ?string $status,
        ?string $memberId,
        int $page,
        int $size
    ): PageResult {
        if ($from !== null && $to !== null) {
            new DateRange($from, $to);
        }
        if ($page < 1 || $size < 1 || $size > 50) {
            throw new ValidationException('La paginación debe tener página positiva y tamaño entre 1 y 50.');
        }

        return $this->loans->search($from, $to, $status, $memberId, $page, $size, $this->clock->now());
    }
}
