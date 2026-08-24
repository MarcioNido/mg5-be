<?php

namespace App\Http\Controllers;

use App\Enums\TransactionOrigin;
use App\Enums\TransactionStatus;
use App\Http\Requests\IndexTransactionRequest;
use App\Http\Requests\StoreTransactionRequest;
use App\Http\Requests\UpdateTransactionRequest;
use App\Http\Resources\TransactionResource;
use App\Models\Category;
use App\Models\Transaction;
use App\Services\TransactionResourceLoader;
use App\Services\TransactionSplitService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class TransactionController extends Controller
{
    public function __construct(private readonly TransactionResourceLoader $resourceLoader) {}

    public function index(IndexTransactionRequest $request): AnonymousResourceCollection
    {
        $filters = $request->validated();
        $query = $this->resourceLoader->query();

        $query->when(isset($filters['account_id']), fn ($query) => $query->where('account_id', $filters['account_id']))
            ->when(isset($filters['status']), fn ($query) => $query->where('status', $filters['status']))
            ->when(isset($filters['origin']), fn ($query) => $query->where('origin', $filters['origin']))
            ->when(isset($filters['date_from']), fn ($query) => $query->whereDate('transaction_date', '>=', $filters['date_from']))
            ->when(isset($filters['date_to']), fn ($query) => $query->whereDate('transaction_date', '<=', $filters['date_to']))
            ->when(filled($filters['search'] ?? null), function ($query) use ($filters): void {
                $search = '%'.$filters['search'].'%';
                $query->where(fn ($query) => $query
                    ->whereLike('description', $search, caseSensitive: false)
                    ->orWhereLike('notes', $search, caseSensitive: false));
            });

        if (isset($filters['category_id'])) {
            $categoryIds = $this->categoryGroupIds((int) $filters['category_id']);
            $query->where(fn ($query) => $query
                ->whereIn('category_id', $categoryIds)
                ->orWhereHas('splits', fn ($query) => $query->whereIn('category_id', $categoryIds)));
        }

        if (($filters['uncategorized'] ?? false) === true) {
            $query->whereNull('category_id')->whereDoesntHave('splits');
        }

        $transactions = $query
            ->orderByDesc('transaction_date')
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 25)
            ->withQueryString();

        return TransactionResource::collection($transactions);
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

        return new TransactionResource($this->resourceLoader->prepare($transaction));
    }

    public function show(Transaction $transaction): TransactionResource
    {
        return new TransactionResource($this->resourceLoader->prepare($transaction));
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

        return new TransactionResource($this->resourceLoader->prepare($transaction->fresh()));
    }

    public function destroy(Transaction $transaction): Response
    {
        $transaction->delete();

        return response()->noContent();
    }

    /** @return array<int, int> */
    private function categoryGroupIds(int $categoryId): array
    {
        $categoriesByParent = Category::query()
            ->get(['id', 'parent_id'])
            ->groupBy('parent_id');
        $ids = [$categoryId];

        for ($offset = 0; $offset < count($ids); $offset++) {
            foreach ($categoriesByParent->get($ids[$offset], collect()) as $child) {
                $ids[] = $child->id;
            }
        }

        return array_values(array_unique($ids));
    }
}
