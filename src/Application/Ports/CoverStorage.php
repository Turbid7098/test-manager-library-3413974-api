<?php

namespace Application\Ports;

use Application\DTO\CoverContents;

interface CoverStorage
{
    /** The adapter must verify the actual image content, not trust the supplied media type. */
    public function store(string $bookId, string $contents, string $mediaType): string;

    public function read(string $key): ?CoverContents;

    public function delete(string $key): void;
}
