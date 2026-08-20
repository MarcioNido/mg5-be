<?php

namespace App\Console\Commands;

use App\Jobs\ProcessAllRules;
use App\Models\Tenant;
use Illuminate\Console\Command;

class DispatchProcessAllRulesCommand extends Command
{
    protected $signature = 'dispatch:process-all-rules {--force=false}';

    protected $description = 'Dispatch process all rules job';

    public function handle(): void
    {
        Tenant::query()->each(function (Tenant $tenant): void {
            $tenant->execute(function (): void {
                ProcessAllRules::dispatch(
                    filter_var($this->option('force'), FILTER_VALIDATE_BOOL)
                );
            });
        });
    }
}
