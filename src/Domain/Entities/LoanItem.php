<?php

namespace Domain\Entities;

use DateTimeImmutable;
use Domain\Exceptions\ConflictException;
use Domain\Exceptions\ValidationException;
use Domain\ValueObjects\Isbn;

class LoanItem
{
    private ?DateTimeImmutable $returnedAt;

    public function __construct(
        public readonly string $id,
        public readonly string $loanId,
        public readonly string $bookId,
        public readonly string $bookTitle,
        public readonly Isbn $isbn,
        public readonly string $categoryName,
        public readonly DateTimeImmutable $dueAt,
        ?DateTimeImmutable $returnedAt = null
    ) {
        $this->returnedAt = $returnedAt;
        if (trim($this->bookTitle) === '' || trim($this->categoryName) === '') {
            throw new ValidationException('El título y la categoría congelados son obligatorios.');
        }
    }

    public function markAsReturned(DateTimeImmutable $returnedAt): void
    {
        if ($this->returnedAt !== null) {
            throw new ConflictException('El ejemplar ya fue devuelto.', 'ITEM_ALREADY_RETURNED', [['field' => 'returned_at', 'issue' => 'ya devuelto']]);
        }
        $this->returnedAt = $returnedAt;
    }

    public function isOverdue(DateTimeImmutable $now): bool
    {
        return $this->returnedAt === null && $this->dueAt < $now;
    }

    public function isReturned(): bool
    {
        return $this->returnedAt !== null;
    }

    public function returnedAt(): ?DateTimeImmutable
    {
        return $this->returnedAt;
    }
}
