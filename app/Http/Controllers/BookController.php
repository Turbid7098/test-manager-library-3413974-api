<?php

namespace App\Http\Controllers;

use App\Http\Responses\ApiResource;
use Application\Ports\CategoryRepository;
use Application\Services\CreateBookService;
use Application\Services\DeleteBookService;
use Application\Services\GetBookCoverService;
use Application\Services\GetBookService;
use Application\Services\RemoveBookCoverService;
use Application\Services\SearchBooksService;
use Application\Services\SetBookCoverService;
use Application\Services\UpdateBookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

final class BookController extends Controller
{
    public function index(
        Request $request,
        SearchBooksService $search,
        CategoryRepository $categories
    ): JsonResponse {
        $query = $request->validate([
            'q' => ['sometimes', 'string', 'max:250'],
            'category_id' => ['sometimes', 'uuid'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'size' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);
        $result = $search->execute(
            $query['q'] ?? '',
            $query['category_id'] ?? null,
            (int) ($query['page'] ?? 1),
            (int) ($query['size'] ?? 20)
        );

        return response()->json([
            'items' => ApiResource::books($result->items, $categories),
            'page' => $result->page,
            'size' => $result->size,
            'total' => $result->total,
        ]);
    }

    public function show(string $id, GetBookService $get, CategoryRepository $categories): JsonResponse
    {
        return response()->json(ApiResource::book($get->execute($id), $categories));
    }

    public function store(Request $request, CreateBookService $create, CategoryRepository $categories): JsonResponse
    {
        $input = $request->validate([
            'title' => ['required', 'string', 'max:250'],
            'author' => ['required', 'string', 'max:200'],
            'isbn' => ['required', 'string', 'size:13', 'regex:/^[0-9]{13}$/'],
            'category_id' => ['required', 'uuid'],
            'total_copies' => ['required', 'integer', 'min:1'],
        ]);
        $book = $create->execute(
            $input['title'],
            $input['author'],
            $input['isbn'],
            $input['category_id'],
            (int) $input['total_copies']
        );

        return response()->json(ApiResource::book($book, $categories), 201);
    }

    public function update(
        Request $request,
        string $id,
        UpdateBookService $update,
        CategoryRepository $categories
    ): JsonResponse {
        $input = $request->validate([
            'title' => ['required', 'string', 'max:250'],
            'author' => ['required', 'string', 'max:200'],
            'category_id' => ['required', 'uuid'],
            'total_copies' => ['required', 'integer', 'min:1'],
        ]);
        $book = $update->execute(
            $id,
            $input['title'],
            $input['author'],
            $input['category_id'],
            (int) $input['total_copies']
        );

        return response()->json(ApiResource::book($book, $categories));
    }

    public function destroy(string $id, DeleteBookService $delete): Response
    {
        $delete->execute($id);

        return response()->noContent();
    }

    public function showCover(string $id, GetBookCoverService $get): Response
    {
        $cover = $get->execute($id);

        return response($cover->contents, 200)
            ->header('Content-Type', $cover->mediaType)
            ->header('X-Content-Type-Options', 'nosniff');
    }

    public function storeCover(
        Request $request,
        string $id,
        SetBookCoverService $set,
        CategoryRepository $categories
    ): JsonResponse {
        $input = $request->validate([
            'file' => ['required', 'file', 'max:2048', 'mimetypes:image/jpeg,image/png'],
        ]);
        $file = $input['file'];
        $contents = file_get_contents($file->getRealPath());
        if ($contents === false) {
            throw new RuntimeException('No se pudo leer el archivo de portada recibido.');
        }

        $book = $set->execute($id, $contents, $file->getMimeType() ?? '');

        return response()->json(ApiResource::book($book, $categories));
    }

    public function destroyCover(string $id, RemoveBookCoverService $remove): Response
    {
        $remove->execute($id);

        return response()->noContent();
    }
}
