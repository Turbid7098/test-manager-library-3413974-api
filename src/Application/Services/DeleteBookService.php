<?php

namespace Application\Services;

use Application\Ports\BookRepository;
use Application\Ports\Clock;
use Domain\Entities\Book;
use Domain\Exceptions\ConflictException;
use Domain\Exceptions\NotFoundException;

final class DeleteBookService
{
    public function __construct(
        private readonly BookRepository $books,
        private readonly Clock $clock
    ) {}

    public function execute(string $id): Book
    {
        $book = $this->books->findById($id);
        if ($book === null || $book->deletedAt() !== null) {
            throw new NotFoundException('El libro no existe.');
        }
        if ($this->books->hasOpenLoans($id)) {
            throw new ConflictException(
                'No se puede dar de baja un libro con préstamos abiertos.',
                'BOOK_HAS_OPEN_LOANS'
            );
        }

        $book->delete($this->clock->now());
        $this->books->save($book);

        return $book;
    }
}
