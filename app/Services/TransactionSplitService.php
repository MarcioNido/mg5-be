<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TransactionSplitService
{
    public function replace(Transaction $transaction, array $splits): void
    {
        $total = array_sum(array_map(fn (array $split): int => Money::units($split['amount']), $splits));

        if ($splits !== [] && $total !== Money::units($transaction->amount)) {
            throw ValidationException::withMessages([
                'splits' => 'Split amounts must equal the transaction amount exactly.',
            ]);
        }

        $categoryIds = collect($splits)->pluck('category_id')->unique();
        if (Category::query()->whereKey($categoryIds)->count() !== $categoryIds->count()) {
            throw ValidationException::withMessages([
                'splits' => 'Every split category must belong to the current tenant.',
            ]);
        }

        DB::transaction(function () use ($transaction, $splits): void {
            $transaction->splits()->delete();
            foreach ($splits as $split) {
                $transaction->splits()->create($split);
            }
        });
    }
}
