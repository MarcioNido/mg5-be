<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreFileRequest;
use App\Http\Resources\FileResource;
use App\Models\File;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class FileController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return FileResource::collection(File::latest()->limit(10)->get());
    }

    public function store(StoreFileRequest $request): bool|string
    {
        $path = $request->file('file')->store('files');

        File::create([
            'filename' => $path,
        ]);

        return $path;
    }
}
