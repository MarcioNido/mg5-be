<?php

namespace App\Jobs;

use App\Models\Rule;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Spatie\Multitenancy\Jobs\TenantAware;

class ProcessAllRules implements ShouldQueue, TenantAware
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public $force = false) {}

    public function handle(): void
    {
        Rule::query()
            ->orderBy('id')
            ->each(fn (Rule $rule) => (new ProcessRule($rule, $this->force))->handle());
    }
}
