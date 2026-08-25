<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('rules')
            ->select(['id', 'content'])
            ->orderBy('id')
            ->chunkById(100, function ($rules): void {
                foreach ($rules as $rule) {
                    if (! str_starts_with($rule->content, '%') || ! str_ends_with($rule->content, '%')) {
                        continue;
                    }

                    $literal = substr($rule->content, 1, -1);
                    if (trim($literal) === '') {
                        continue;
                    }

                    DB::table('rules')->where('id', $rule->id)->update(['content' => $literal]);
                }
            });
    }

    public function down(): void
    {
        // Which values were legacy LIKE patterns cannot be inferred after conversion.
    }
};
