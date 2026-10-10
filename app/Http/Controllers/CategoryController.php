<?php

namespace App\Http\Controllers;

use Application\Ports\CategoryRepository;
use Illuminate\Http\JsonResponse;

final class CategoryController extends Controller
{
    public function index(CategoryRepository $categories): JsonResponse
    {
        $items = [];
        foreach ($categories->findAllNames() as $id => $name) {
            $items[] = ['id' => $id, 'name' => $name];
        }

        return response()->json(['items' => $items]);
    }
}
