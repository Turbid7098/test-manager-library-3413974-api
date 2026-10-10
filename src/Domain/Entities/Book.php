<?php

namespace Domain\Entities;

use DateTimeImmutable;
use Domain\Exceptions\ConflictException;
use Domain\Exceptions\NotFoundException;
use Domain\Exceptions\ValidationException;
use Domain\ValueObjects\Isbn;
use Domain\ValueObjects\Quantity;

class Book
{
    private string $title;

    private string $author;

    private string $categoryId;

    public function __construct(
        public readonly string $id,
        string $title,
        string $author,
        public readonly Isbn $isbn,
        string $categoryId,
        private Quantity $totalCopies,
        private Quantity $availableCopies,
        private ?string $coverKey = null,
        private ?DateTimeImmutable $deletedAt = null
    ) {
        $this->title = trim($title);
        $this->author = trim($author);
        $this->categoryId = trim($categoryId);

        if ($this->title === '' || $this->author === '' || $this->categoryId === '') {
            throw new ValidationException('El título, autor y categoría son obligatorios.');
        }
        if ($this->totalCopies->value < 1) {
            throw new ValidationException('El total de ejemplares debe ser mayor o igual a 1.');
        }
        if (! $this->totalCopies->isGreaterThanOrEqual($this->availableCopies)) {
            throw new ValidationException('Los ejemplares disponibles no pueden superar al total.');
        }
        if ($this->coverKey === '') {
            throw new ValidationException('La clave de portada no puede estar vacía.');
        }
    }

    public function title(): string
    {
        return $this->title;
    }

    public function author(): string
    {
        return $this->author;
    }

    public function categoryId(): string
    {
        return $this->categoryId;
    }

    public function totalCopies(): Quantity
    {
        return $this->totalCopies;
    }

    public function availableCopies(): Quantity
    {
        return $this->availableCopies;
    }

    public function coverKey(): ?string
    {
        return $this->coverKey;
    }

    public function deletedAt(): ?DateTimeImmutable
    {
        return $this->deletedAt;
    }

    public function withdraw(): void
    {
        $this->ensureActive();
        if ($this->availableCopies->value < 1) {
            throw new ConflictException('No quedan ejemplares disponibles de «'.$this->title.'».', 'BOOK_NOT_AVAILABLE', [['field' => 'available_copies', 'issue' => 'sin ejemplares']]);
        }
        $this->availableCopies = new Quantity($this->availableCopies->value - 1);
    }

    public function restock(): void
    {
        if ($this->availableCopies->value >= $this->totalCopies->value) {
            throw new ValidationException('No se pueden devolver más ejemplares de los totales.');
        }
        $this->availableCopies = new Quantity($this->availableCopies->value + 1);
    }

    public function updateDetails(string $title, string $author, string $categoryId, Quantity $newTotal): void
    {
        $this->ensureActive();
        $title = trim($title);
        $author = trim($author);
        $categoryId = trim($categoryId);
        if ($title === '' || $author === '' || $categoryId === '') {
            throw new ValidationException('El título, autor y categoría son obligatorios.');
        }
        if ($newTotal->value < 1) {
            throw new ValidationException('El total de ejemplares debe ser mayor o igual a 1.', [['field' => 'total_copies', 'issue' => 'menor a 1']]);
        }

        $difference = $newTotal->value - $this->totalCopies->value;
        $newAvailable = $this->availableCopies->value + $difference;

        if ($newAvailable < 0) {
            throw new ValidationException('Cambiar el total dejaría los disponibles en negativo.', [['field' => 'total_copies', 'issue' => 'disponibles negativos']]);
        }

        $this->title = $title;
        $this->author = $author;
        $this->categoryId = $categoryId;
        $this->totalCopies = $newTotal;
        $this->availableCopies = new Quantity($newAvailable);
    }

    public function delete(DateTimeImmutable $at): void
    {
        $this->ensureActive();
        $this->deletedAt = $at;
    }

    public function setCoverKey(?string $coverKey): void
    {
        if ($coverKey === '') {
            throw new ValidationException('La clave de portada no puede estar vacía.');
        }
        $this->coverKey = $coverKey;
    }

    private function ensureActive(): void
    {
        if ($this->deletedAt !== null) {
            throw new NotFoundException('El libro no existe.');
        }
    }
}
