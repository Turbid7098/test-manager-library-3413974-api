<?php

namespace Application\Services;

use Application\DTO\PageResult;
use Application\Ports\MemberRepository;
use Domain\Exceptions\ValidationException;

final class SearchMembersService
{
    public function __construct(private readonly MemberRepository $members) {}

    public function execute(string $query = '', int $page = 1, int $size = 20): PageResult
    {
        if ($page < 1 || $size < 1 || $size > 50) {
            throw new ValidationException('La paginación debe tener página positiva y tamaño entre 1 y 50.');
        }

        return $this->members->search(trim($query), $page, $size);
    }
}
