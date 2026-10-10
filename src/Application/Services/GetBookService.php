<?php

namespace Application\Services;

use Application\Ports\BookRepository;
use Domain\Entities\Book;
use Domain\Exceptions\NotFoundException;

final class GetBookService
{
    public function __construct(private readonly BookRepository $books) {}

    public function execute(string $id): Book
    {
        $book = $this->books->findById($id);
        if ($book === null || $book->deletedAt() !== null) {
            throw new NotFoundException('El libro no existe.');
        }

        return $book;
    }
}
