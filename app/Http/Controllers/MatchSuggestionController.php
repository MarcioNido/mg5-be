<?php

namespace App\Http\Controllers;

use App\Enums\ImportRowStatus;
use App\Enums\MatchSuggestionStatus;
use App\Enums\TransactionStatus;
use App\Http\Requests\IndexMatchReviewRequest;
use App\Http\Resources\MatchActionResource;
use App\Http\Resources\MatchReviewResource;
use App\Models\ImportRow;
use App\Models\MatchSuggestion;
use App\Models\Transaction;
use App\Services\TransactionMatchingService;
use App\Services\TransactionResourceLoader;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class MatchSuggestionController extends Controller
{
    public function index(IndexMatchReviewRequest $request): AnonymousResourceCollection
    {
        $filters = $request->validated();
        $pendingSuggestions = fn ($query) => $query
            ->where('status', MatchSuggestionStatus::Pending->value)
            ->whereHas('pendingTransaction', fn (Builder $query) => $query
                ->where('status', TransactionStatus::Pending->value));

        $reviews = ImportRow::query()
            ->where('status', ImportRowStatus::NeedsReview->value)
            ->whereHas('account')
            ->whereHas('transaction')
            ->whereHas('suggestions', $pendingSuggestions)
            ->when(isset($filters['account_id']), fn (Builder $query) => $query
                ->where('account_id', $filters['account_id']))
            ->with([
                'account',
                'import',
                'transaction.category.parent',
                'transaction.splits.category.parent',
                'suggestions' => $pendingSuggestions,
                'suggestions.pendingTransaction.category.parent',
                'suggestions.pendingTransaction.splits.category.parent',
            ])
            ->orderByDesc(Transaction::query()
                ->select('transaction_date')
                ->whereColumn('transactions.id', 'import_rows.transaction_id')
                ->limit(1))
            ->orderByDesc('import_rows.id')
            ->paginate($filters['per_page'] ?? 15)
            ->withQueryString();

        return MatchReviewResource::collection($reviews);
    }

    public function confirm(
        MatchSuggestion $suggestion,
        TransactionMatchingService $service,
        TransactionResourceLoader $resourceLoader
    ): MatchActionResource {
        $reviewId = $suggestion->import_row_id;
        $transaction = $resourceLoader->prepare($service->confirm($suggestion));

        return new MatchActionResource([
            'review_id' => $reviewId,
            'suggestion_id' => $suggestion->id,
            'resolution' => 'matched',
            'transaction' => $transaction,
        ]);
    }

    public function reject(MatchSuggestion $suggestion, TransactionMatchingService $service): MatchActionResource
    {
        $remainingCandidates = $service->reject($suggestion);

        return new MatchActionResource([
            'review_id' => $suggestion->import_row_id,
            'suggestion_id' => $suggestion->id,
            'resolution' => $remainingCandidates > 0 ? 'candidate_rejected' : 'imported_transaction_kept',
            'remaining_candidates' => $remainingCandidates,
        ]);
    }
}
