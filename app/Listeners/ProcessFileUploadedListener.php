<?php

namespace App\Listeners;

use App\Events\FileUploadedEvent;
use App\Services\CsvImportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Storage;
use Spatie\Multitenancy\Jobs\TenantAware;

class ProcessFileUploadedListener implements ShouldQueue, TenantAware
{
    /**
     * @throws UnsupportedFileTypeException
     */
    public function handle(FileUploadedEvent $event): void
    {
        $filePath = Storage::path($event->file->filename);
        app(CsvImportService::class)->process($event->file, $filePath);
    }
}
