<?php

namespace App\Adapters\Outbound\Persistence;

use Application\DTO\MostBorrowedRow;
use Application\Ports\MostBorrowedReportReader;
use Domain\ValueObjects\DateRange;
use Illuminate\Support\Facades\DB;

final class DatabaseMostBorrowedReportReader implements MostBorrowedReportReader
{
    public function mostBorrowed(DateRange $range): array
    {
        return DB::table('library.loan_item as item')
            ->join('library.loan as loan', 'loan.id', '=', 'item.loan_id')
            ->where('loan.loaned_at', '>=', $range->start->format('Y-m-d H:i:s.uP'))
            ->where('loan.loaned_at', '<=', $range->end->format('Y-m-d H:i:s.uP'))
            ->select(['item.book_id', 'item.book_title', 'item.category_name'])
            ->selectRaw('COUNT(*)::integer as times_borrowed')
            ->groupBy('item.book_id', 'item.book_title', 'item.category_name')
            ->orderByDesc('times_borrowed')
            ->orderBy('item.book_title')
            ->get()
            ->map(fn (object $row): MostBorrowedRow => new MostBorrowedRow(
                $row->book_id,
                $row->book_title,
                $row->category_name,
                (int) $row->times_borrowed
            ))
            ->all();
    }
}
