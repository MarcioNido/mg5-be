<?php

namespace App\Http\Controllers;

use App\Enums\TransactionOrigin;
use App\Enums\TransactionStatus;
use App\Http\Requests\StoreTransactionRequest;
use App\Http\Requests\UpdateTransactionRequest;
use App\Http\Resources\TransactionResource;
use App\Models\Transaction;
use App\Services\TransactionSplitService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class TransactionController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $transactions = Transaction::filters($request->get('filter'))
            ->orders($request->get('orderBy'))
            ->latest('transaction_date')
            ->with(['category', 'account', 'splits.category']);

        $filters = $request->get('filter');
        if (isset($filters['month'])) {
            $firstDayOfMonth = (new Carbon(
                $filters['month'].'-01'
            ))->firstOfMonth();
            $lastDayOfMonth = (new Carbon(
                $filters['month'].'-01'
            ))->endOfMonth();
            $transactions->where('transaction_date', '>=', $firstDayOfMonth);
            $transactions->where('transaction_date', '<=', $lastDayOfMonth);
        }

        if (isset($filters['category_id'])) {
            $transactions->belongsToCategoryGroup($filters['category_id']);
        }

        return TransactionResource::collection($transactions->paginate());
    }

    public function store(StoreTransactionRequest $request, TransactionSplitService $splits): TransactionResource
    {
        $validated = $request->validated();
        $splitData = $validated['splits'] ?? [];
        unset($validated['splits']);
        $validated['origin'] = TransactionOrigin::Manual;
        $validated['status'] ??= TransactionStatus::Pending;
        $validated['posted_at'] = ($validated['status'] instanceof TransactionStatus
            ? $validated['status'] : TransactionStatus::from($validated['status'])) === TransactionStatus::Posted ? now() : null;
        $transaction = DB::transaction(function () use ($validated, $splitData, $splits): Transaction {
            $transaction = Transaction::query()->create($validated);
            $splits->replace($transaction, $splitData);

            return $transaction;
        });

        return new TransactionResource($transaction->load(['account', 'category', 'splits.category']));
    }

    public function show(Transaction $transaction): TransactionResource
    {
        $transaction->load(['category', 'account', 'splits.category']);

        return new TransactionResource($transaction);
    }

    public function update(
        UpdateTransactionRequest $request,
        Transaction $transaction,
        TransactionSplitService $splits
    ): TransactionResource {
        $validated = $request->validated();
        $splitData = $validated['splits'] ?? null;
        unset($validated['splits']);
        if (isset($validated['status'])) {
            $validated['posted_at'] = $validated['status'] === 'posted' ? ($transaction->posted_at ?? now()) : null;
        }
        DB::transaction(function () use ($transaction, $validated, $splitData, $splits): void {
            $transaction->update($validated);
            if ($splitData !== null) {
                $splits->replace($transaction, $splitData);
            }
        });

        return new TransactionResource($transaction->fresh()->load(['account', 'category', 'splits.category']));
    }

    public function destroy(Transaction $transaction): Response
    {
        $transaction->delete();

        return response()->noContent();
    }
}
