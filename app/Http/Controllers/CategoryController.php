<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCategoryRequest;
use App\Http\Requests\UpdateCategoryRequest;
use App\Http\Resources\CategoryResource;
use App\Models\Category;
use App\Services\CategoryService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class CategoryController extends Controller
{
    public function __construct(private readonly CategoryService $categories) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return CategoryResource::collection(
            Category::query()
                ->with('parent')
                ->orderBy('type')
                ->orderByRaw('LOWER(name)')
                ->orderBy('id')
                ->get()
        );
    }

    public function store(StoreCategoryRequest $request): CategoryResource
    {
        return CategoryResource::make(
            $this->categories->create($request->validated())->load('parent')
        );
    }

    public function show(Category $category): CategoryResource
    {
        return CategoryResource::make($category->load([
            'parent',
            'children' => fn ($query) => $query->orderByRaw('LOWER(name)')->orderBy('id'),
        ]));
    }

    public function update(
        UpdateCategoryRequest $request,
        Category $category
    ): CategoryResource {
        return CategoryResource::make(
            $this->categories->update($category, $request->validated())->load('parent')
        );
    }

    public function destroy(Category $category): Response
    {
        $this->categories->delete($category);

        return response()->noContent();
    }
}
