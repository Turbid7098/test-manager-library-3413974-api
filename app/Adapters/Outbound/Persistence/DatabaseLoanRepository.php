<?php

namespace App\Adapters\Outbound\Persistence;

use Application\DTO\OverdueLoanItem;
use Application\Ports\LoanRepository;
use DateTimeImmutable;
use Domain\Entities\Loan;
use Domain\Entities\LoanItem;
use Domain\Exceptions\ConflictException;
use Domain\ValueObjects\Isbn;
use Illuminate\Support\Facades\DB;

final class DatabaseLoanRepository implements LoanRepository
{
    public function findById(string $id): ?Loan
    {
        $record = DB::table('library.loan')->where('id', $id)->first();
        if ($record === null) {
            return null;
        }

        $items = DB::table('library.loan_item')
            ->where('loan_id', $id)
            ->orderBy('id')
            ->get()
            ->map(fn (object $item): LoanItem => $this->toItem($item))
            ->all();

        return new Loan(
            $record->id,
            $record->member_id,
            $record->loaned_by_user_id,
            new DateTimeImmutable($record->loaned_at),
            $items
        );
    }

    public function save(Loan $loan): void
    {
        $exists = DB::table('library.loan')->where('id', $loan->id)->exists();

        if (! $exists) {
            DB::table('library.loan')->insert([
                'id' => $loan->id,
                'member_id' => $loan->memberId,
                'loaned_by_user_id' => $loan->loanedByUserId,
                'loaned_at' => $loan->loanedAt->format('Y-m-d H:i:s.uP'),
            ]);
            DB::table('library.loan_item')->insert(array_map(
                fn (LoanItem $item): array => $this->itemValues($item),
                $loan->getItems()
            ));

            return;
        }

        foreach ($loan->getItems() as $item) {
            $returnedAt = $item->returnedAt();
            if ($returnedAt === null) {
                continue;
            }

            $updated = DB::table('library.loan_item')
                ->where('id', $item->id)
                ->where('loan_id', $loan->id)
                ->whereNull('returned_at')
                ->update(['returned_at' => $returnedAt->format('Y-m-d H:i:s.uP')]);

            if ($updated === 0) {
                $persisted = DB::table('library.loan_item')
                    ->where('id', $item->id)
                    ->where('loan_id', $loan->id)
                    ->value('returned_at');
                if ($persisted === null) {
                    throw new ConflictException('La línea del préstamo ya no existe.', 'LOAN_ITEM_NOT_FOUND');
                }
            }
        }
    }

    public function countActiveItemsForMember(string $memberId): int
    {
        return DB::table('library.loan_item')
            ->join('library.loan', 'library.loan.id', '=', 'library.loan_item.loan_id')
            ->where('library.loan.member_id', $memberId)
            ->whereNull('library.loan_item.returned_at')
            ->count();
    }

    public function memberHasOverdueLoans(string $memberId, DateTimeImmutable $now): bool
    {
        return DB::table('library.loan_item')
            ->join('library.loan', 'library.loan.id', '=', 'library.loan_item.loan_id')
            ->where('library.loan.member_id', $memberId)
            ->whereNull('library.loan_item.returned_at')
            ->where('library.loan_item.due_at', '<', $now->format('Y-m-d H:i:s.uP'))
            ->exists();
    }

    public function findOverdueItems(DateTimeImmutable $now): array
    {
        $items = DB::table('library.loan_item as item')
            ->join('library.loan as loan', 'loan.id', '=', 'item.loan_id')
            ->join('library.member as member', 'member.id', '=', 'loan.member_id')
            ->whereNull('item.returned_at')
            ->where('item.due_at', '<', $now->format('Y-m-d H:i:s.uP'))
            ->select([
                'loan.id as loan_id',
                'item.id as item_id',
                'item.book_title',
                'member.full_name as member_name',
                'item.due_at',
            ])
            ->selectRaw(
                'GREATEST(0, FLOOR(EXTRACT(EPOCH FROM (?::timestamptz - item.due_at)) / 86400))::integer as days_late',
                [$now->format('Y-m-d H:i:s.uP')]
            )
            ->orderByDesc('days_late')
            ->orderBy('item.due_at')
            ->get();

        return $items->map(
            fn (object $item): OverdueLoanItem => new OverdueLoanItem(
                $item->loan_id,
                $item->item_id,
                $item->book_title,
                $item->member_name,
                new DateTimeImmutable($item->due_at),
                (int) $item->days_late
            )
        )->all();
    }

    private function toItem(object $record): LoanItem
    {
        return new LoanItem(
            $record->id,
            $record->loan_id,
            $record->book_id,
            $record->book_title,
            new Isbn($record->isbn),
            $record->category_name,
            new DateTimeImmutable($record->due_at),
            $record->returned_at === null ? null : new DateTimeImmutable($record->returned_at)
        );
    }

    /** @return array<string, mixed> */
    private function itemValues(LoanItem $item): array
    {
        return [
            'id' => $item->id,
            'loan_id' => $item->loanId,
            'book_id' => $item->bookId,
            'book_title' => $item->bookTitle,
            'isbn' => $item->isbn->value,
            'category_name' => $item->categoryName,
            'due_at' => $item->dueAt->format('Y-m-d H:i:s.uP'),
            'returned_at' => $item->returnedAt()?->format('Y-m-d H:i:s.uP'),
        ];
    }
}
