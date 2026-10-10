<?php

namespace Application\Services;

use Application\Ports\BookRepository;
use Application\Ports\CategoryRepository;
use Domain\Entities\Book;
use Domain\Exceptions\NotFoundException;
use Domain\ValueObjects\Quantity;

final class UpdateBookService
{
    public function __construct(
        private readonly BookRepository $books,
        private readonly CategoryRepository $categories
    ) {}

    public function execute(string $id, string $title, string $author, string $categoryId, int $totalCopies): Book
    {
        $book = $this->books->findById($id);
        if ($book === null || $book->deletedAt() !== null) {
            throw new NotFoundException('El libro no existe.');
        }
        if (! $this->categories->exists($categoryId)) {
            throw new NotFoundException('La categoría no existe.');
        }

        $book->updateDetails($title, $author, $categoryId, new Quantity($totalCopies));
        $this->books->save($book);

        return $book;
    }
}
