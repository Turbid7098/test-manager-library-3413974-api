<?php

namespace App\Http\Responses;

use Application\Ports\CategoryRepository;
use DateTimeImmutable;
use DateTimeZone;
use Domain\Entities\Book;
use Domain\Entities\Loan;
use Domain\Entities\LoanItem;
use Domain\Entities\Member;
use RuntimeException;

final class ApiResource
{
    /** @return array<string, mixed> */
    public static function book(Book $book, CategoryRepository $categories): array
    {
        $categoryName = $categories->findNamesByIds([$book->categoryId()])[$book->categoryId()] ?? null;
        if ($categoryName === null) {
            throw new RuntimeException('No se encontró la categoría del libro para construir la respuesta.');
        }

        return self::bookWithCategoryName($book, $categoryName);
    }

    /** @param list<Book> $books
     * @return list<array<string, mixed>>
     */
    public static function books(array $books, CategoryRepository $categories): array
    {
        $categoryIds = array_values(array_unique(array_map(
            static fn (Book $book): string => $book->categoryId(),
            $books
        )));
        $categoryNames = $categories->findNamesByIds($categoryIds);

        return array_map(
            static function (Book $book) use ($categoryNames): array {
                $categoryName = $categoryNames[$book->categoryId()] ?? null;
                if ($categoryName === null) {
                    throw new RuntimeException('No se encontró la categoría del libro para construir la respuesta.');
                }

                return self::bookWithCategoryName($book, $categoryName);
            },
            $books
        );
    }

    /** @return array<string, mixed> */
    private static function bookWithCategoryName(Book $book, string $categoryName): array
    {
        return [
            'id' => $book->id,
            'title' => $book->title(),
            'author' => $book->author(),
            'isbn' => $book->isbn->value,
            'category' => ['id' => $book->categoryId(), 'name' => $categoryName],
            'total_copies' => $book->totalCopies()->value,
            'available_copies' => $book->availableCopies()->value,
            'cover_key' => $book->coverKey(),
        ];
    }

    /** @return array<string, mixed> */
    public static function member(Member $member, ?int $activeLoans = null): array
    {
        $resource = [
            'id' => $member->id,
            'full_name' => $member->fullName(),
            'document_number' => $member->documentNumber->value,
        ];
        if ($activeLoans !== null) {
            $resource['active_loans'] = $activeLoans;
        }

        return $resource;
    }

    /** @return array<string, mixed> */
    public static function loan(Loan $loan, string $status): array
    {
        return [
            'id' => $loan->id,
            'member_id' => $loan->memberId,
            'loaned_at' => self::dateTime($loan->loanedAt),
            'status' => $status,
            'items' => array_map(self::loanItem(...), $loan->getItems()),
        ];
    }

    /** @return array<string, mixed> */
    public static function loanItem(LoanItem $item): array
    {
        return [
            'id' => $item->id,
            'book_id' => $item->bookId,
            'book_title' => $item->bookTitle,
            'isbn' => $item->isbn->value,
            'category_name' => $item->categoryName,
            'due_at' => self::dateTime($item->dueAt),
            'returned_at' => $item->returnedAt() === null ? null : self::dateTime($item->returnedAt()),
        ];
    }

    public static function dateTime(DateTimeImmutable $dateTime): string
    {
        return $dateTime->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
}
