<?php

namespace App\Http\Controllers;

use Application\Services\MostBorrowedReportService;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ReportController extends Controller
{
    public function mostBorrowed(Request $request, MostBorrowedReportService $report): JsonResponse
    {
        $query = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d'],
        ]);
        $from = new DateTimeImmutable($query['from'].'T00:00:00Z', new DateTimeZone('UTC'));
        $to = new DateTimeImmutable($query['to'].'T23:59:59.999999Z', new DateTimeZone('UTC'));
        $rows = $report->execute($from, $to);

        return response()->json([
            'from' => $query['from'],
            'to' => $query['to'],
            'rows' => array_map(
                static fn ($row): array => [
                    'book_id' => $row->bookId,
                    'book_title' => $row->bookTitle,
                    'category_name' => $row->categoryName,
                    'times_borrowed' => $row->timesBorrowed,
                ],
                $rows
            ),
        ]);
    }
}
