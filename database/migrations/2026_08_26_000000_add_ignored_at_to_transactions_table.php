<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table): void {
            $table->timestamp('ignored_at')->nullable()->after('posted_at');
            $table->index(['tenant_id', 'ignored_at']);
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table): void {
            $table->dropIndex(['tenant_id', 'ignored_at']);
            $table->dropColumn('ignored_at');
        });
    }
};
