<?php

namespace Application\Ports;

use Application\DTO\OverdueLoanItem;
use DateTimeImmutable;
use Domain\Entities\Loan;

interface LoanRepository
{
    public function findById(string $id): ?Loan;

    /** Persists a new loan or its item-return changes; there is no loan edit/delete operation. */
    public function save(Loan $loan): void;

    /** Counts unreturned loan items for the member, not the number of loan headers. */
    public function countActiveItemsForMember(string $memberId): int;

    /** True when any unreturned item for the member has due_at earlier than now. */
    public function memberHasOverdueLoans(string $memberId, DateTimeImmutable $now): bool;

    /**
     * Returns unreturned overdue lines with computed days late.
     *
     * @return list<OverdueLoanItem>
     */
    public function findOverdueItems(DateTimeImmutable $now): array;
}
