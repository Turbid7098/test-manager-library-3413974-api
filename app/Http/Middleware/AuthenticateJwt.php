<?php

namespace App\Http\Middleware;

use App\Http\Responses\ApiErrorResponse;
use Application\Ports\TokenVerifier;
use Application\Ports\UserRepository;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class AuthenticateJwt
{
    public function __construct(
        private readonly TokenVerifier $tokens,
        private readonly UserRepository $users
    ) {}

    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $authorization = $request->header('Authorization', '');
        if (preg_match('/^Bearer\s+([^\s]+)$/iD', $authorization, $matches) !== 1) {
            return $this->unauthorized();
        }

        $claims = $this->tokens->verify($matches[1]);
        if ($claims === null) {
            return $this->unauthorized();
        }

        $user = $this->users->findById($claims['sub']);
        if ($user === null || $user->role !== $claims['role']) {
            return $this->unauthorized();
        }
        if ($roles !== [] && ! in_array($user->role, $roles, true)) {
            return ApiErrorResponse::make(
                'FORBIDDEN_ROLE',
                'No tiene permisos para realizar esta operación.',
                [],
                Response::HTTP_FORBIDDEN
            );
        }

        $request->attributes->set('auth_user', $user);

        return $next($request);
    }

    private function unauthorized(): Response
    {
        return ApiErrorResponse::make(
            'UNAUTHENTICATED',
            'Se requiere un token de acceso válido.',
            [],
            Response::HTTP_UNAUTHORIZED
        );
    }
}
