<?php

namespace App\Http\Controllers;

use App\Http\Responses\ApiResource;
use Application\DTO\OverdueLoanItem;
use Application\Ports\Clock;
use Application\Services\GetLoanService;
use Application\Services\LendBooksService;
use Application\Services\ListOverdueLoansService;
use Application\Services\ReturnLoanItemService;
use Application\Services\SearchLoansService;
use Domain\Entities\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

final class LoanController extends Controller
{
    public function index(Request $request, SearchLoansService $search): JsonResponse
    {
        $query = $request->validate([
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d'],
            'status' => ['sometimes', 'in:open,overdue,closed'],
            'member_id' => ['sometimes', 'uuid'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'size' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);
        $result = $search->execute(
            isset($query['from']) ? $this->dayStart($query['from']) : null,
            isset($query['to']) ? $this->dayEnd($query['to']) : null,
            $query['status'] ?? null,
            $query['member_id'] ?? null,
            (int) ($query['page'] ?? 1),
            (int) ($query['size'] ?? 20)
        );

        return response()->json([
            'items' => array_map(
                static fn (array $item): array => ApiResource::loan($item['loan'], $item['status']),
                $result->items
            ),
            'page' => $result->page,
            'size' => $result->size,
            'total' => $result->total,
        ]);
    }

    public function overdue(ListOverdueLoansService $list): JsonResponse
    {
        return response()->json([
            'items' => array_map(
                static fn (OverdueLoanItem $item): array => [
                    'loan_id' => $item->loanId,
                    'item_id' => $item->itemId,
                    'book_title' => $item->bookTitle,
                    'member_name' => $item->memberName,
                    'due_at' => ApiResource::dateTime($item->dueAt),
                    'days_late' => $item->daysLate,
                ],
                $list->execute()
            ),
        ]);
    }

    public function store(Request $request, LendBooksService $lend, Clock $clock): JsonResponse
    {
        $input = $request->validate([
            'member_id' => ['required', 'uuid'],
            'items' => ['required', 'array', 'min:1', 'max:3'],
            'items.*.book_id' => ['required', 'uuid'],
        ]);
        $operator = $request->attributes->get('auth_user');
        if (! $operator instanceof User) {
            throw new RuntimeException('El middleware JWT no proporcionó el usuario autenticado.');
        }

        $loan = $lend->execute(
            $input['member_id'],
            $operator->id,
            array_column($input['items'], 'book_id')
        );

        return response()->json(ApiResource::loan($loan, $loan->getStatus($clock->now())), 201);
    }

    public function show(string $id, GetLoanService $get): JsonResponse
    {
        $result = $get->execute($id);

        return response()->json(ApiResource::loan($result['loan'], $result['status']));
    }

    public function returnItem(
        string $loanId,
        string $itemId,
        ReturnLoanItemService $return
    ): JsonResponse {
        $item = $return->execute($loanId, $itemId);

        return response()->json(ApiResource::loanItem($item));
    }
}
