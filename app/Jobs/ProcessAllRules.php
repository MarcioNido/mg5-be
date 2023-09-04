<?php

namespace App\Jobs;

use App\Models\Rule;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessAllRules implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public $force = false)
    {
    }

    public function handle(): void
    {
        $rules = Rule::query()->get();
        foreach ($rules as $rule) {
            ProcessRule::dispatch($rule, $this->force);
        }
    }
}
