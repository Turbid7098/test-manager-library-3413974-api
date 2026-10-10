<?php

namespace Application\DTO;

final readonly class MostBorrowedRow
{
    public function __construct(
        public string $bookId,
        public string $bookTitle,
        public string $categoryName,
        public int $timesBorrowed
    ) {}
}
