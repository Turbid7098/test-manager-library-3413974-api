<?php

namespace Application\Services;

use Application\DTO\OverdueLoanItem;
use Application\Ports\Clock;
use Application\Ports\LoanRepository;

final class ListOverdueLoansService
{
    public function __construct(
        private readonly LoanRepository $loans,
        private readonly Clock $clock
    ) {}

    /** @return list<OverdueLoanItem> */
    public function execute(): array
    {
        $items = $this->loans->findOverdueItems($this->clock->now());
        usort(
            $items,
            static fn (OverdueLoanItem $left, OverdueLoanItem $right): int => $right->daysLate <=> $left->daysLate
        );

        return $items;
    }
}
