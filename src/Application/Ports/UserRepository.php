<?php

namespace Application\Ports;

use Domain\Entities\User;

interface UserRepository
{
    public function findById(string $id): ?User;

    public function findByUsername(string $username): ?User;

    public function save(User $user): void;
}
