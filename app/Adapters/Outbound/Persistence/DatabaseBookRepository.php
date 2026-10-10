<?php

namespace App\Adapters\Outbound\Persistence;

use Application\DTO\PageResult;
use Application\Ports\BookRepository;
use DateTimeImmutable;
use Domain\Entities\Book;
use Domain\ValueObjects\Isbn;
use Domain\ValueObjects\Quantity;
use Illuminate\Support\Facades\DB;

final class DatabaseBookRepository implements BookRepository
{
    public function findById(string $id): ?Book
    {
        $record = DB::table('library.book')
            ->where('id', $id)
            ->whereNull('deleted_at')
            ->first();

        return $record === null ? null : $this->toDomain($record);
    }

    public function findByIsbn(Isbn $isbn): ?Book
    {
        $record = DB::table('library.book')
            ->where('isbn', $isbn->value)
            ->whereNull('deleted_at')
            ->first();

        return $record === null ? null : $this->toDomain($record);
    }

    public function save(Book $book): void
    {
        DB::table('library.book')->updateOrInsert(
            ['id' => $book->id],
            [
                'title' => $book->title(),
                'author' => $book->author(),
                'isbn' => $book->isbn->value,
                'category_id' => $book->categoryId(),
                'total_copies' => $book->totalCopies()->value,
                'available_copies' => $book->availableCopies()->value,
                'cover_key' => $book->coverKey(),
                'deleted_at' => $book->deletedAt()?->format('Y-m-d H:i:s.uP'),
            ]
        );
    }

    public function findManyByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return DB::table('library.book')
            ->whereIn('id', $ids)
            ->whereNull('deleted_at')
            ->get()
            ->map(fn (object $record): Book => $this->toDomain($record))
            ->all();
    }

    public function search(string $query, ?string $categoryId, int $page, int $size): PageResult
    {
        $books = DB::table('library.book')
            ->whereNull('deleted_at')
            ->when($query !== '', function ($builder) use ($query): void {
                $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $query).'%';
                $builder->where(function ($search) use ($pattern): void {
                    $search->whereRaw("title ILIKE ? ESCAPE '!'", [$pattern])
                        ->orWhereRaw("author ILIKE ? ESCAPE '!'", [$pattern]);
                });
            })
            ->when($categoryId !== null, fn ($builder) => $builder->where('category_id', $categoryId));

        $total = (clone $books)->count();
        $records = $books
            ->orderBy('title')
            ->orderBy('id')
            ->offset(($page - 1) * $size)
            ->limit($size)
            ->get();

        return new PageResult(
            $records->map(fn (object $record): Book => $this->toDomain($record))->all(),
            $page,
            $size,
            $total
        );
    }

    public function hasOpenLoans(string $bookId): bool
    {
        return DB::table('library.loan_item')
            ->where('book_id', $bookId)
            ->whereNull('returned_at')
            ->exists();
    }

    private function toDomain(object $record): Book
    {
        return new Book(
            $record->id,
            $record->title,
            $record->author,
            new Isbn($record->isbn),
            $record->category_id,
            new Quantity((int) $record->total_copies),
            new Quantity((int) $record->available_copies),
            $record->cover_key,
            $record->deleted_at === null ? null : new DateTimeImmutable($record->deleted_at)
        );
    }
}
