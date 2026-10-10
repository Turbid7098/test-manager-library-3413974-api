<?php

namespace Application\Services;

use Application\Ports\BookRepository;
use Application\Ports\Clock;
use Application\Ports\LoanRepository;
use Application\Ports\TransactionManager;
use Domain\Entities\LoanItem;
use Domain\Exceptions\NotFoundException;

final class ReturnLoanItemService
{
    public function __construct(
        private readonly BookRepository $books,
        private readonly LoanRepository $loans,
        private readonly TransactionManager $transactions,
        private readonly Clock $clock
    ) {}

    public function execute(string $loanId, string $itemId): LoanItem
    {
        return $this->transactions->execute(function () use ($loanId, $itemId): LoanItem {
            $loan = $this->loans->findById($loanId);
            if ($loan === null) {
                throw new NotFoundException('El préstamo no existe.');
            }

            $item = $loan->returnItem($itemId, $this->clock->now());
            $book = $this->books->findById($item->bookId);
            if ($book === null) {
                throw new NotFoundException('El libro del préstamo no existe.');
            }

            $book->restock();
            $this->books->save($book);
            $this->loans->save($loan);

            return $item;
        });
    }
}
