<?php

namespace App\Services\FileReader;

use Exception;

class UnsupportedFileTypeException extends Exception
{
    protected $message = "Unsupported file type";
}
