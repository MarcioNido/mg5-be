<?php

namespace App\Http\Controllers;

use App\Enums\ImportStatus;
use App\Events\FileUploadedEvent;
use App\Http\Requests\IndexFileRequest;
use App\Http\Requests\StoreFileRequest;
use App\Http\Resources\FileResource;
use App\Models\Account;
use App\Models\File;
use App\Services\CsvImportService;
use App\Services\FileReader\UnsupportedFileTypeException;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class FileController extends Controller
{
    public function index(IndexFileRequest $request): AnonymousResourceCollection
    {
        $filters = $request->validated();

        return FileResource::collection(
            File::query()
                ->with('account')
                ->when(
                    isset($filters['account_id']),
                    fn ($query) => $query->where('account_id', $filters['account_id'])
                )
                ->when(
                    isset($filters['status']),
                    fn ($query) => $query->where('status', $filters['status'])
                )
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->paginate($filters['per_page'] ?? 15)
                ->withQueryString()
        );
    }

    public function show(File $file): FileResource
    {
        return new FileResource($file->load([
            'account',
            'rows' => fn ($query) => $query->orderBy('line_number'),
        ]));
    }

    public function store(StoreFileRequest $request, CsvImportService $imports): FileResource
    {
        $uploaded = $request->file('file');
        $path = $uploaded->store('files');

        try {
            $file = $imports->create(
                Account::query()->findOrFail($request->integer('account_id')),
                $path,
                $uploaded->getClientOriginalName(),
                Storage::path($path)
            );
        } catch (UnsupportedFileTypeException) {
            Storage::delete($path);

            throw ValidationException::withMessages([
                'file' => 'Unsupported CSV format. Upload an RBC or Triangle statement export.',
            ]);
        } catch (Throwable $exception) {
            Storage::delete($path);

            throw $exception;
        }

        $duplicateUpload = ! $file->wasRecentlyCreated;

        if ($duplicateUpload && $file->filename !== $path) {
            Storage::delete($path);
        }

        $retryingFailedRows = $duplicateUpload && $file->status === ImportStatus::CompleteWithErrors;

        if (! $duplicateUpload || $retryingFailedRows) {
            FileUploadedEvent::dispatch($file);
        }

        return (new FileResource($file->refresh()->load('account')))->additional([
            'meta' => ['duplicate_upload' => $duplicateUpload],
        ]);
    }
}
