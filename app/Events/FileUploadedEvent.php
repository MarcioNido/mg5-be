<?php

namespace App\Events;

use App\Models\File;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class FileUploadedEvent
{
    use Dispatchable, SerializesModels;

    public function __construct(public File $file)
    {
    }
}
