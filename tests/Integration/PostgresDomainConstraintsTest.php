<?php

namespace Tests\Integration;

use Domain\Entities\Book;
use Domain\Entities\User;
use Domain\Exceptions\ValidationException;
use Domain\ValueObjects\Isbn;
use Domain\ValueObjects\Quantity;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PostgresDomainConstraintsTest extends TestCase
{
    private const CATEGORY_NOVEL = '11111111-1111-4111-8111-111111111111';

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'pgsql']);
        DB::purge('pgsql');
        self::assertSame('pgsql', DB::connection('pgsql')->getDriverName());
        DB::connection('pgsql')->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (DB::connection('pgsql')->transactionLevel() > 0) {
            DB::connection('pgsql')->rollBack();
        }
        DB::purge('pgsql');

        parent::tearDown();
    }

    public function test_book_total_must_be_positive_in_postgres(): void
    {
        $book = new Book(
            Str::uuid()->toString(),
            'Libro constraint',
            'Autor',
            new Isbn($this->uniqueIsbn()),
            self::CATEGORY_NOVEL,
            new Quantity(1),
            new Quantity(1)
        );

        try {
            $book->updateDetails($book->title(), $book->author(), $book->categoryId(), new Quantity(0));
            self::fail('The domain must reject a zero-copy book before PostgreSQL.');
        } catch (ValidationException) {
            self::assertSame(1, $book->totalCopies()->value);
        }

        DB::table('library.book')->insert([
            'id' => $book->id,
            'title' => $book->title(),
            'author' => $book->author(),
            'isbn' => $book->isbn->value,
            'category_id' => $book->categoryId(),
            'total_copies' => 1,
            'available_copies' => 1,
            'cover_key' => null,
            'deleted_at' => null,
            'version' => 0,
        ]);
        self::assertDatabaseHas('library.book', ['id' => $book->id, 'total_copies' => 1], 'pgsql');

        $exception = $this->databaseCheckViolation(function () use ($book): void {
            DB::table('library.book')->insert([
                'id' => Str::uuid()->toString(),
                'title' => $book->title(),
                'author' => $book->author(),
                'isbn' => $this->uniqueIsbn(),
                'category_id' => $book->categoryId(),
                'total_copies' => 0,
                'available_copies' => 0,
                'cover_key' => null,
                'deleted_at' => null,
                'version' => 0,
            ]);
        });

        self::assertSame('23514', $exception->errorInfo[0]);
        self::assertStringContainsString('ck_book_total_positive', $exception->getMessage());
    }

    public function test_user_role_is_limited_to_admin_and_librarian_in_postgres(): void
    {
        foreach (['admin', 'librarian'] as $role) {
            $user = new User(Str::uuid()->toString(), 'role-'.Str::lower(Str::random(8)), 'hash', $role);
            DB::table('library.user')->insert([
                'id' => $user->id,
                'username' => $user->username(),
                'password_hash' => $user->passwordHash,
                'role' => $role,
            ]);
            self::assertDatabaseHas('library.user', ['id' => $user->id, 'role' => $role], 'pgsql');
        }

        $userId = Str::uuid()->toString();
        $exception = $this->databaseCheckViolation(function () use ($userId): void {
            DB::table('library.user')->insert([
                'id' => $userId,
                'username' => 'invalid-'.Str::lower(Str::random(8)),
                'password_hash' => 'not-a-real-secret-hash',
                'role' => 'superuser',
            ]);
        });

        self::assertSame('23514', $exception->errorInfo[0]);
        self::assertStringContainsString('ck_user_role', $exception->getMessage());
    }

    private function databaseCheckViolation(callable $operation): QueryException
    {
        try {
            DB::transaction($operation);
        } catch (QueryException $exception) {
            return $exception;
        }

        self::fail('PostgreSQL must reject this invalid row with a CHECK violation.');
    }

    private function uniqueIsbn(): string
    {
        $firstTwelve = '978'.str_pad((string) random_int(0, 999_999_999), 9, '0', STR_PAD_LEFT);
        $weightedSum = 0;
        for ($index = 0; $index < 12; $index++) {
            $weightedSum += (int) $firstTwelve[$index] * ($index % 2 === 0 ? 1 : 3);
        }

        return $firstTwelve.(string) ((10 - $weightedSum % 10) % 10);
    }
}
