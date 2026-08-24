<?php

namespace App\Services;

use App\Models\Transaction;
use Illuminate\Database\Eloquent\Builder;

class TransactionResourceLoader
{
    public function query(): Builder
    {
        return Transaction::query()
            ->with(['account', 'category.parent', 'splits.category.parent'])
            ->withExists([
                'importRows as has_import_rows',
                'importedMovements as has_imported_movements',
            ]);
    }

    public function prepare(Transaction $transaction): Transaction
    {
        $transaction->load(['account', 'category.parent', 'splits.category.parent']);
        $transaction->loadExists([
            'importRows as has_import_rows',
            'importedMovements as has_imported_movements',
        ]);

        return $transaction;
    }
}
