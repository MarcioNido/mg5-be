<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreReconciliationRequest;
use App\Models\Account;
use App\Services\ReconciliationService;
use Illuminate\Http\JsonResponse;

class ReconciliationController extends Controller
{
    public function index(Account $account): JsonResponse
    {
        return response()->json(['data' => $account->reconciliations()->orderByDesc('statement_date')->get()
            ->each->append('is_valid')]);
    }

    public function store(
        StoreReconciliationRequest $request,
        Account $account,
        ReconciliationService $service
    ): JsonResponse {
        $reconciliation = $service->reconcile(
            $account,
            (string) $request->string('statement_date'),
            $request->input('entered_bank_balance')
        );

        return response()->json(['data' => $reconciliation->append('is_valid')], 201);
    }

    public function latest(Account $account, ReconciliationService $service): JsonResponse
    {
        $latest = $service->latestValid($account);

        return response()->json(['data' => $latest?->append('is_valid')]);
    }
}
