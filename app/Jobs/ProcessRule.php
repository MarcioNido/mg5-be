<?php

namespace App\Jobs;

use App\Models\Rule;
use App\Models\Transaction;
use App\Support\LiteralContains;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Spatie\Multitenancy\Jobs\TenantAware;

class ProcessRule implements ShouldQueue, TenantAware
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(public Rule $rule, public $force = false) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $query = Transaction::query()
            ->whereDoesntHave('splits')
            ->when(! $this->force, fn ($query) => $query->whereNull('category_id'))
            ->when($this->rule->account_id, fn ($query) => $query->where('account_id', $this->rule->account_id));

        LiteralContains::apply($query, 'description', $this->rule->content)
            ->update(['category_id' => $this->rule->category_id]);
    }
}
