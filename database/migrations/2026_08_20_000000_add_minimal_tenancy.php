<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const FINANCIAL_TABLES = [
        'accounts',
        'transactions',
        'categories',
        'rules',
        'files',
        'balances',
    ];

    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->timestamps();
        });

        Schema::create('tenant_user', function (Blueprint $table): void {
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->primary(['tenant_id', 'user_id']);
        });

        $now = now();
        $personalTenantId = DB::table('tenants')->insertGetId([
            'name' => 'Personal',
            'slug' => 'personal',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $clinicTenantId = DB::table('tenants')->insertGetId([
            'name' => 'Clinic',
            'slug' => 'clinic',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('users')->orderBy('id')->pluck('id')->chunk(500)->each(
            function ($userIds) use ($personalTenantId, $clinicTenantId): void {
                $memberships = [];

                foreach ($userIds as $userId) {
                    $memberships[] = ['tenant_id' => $personalTenantId, 'user_id' => $userId];
                    $memberships[] = ['tenant_id' => $clinicTenantId, 'user_id' => $userId];
                }

                DB::table('tenant_user')->insertOrIgnore($memberships);
            }
        );

        foreach (self::FINANCIAL_TABLES as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->unsignedBigInteger('tenant_id')->nullable()->index();
            });

            DB::table($tableName)->update(['tenant_id' => $personalTenantId]);

            Schema::table($tableName, function (Blueprint $table): void {
                $table->unsignedBigInteger('tenant_id')->nullable(false)->change();
                $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            });
        }

        Schema::table('transactions', function (Blueprint $table): void {
            $table->dropUnique('full');
            $table->unique(
                ['tenant_id', 'account_number', 'transaction_date', 'description', 'amount'],
                'transactions_tenant_fingerprint_unique'
            );
        });

        Schema::table('balances', function (Blueprint $table): void {
            $table->dropUnique(['account_number', 'last_day_of_month']);
            $table->unique(
                ['tenant_id', 'account_number', 'last_day_of_month'],
                'balances_tenant_account_month_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table): void {
            $table->dropUnique('transactions_tenant_fingerprint_unique');
            $table->unique(
                ['account_number', 'transaction_date', 'description', 'amount'],
                'full'
            );
        });

        Schema::table('balances', function (Blueprint $table): void {
            $table->dropUnique('balances_tenant_account_month_unique');
            $table->unique(['account_number', 'last_day_of_month']);
        });

        foreach (array_reverse(self::FINANCIAL_TABLES) as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropForeign(['tenant_id']);
                $table->dropColumn('tenant_id');
            });
        }

        Schema::dropIfExists('tenant_user');
        Schema::dropIfExists('tenants');
    }
};
