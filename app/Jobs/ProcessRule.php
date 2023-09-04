<?php

namespace App\Jobs;

use App\Models\Rule;
use App\Models\Transaction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessRule implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(public Rule $rule, public $force = false)
    {
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle(): void
    {
        Transaction::query()
            ->when(!$this->force, function ($query) {
                $query->whereNull("category_id");
            })
            ->where("description", "like", "{$this->rule->content}")
            ->where("account_number", $this->rule->account_number)
            ->update(["category_id" => $this->rule->category_id]);
    }
}
