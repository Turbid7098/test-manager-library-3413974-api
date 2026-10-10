<?php

namespace Application\DTO;

final readonly class CoverContents
{
    public function __construct(
        public string $contents,
        public string $mediaType
    ) {}
}
