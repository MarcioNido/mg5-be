<?php

namespace App\Http\Controllers;

use App\Events\FileUploadedEvent;
use App\Http\Requests\StoreFileRequest;
use App\Http\Resources\FileResource;
use App\Models\File;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;

class FileController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return FileResource::collection(File::latest()->limit(10)->get());
    }

    public function store(StoreFileRequest $request): bool|string
    {
        $path = $request->file('file')->store('files');

        $file = File::create([
            'filename' => $path,
        ]);

        FileUploadedEvent::dispatch($file);

        return $file;
    }
}
