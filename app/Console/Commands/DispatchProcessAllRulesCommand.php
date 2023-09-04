<?php

namespace App\Console\Commands;

use App\Jobs\ProcessAllRules;
use Illuminate\Console\Command;

class DispatchProcessAllRulesCommand extends Command
{
    protected $signature = "dispatch:process-all-rules {--force=false}";
    protected $description = "Dispatch process all rules job";

    public function handle(): void
    {
        ProcessAllRules::dispatch($this->option("force"));
    }
}
