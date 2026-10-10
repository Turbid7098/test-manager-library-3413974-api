<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\BookController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\LoanController;
use App\Http\Controllers\MemberController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::get('/health', function () {
        return response()->json(['status' => 'ok']);
    });

    Route::post('/auth/login', [AuthController::class, 'login']);

    Route::middleware('jwt')->group(function (): void {
        Route::get('/categories', [CategoryController::class, 'index']);

        Route::get('/books', [BookController::class, 'index']);
        Route::get('/books/{id}', [BookController::class, 'show']);
        Route::get('/books/{id}/cover', [BookController::class, 'showCover']);

        Route::get('/members', [MemberController::class, 'index']);
        Route::get('/members/{id}', [MemberController::class, 'show']);
        Route::post('/members', [MemberController::class, 'store']);

        Route::get('/loans/overdue', [LoanController::class, 'overdue']);
        Route::get('/loans', [LoanController::class, 'index']);
        Route::post('/loans', [LoanController::class, 'store']);
        Route::get('/loans/{id}', [LoanController::class, 'show']);
        Route::post('/loans/{loanId}/items/{itemId}/return', [LoanController::class, 'returnItem']);
    });

    Route::middleware('jwt:admin')->group(function (): void {
        Route::post('/users', [UserController::class, 'store']);
        Route::post('/books', [BookController::class, 'store']);
        Route::put('/books/{id}', [BookController::class, 'update']);
        Route::delete('/books/{id}', [BookController::class, 'destroy']);
        Route::put('/books/{id}/cover', [BookController::class, 'storeCover']);
        Route::delete('/books/{id}/cover', [BookController::class, 'destroyCover']);
        Route::get('/reports/most-borrowed', [ReportController::class, 'mostBorrowed']);
    });
});
