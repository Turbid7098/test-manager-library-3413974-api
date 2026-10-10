<?php

use App\Http\Middleware\AuthenticateJwt;
use App\Http\Responses\ApiErrorResponse;
use Domain\Exceptions\ConflictException;
use Domain\Exceptions\DomainException;
use Domain\Exceptions\NotFoundException;
use Domain\Exceptions\ValidationException as DomainValidationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException as LaravelValidationException;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'jwt' => AuthenticateJwt::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(function (DomainException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            $status = match (true) {
                $exception instanceof ConflictException => Response::HTTP_CONFLICT,
                $exception instanceof NotFoundException => Response::HTTP_NOT_FOUND,
                $exception instanceof DomainValidationException => Response::HTTP_UNPROCESSABLE_ENTITY,
                $exception->errorCode === 'INVALID_CREDENTIALS' => Response::HTTP_UNAUTHORIZED,
                default => Response::HTTP_INTERNAL_SERVER_ERROR,
            };

            return ApiErrorResponse::make(
                $exception->errorCode,
                $exception->getMessage(),
                $exception->details,
                $status
            );
        });

        $exceptions->render(function (LaravelValidationException $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            $details = [];
            foreach ($exception->errors() as $field => $messages) {
                foreach ($messages as $message) {
                    $details[] = ['field' => (string) $field, 'issue' => (string) $message];
                }
            }

            return ApiErrorResponse::make(
                'VALIDATION_ERROR',
                'Los datos enviados no son válidos.',
                $details,
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        });
    })->create();
