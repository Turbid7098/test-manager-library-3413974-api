<?php

namespace Application\Services;

use Application\Ports\BookRepository;
use Application\Ports\CoverStorage;
use Application\Ports\TransactionManager;
use Domain\Exceptions\NotFoundException;

final class RemoveBookCoverService
{
    public function __construct(
        private readonly BookRepository $books,
        private readonly CoverStorage $covers,
        private readonly TransactionManager $transactions
    ) {}

    public function execute(string $bookId): void
    {
        $oldKey = $this->transactions->execute(function () use ($bookId): ?string {
            $book = $this->books->findById($bookId);
            if ($book === null || $book->deletedAt() !== null) {
                throw new NotFoundException('El libro no existe.');
            }

            $key = $book->coverKey();
            if ($key !== null) {
                $book->setCoverKey(null);
                $this->books->save($book);
            }

            return $key;
        });

        if ($oldKey !== null) {
            $this->covers->delete($oldKey);
        }
    }
}
