<?php

namespace Application\Ports;

interface CategoryRepository
{
    public function exists(string $id): bool;

    /** @return array<string, string> Category names keyed by id, ordered by name. */
    public function findAllNames(): array;

    /** @param list<string> $ids
     * @return array<string, string> Category names keyed by id.
     */
    public function findNamesByIds(array $ids): array;
}
