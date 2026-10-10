<?php

namespace Application\DTO;

final readonly class PageResult
{
    /** @param list<mixed> $items */
    public function __construct(
        public array $items,
        public int $page,
        public int $size,
        public int $total
    ) {}
}
