<?php

namespace App\Http\Controllers;

use App\Events\FileUploadedEvent;
use App\Http\Requests\StoreFileRequest;
use App\Http\Resources\FileResource;
use App\Models\Account;
use App\Models\File;
use App\Services\CsvImportService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;

class FileController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return FileResource::collection(File::latest()->limit(10)->get());
    }

    public function show(File $file): FileResource
    {
        return new FileResource($file->load(['account', 'rows.suggestions.pendingTransaction']));
    }

    public function store(StoreFileRequest $request, CsvImportService $imports): FileResource
    {
        $uploaded = $request->file('file');
        $path = $uploaded->store('files');
        $file = $imports->create(
            Account::query()->findOrFail($request->integer('account_id')),
            $path,
            $uploaded->getClientOriginalName(),
            Storage::path($path)
        );

        if (! $file->wasRecentlyCreated && $file->filename !== $path) {
            Storage::delete($path);
        }

        if ($file->wasRecentlyCreated) {
            FileUploadedEvent::dispatch($file);
        }

        return new FileResource($file->load('rows.suggestions'));
    }
}
