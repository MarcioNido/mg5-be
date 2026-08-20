<?php

namespace App\Http\Controllers;

use App\Enums\MatchSuggestionStatus;
use App\Models\MatchSuggestion;
use App\Services\TransactionMatchingService;
use Illuminate\Http\JsonResponse;

class MatchSuggestionController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => MatchSuggestion::query()
            ->where('status', MatchSuggestionStatus::Pending->value)
            ->with(['importRow.transaction', 'pendingTransaction.splits'])
            ->get()]);
    }

    public function confirm(MatchSuggestion $suggestion, TransactionMatchingService $service): JsonResponse
    {
        return response()->json(['data' => $service->confirm($suggestion)]);
    }

    public function reject(MatchSuggestion $suggestion, TransactionMatchingService $service): JsonResponse
    {
        $service->reject($suggestion);

        return response()->json(['data' => $suggestion->fresh()]);
    }
}
