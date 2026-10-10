<?php

namespace App\Adapters\Outbound\Persistence;

use Application\Ports\UserRepository;
use Domain\Entities\User;
use Illuminate\Support\Facades\DB;

final class DatabaseUserRepository implements UserRepository
{
    public function findById(string $id): ?User
    {
        $record = DB::table('library.user')->where('id', $id)->first();

        return $record === null ? null : $this->toDomain($record);
    }

    public function findByUsername(string $username): ?User
    {
        $record = DB::table('library.user')->where('username', strtolower(trim($username)))->first();

        return $record === null ? null : $this->toDomain($record);
    }

    public function save(User $user): void
    {
        DB::table('library.user')->updateOrInsert(
            ['id' => $user->id],
            [
                'username' => $user->username(),
                'password_hash' => $user->passwordHash,
                'role' => $user->role,
            ]
        );
    }

    private function toDomain(object $record): User
    {
        return new User(
            $record->id,
            $record->username,
            $record->password_hash,
            $record->role
        );
    }
}
