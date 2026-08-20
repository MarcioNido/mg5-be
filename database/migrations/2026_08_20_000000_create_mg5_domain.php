<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
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

        Schema::create('accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('account_number')->nullable();
            $table->string('name');
            $table->string('type', 40);
            $table->char('currency', 3)->default('CAD');
            $table->decimal('opening_balance', 19, 4)->default(0);
            $table->date('opening_balance_date')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['tenant_id', 'account_number']);
            $table->unique(['tenant_id', 'id']);
        });

        Schema::create('categories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->string('name');
            $table->unsignedSmallInteger('level')->default(1);
            $table->string('type', 60);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['tenant_id', 'id']);
            $table->foreign(['tenant_id', 'parent_id'])->references(['tenant_id', 'id'])->on('categories')->restrictOnDelete();
        });

        Schema::create('imports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('account_id');
            $table->string('filename');
            $table->string('source_name', 80)->nullable();
            $table->string('source_type', 40)->nullable();
            $table->string('status', 40)->default('pending');
            $table->char('file_fingerprint', 64);
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('processed_rows')->default(0);
            $table->unsignedInteger('failed_rows')->default(0);
            $table->text('error_message')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'account_id', 'file_fingerprint']);
            $table->unique(['tenant_id', 'id']);
            $table->foreign(['tenant_id', 'account_id'])->references(['tenant_id', 'id'])->on('accounts')->restrictOnDelete();
        });

        Schema::create('transactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('account_id');
            $table->unsignedBigInteger('category_id')->nullable();
            $table->date('transaction_date');
            $table->string('description');
            $table->text('notes')->nullable();
            $table->decimal('amount', 19, 4);
            $table->string('status', 20)->default('pending');
            $table->string('origin', 20)->default('manual');
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'account_id', 'status', 'transaction_date'], 'transactions_balance_lookup');
            $table->foreign(['tenant_id', 'account_id'])->references(['tenant_id', 'id'])->on('accounts')->restrictOnDelete();
            $table->foreign(['tenant_id', 'category_id'])->references(['tenant_id', 'id'])->on('categories')->restrictOnDelete();
        });

        Schema::create('imported_movements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('account_id');
            $table->unsignedBigInteger('transaction_id')->nullable();
            $table->string('source_name', 80);
            $table->char('fingerprint', 64);
            $table->unsignedInteger('occurrence');
            $table->timestamps();
            $table->unique(['tenant_id', 'id']);
            $table->unique(
                ['tenant_id', 'account_id', 'source_name', 'fingerprint', 'occurrence'],
                'imported_movements_identity_unique'
            );
            $table->foreign(['tenant_id', 'account_id'])->references(['tenant_id', 'id'])->on('accounts')->restrictOnDelete();
            $table->foreign(['tenant_id', 'transaction_id'])->references(['tenant_id', 'id'])->on('transactions')->restrictOnDelete();
        });

        Schema::create('import_rows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('import_id');
            $table->unsignedBigInteger('account_id');
            $table->unsignedBigInteger('transaction_id')->nullable();
            $table->unsignedBigInteger('imported_movement_id')->nullable();
            $table->unsignedInteger('line_number');
            $table->json('raw_payload');
            $table->json('normalized_payload')->nullable();
            $table->char('fingerprint', 64);
            $table->unsignedInteger('occurrence')->nullable();
            $table->string('status', 40)->default('pending');
            $table->text('error_message')->nullable();
            $table->timestamps();
            $table->unique(['import_id', 'line_number']);
            $table->index(['tenant_id', 'fingerprint']);
            $table->unique(['tenant_id', 'id']);
            $table->foreign(['tenant_id', 'import_id'])->references(['tenant_id', 'id'])->on('imports')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'account_id'])->references(['tenant_id', 'id'])->on('accounts')->restrictOnDelete();
            $table->foreign(['tenant_id', 'transaction_id'])->references(['tenant_id', 'id'])->on('transactions')->restrictOnDelete();
            $table->foreign(['tenant_id', 'imported_movement_id'])->references(['tenant_id', 'id'])->on('imported_movements')->restrictOnDelete();
        });

        Schema::create('transaction_splits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('transaction_id');
            $table->unsignedBigInteger('category_id');
            $table->decimal('amount', 19, 4);
            $table->string('description')->nullable();
            $table->timestamps();
            $table->foreign(['tenant_id', 'transaction_id'])->references(['tenant_id', 'id'])->on('transactions')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'category_id'])->references(['tenant_id', 'id'])->on('categories')->restrictOnDelete();
        });

        Schema::create('match_suggestions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('import_row_id');
            $table->unsignedBigInteger('pending_transaction_id');
            $table->string('status', 20)->default('pending');
            $table->decimal('confidence', 5, 4);
            $table->timestamps();
            $table->unique(['import_row_id', 'pending_transaction_id']);
            $table->foreign(['tenant_id', 'import_row_id'])->references(['tenant_id', 'id'])->on('import_rows')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'pending_transaction_id'])->references(['tenant_id', 'id'])->on('transactions')->cascadeOnDelete();
        });

        Schema::create('rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('content');
            $table->unsignedBigInteger('account_id')->nullable();
            $table->unsignedBigInteger('category_id');
            $table->timestamps();
            $table->softDeletes();
            $table->foreign(['tenant_id', 'account_id'])->references(['tenant_id', 'id'])->on('accounts')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'category_id'])->references(['tenant_id', 'id'])->on('categories')->cascadeOnDelete();
        });

        Schema::create('reconciliations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('account_id');
            $table->date('statement_date');
            $table->decimal('entered_bank_balance', 19, 4);
            $table->decimal('calculated_balance', 19, 4);
            $table->timestamp('reconciled_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'account_id', 'statement_date']);
            $table->unique(['tenant_id', 'id']);
            $table->foreign(['tenant_id', 'account_id'])->references(['tenant_id', 'id'])->on('accounts')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reconciliations');
        Schema::dropIfExists('rules');
        Schema::dropIfExists('match_suggestions');
        Schema::dropIfExists('transaction_splits');
        Schema::dropIfExists('import_rows');
        Schema::dropIfExists('imported_movements');
        Schema::dropIfExists('transactions');
        Schema::dropIfExists('imports');
        Schema::dropIfExists('categories');
        Schema::dropIfExists('accounts');
        Schema::dropIfExists('tenant_user');
        Schema::dropIfExists('tenants');
    }
};
