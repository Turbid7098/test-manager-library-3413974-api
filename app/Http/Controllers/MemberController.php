<?php

namespace App\Http\Controllers;

use App\Http\Responses\ApiResource;
use Application\Ports\LoanRepository;
use Application\Ports\MemberRepository;
use Application\Services\CreateMemberService;
use Application\Services\SearchMembersService;
use Domain\Exceptions\NotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class MemberController extends Controller
{
    public function index(Request $request, SearchMembersService $search): JsonResponse
    {
        $query = $request->validate([
            'q' => ['sometimes', 'string', 'max:200'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'size' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);
        $result = $search->execute(
            $query['q'] ?? '',
            (int) ($query['page'] ?? 1),
            (int) ($query['size'] ?? 20)
        );

        return response()->json([
            'items' => array_map(
                static fn ($member): array => ApiResource::member($member),
                $result->items
            ),
            'page' => $result->page,
            'size' => $result->size,
            'total' => $result->total,
        ]);
    }

    public function show(string $id, MemberRepository $members, LoanRepository $loans): JsonResponse
    {
        $member = $members->findById($id);
        if ($member === null) {
            throw new NotFoundException('Socio no encontrado.');
        }

        return response()->json(ApiResource::member(
            $member,
            $loans->countActiveItemsForMember($member->id)
        ));
    }

    public function store(Request $request, CreateMemberService $create): JsonResponse
    {
        $input = $request->validate([
            'full_name' => ['required', 'string', 'max:200'],
            'document_number' => ['required', 'string', 'max:30'],
        ]);
        $member = $create->execute($input['full_name'], $input['document_number']);

        return response()->json(ApiResource::member($member, 0), 201);
    }
}
