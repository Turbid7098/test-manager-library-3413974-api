<?php

namespace Application\Services;

use Application\Ports\BookRepository;
use Application\Ports\CategoryRepository;
use Application\Ports\IdGenerator;
use Domain\Entities\Book;
use Domain\Exceptions\ConflictException;
use Domain\Exceptions\NotFoundException;
use Domain\ValueObjects\Isbn;
use Domain\ValueObjects\Quantity;

final class CreateBookService
{
    public function __construct(
        private readonly BookRepository $books,
        private readonly CategoryRepository $categories,
        private readonly IdGenerator $ids
    ) {}

    public function execute(string $title, string $author, string $isbn, string $categoryId, int $totalCopies): Book
    {
        $isbnValue = new Isbn($isbn);
        if (! $this->categories->exists($categoryId)) {
            throw new NotFoundException('La categoría no existe.');
        }
        if ($this->books->findByIsbn($isbnValue) !== null) {
            throw new ConflictException('El ISBN ya pertenece a un libro activo.', 'ISBN_TAKEN');
        }

        $total = new Quantity($totalCopies);
        $book = new Book(
            $this->ids->generate(),
            $title,
            $author,
            $isbnValue,
            $categoryId,
            $total,
            $total
        );
        $this->books->save($book);

        return $book;
    }
}
