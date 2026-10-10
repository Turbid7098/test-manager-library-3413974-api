<?php

namespace Application\Services;

use Application\DTO\PageResult;
use Application\Ports\BookRepository;
use Domain\Exceptions\ValidationException;

final class SearchBooksService
{
    public function __construct(private readonly BookRepository $books) {}

    public function execute(string $query = '', ?string $categoryId = null, int $page = 1, int $size = 20): PageResult
    {
        if ($page < 1 || $size < 1 || $size > 50) {
            throw new ValidationException('La paginación debe tener página positiva y tamaño entre 1 y 50.');
        }

        return $this->books->search(trim($query), $categoryId, $page, $size);
    }
}
