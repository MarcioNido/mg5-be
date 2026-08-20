<?php

namespace App\Listeners;

use App\Events\FileUploadedEvent;
use App\Events\TransactionsUpdatedEvent;
use App\Services\FileReader\FileReaderFactory;
use App\Services\FileReader\UnsupportedFileTypeException;
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
        $fileReader = FileReaderFactory::make($filePath);
        $fileReader->processFile();
        $event->file->update(['status' => 'complete']);
        TransactionsUpdatedEvent::dispatch();
    }
}
