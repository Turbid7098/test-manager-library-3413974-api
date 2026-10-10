<?php

namespace Application\Services;

use Application\Ports\BookRepository;
use Application\Ports\CoverStorage;
use Domain\Entities\Book;
use Domain\Exceptions\NotFoundException;
use Domain\Exceptions\ValidationException;

final class SetBookCoverService
{
    public function __construct(
        private readonly BookRepository $books,
        private readonly CoverStorage $covers
    ) {}

    public function execute(string $bookId, string $contents, string $detectedMediaType): Book
    {
        if ($contents === '' || strlen($contents) > 2 * 1024 * 1024) {
            throw new ValidationException('La portada debe tener un tamaño máximo de 2 MB.');
        }
        if (! in_array($detectedMediaType, ['image/jpeg', 'image/png'], true)) {
            throw new ValidationException('La portada debe ser una imagen JPG o PNG.');
        }

        $book = $this->books->findById($bookId);
        if ($book === null || $book->deletedAt() !== null) {
            throw new NotFoundException('El libro no existe.');
        }

        $previousKey = $book->coverKey();
        $key = $this->covers->store($book->id, $contents, $detectedMediaType);
        if ($key === '') {
            throw new \RuntimeException('El almacenamiento de portadas devolvió una clave vacía.');
        }
        $book->setCoverKey($key);
        $this->books->save($book);

        if ($previousKey !== null) {
            $this->covers->delete($previousKey);
        }

        return $book;
    }
}
