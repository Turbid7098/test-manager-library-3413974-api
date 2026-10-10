<?php

namespace Application\Ports;

use Application\DTO\PageResult;
use Domain\Entities\Book;
use Domain\ValueObjects\Isbn;

interface BookRepository
{
    /** Returns an active book only; withdrawn books must be treated as not found. */
    public function findById(string $id): ?Book;

    /** Searches active ISBNs only. */
    public function findByIsbn(Isbn $isbn): ?Book;

    public function save(Book $book): void;

    /** @return Book[] */
    public function findManyByIds(array $ids): array;

    /** Searches active books by title/author and optional category, ordered by title. */
    public function search(string $query, ?string $categoryId, int $page, int $size): PageResult;

    public function hasOpenLoans(string $bookId): bool;
}
