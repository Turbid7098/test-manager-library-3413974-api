<?php

namespace App\Http\Controllers;

use Application\Services\LoginService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AuthController extends Controller
{
    public function login(Request $request, LoginService $login): JsonResponse
    {
        $credentials = $request->validate([
            'username' => ['required', 'string', 'max:120'],
            'password' => ['required', 'string'],
        ], [
            'username.required' => 'El nombre de usuario es obligatorio.',
            'username.string' => 'El nombre de usuario no es válido.',
            'username.max' => 'El nombre de usuario supera el máximo permitido.',
            'password.required' => 'La contraseña es obligatoria.',
            'password.string' => 'La contraseña no es válida.',
        ]);

        return response()->json($login->execute($credentials['username'], $credentials['password']));
    }
}
