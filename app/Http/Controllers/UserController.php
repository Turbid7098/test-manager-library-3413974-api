<?php

namespace App\Http\Controllers;

use Application\Services\CreateLibrarianService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class UserController extends Controller
{
    public function store(Request $request, CreateLibrarianService $create): JsonResponse
    {
        $input = $request->validate([
            'username' => ['required', 'string', 'max:120'],
            'password' => ['required', 'string', 'max:255'],
            'role' => ['required', 'string', 'in:librarian'],
        ]);
        $user = $create->execute($input['username'], $input['password'], $input['role']);

        return response()->json([
            'id' => $user->id,
            'username' => $user->username(),
            'role' => $user->role,
        ], 201);
    }
}
