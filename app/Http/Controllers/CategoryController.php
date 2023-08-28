<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCategoryRequest;
use App\Http\Requests\UpdateCategoryRequest;
use App\Http\Resources\CategoryResource;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class CategoryController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return CategoryResource::collection(
            Category::filters($request->get("filter"))
                ->orders($request->get("orderBy"))
                //                ->whereNull("parent_id")
                ->with("children")
                ->get()
        );
    }

    public function store(StoreCategoryRequest $request): CategoryResource
    {
        $data = $request->validated();
        if (isset($data["parent"]["id"])) {
            $data["parent_id"] = $data["parent"]["id"];
        }
        unset($data["parent"]);

        return CategoryResource::make(Category::query()->create($data));
    }

    public function show(Category $category): CategoryResource
    {
        return CategoryResource::make($category->load(["parent", "children"]));
    }

    public function update(
        UpdateCategoryRequest $request,
        Category $category
    ): CategoryResource {
        return CategoryResource::make(
            tap($category)->update($request->validated())
        );
    }

    public function destroy(Category $category): Response
    {
        $category->delete();
        return response()->noContent();
    }
}
