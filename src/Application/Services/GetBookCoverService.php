<?php

namespace Application\Services;

use Application\DTO\CoverContents;
use Application\Ports\BookRepository;
use Application\Ports\CoverStorage;
use Domain\Exceptions\NotFoundException;

final class GetBookCoverService
{
    public function __construct(
        private readonly BookRepository $books,
        private readonly CoverStorage $covers
    ) {}

    public function execute(string $bookId): CoverContents
    {
        $book = $this->books->findById($bookId);
        if ($book === null || $book->deletedAt() !== null || $book->coverKey() === null) {
            throw new NotFoundException('La portada no existe.');
        }

        $contents = $this->covers->read($book->coverKey());
        if ($contents === null) {
            throw new NotFoundException('La portada no existe.');
        }

        return $contents;
    }
}
