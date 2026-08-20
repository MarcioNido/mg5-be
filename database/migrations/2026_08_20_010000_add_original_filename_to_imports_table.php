<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('imports', function (Blueprint $table): void {
            $table->string('original_filename')->nullable()->after('filename');
        });
    }

    public function down(): void
    {
        Schema::table('imports', function (Blueprint $table): void {
            $table->dropColumn('original_filename');
        });
    }
};
