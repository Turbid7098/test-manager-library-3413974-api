<?php

namespace Domain\Entities;

use DateTimeImmutable;
use Domain\Exceptions\NotFoundException;
use Domain\Exceptions\ValidationException;

class Loan
{
    /** @var list<LoanItem> */
    private readonly array $items;

    public function __construct(
        public readonly string $id,
        public readonly string $memberId,
        public readonly string $loanedByUserId,
        public readonly DateTimeImmutable $loanedAt,
        array $items
    ) {
        if (count($items) < 1 || count($items) > 3) {
            throw new ValidationException('El préstamo debe tener entre 1 y 3 libros.');
        }

        $bookIds = [];
        $itemIds = [];
        foreach ($items as $item) {
            if (! $item instanceof LoanItem) {
                throw new ValidationException('Las líneas del préstamo no son válidas.');
            }
            if ($item->loanId !== $this->id) {
                throw new ValidationException('La línea pertenece a otro préstamo.');
            }
            if (isset($bookIds[$item->bookId])) {
                throw new ValidationException(
                    'No se puede repetir el mismo libro en un préstamo.',
                    [['field' => 'items', 'issue' => 'libro duplicado']],
                    'DUPLICATE_BOOK_IN_LOAN'
                );
            }
            if (isset($itemIds[$item->id])) {
                throw new ValidationException('El identificador de línea está repetido.');
            }
            if ($item->dueAt != $this->loanedAt->modify('+14 days')) {
                throw new ValidationException('El vencimiento debe ser 14 días después del préstamo.');
            }
            $bookIds[$item->bookId] = true;
            $itemIds[$item->id] = true;
        }
        $this->items = array_values($items);
    }

    public function returnItem(string $itemId, DateTimeImmutable $returnedAt): LoanItem
    {
        if ($returnedAt < $this->loanedAt) {
            throw new ValidationException('La fecha de devolución no puede ser anterior al préstamo.');
        }

        foreach ($this->items as $item) {
            if ($item->id === $itemId) {
                $item->markAsReturned($returnedAt);

                return $item;
            }
        }

        throw new NotFoundException('La línea de préstamo no existe.');
    }

    /** @return LoanItem[] */
    public function getItems(): array
    {
        return $this->items;
    }

    public function getStatus(DateTimeImmutable $now): string
    {
        if (empty($this->items)) {
            return 'closed';
        }

        $allReturned = true;
        foreach ($this->items as $item) {
            if (! $item->isReturned()) {
                $allReturned = false;
                if ($item->isOverdue($now)) {
                    return 'overdue';
                }
            }
        }

        return $allReturned ? 'closed' : 'open';
    }
}
