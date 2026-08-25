<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('categories')->whereIn('type', [
            'deductions',
            'fixed expenses',
            'variable expenses',
            'expense',
        ])->update(['type' => 'expense']);

        DB::table('categories')
            ->where('type', 'financial transactions')
            ->update(['type' => 'transfer']);
    }

    public function down(): void
    {
        // The legacy expense kinds cannot be reconstructed without losing meaning.
    }
};
