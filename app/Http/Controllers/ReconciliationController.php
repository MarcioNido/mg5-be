<?php

namespace App\Http\Controllers;

use App\Http\Requests\IndexReconciliationRequest;
use App\Http\Requests\PreviewReconciliationRequest;
use App\Http\Requests\StoreReconciliationRequest;
use App\Http\Resources\ReconciliationPreviewResource;
use App\Http\Resources\ReconciliationResource;
use App\Models\Account;
use App\Services\ReconciliationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ReconciliationController extends Controller
{
    public function index(IndexReconciliationRequest $request, Account $account): AnonymousResourceCollection
    {
        $filters = $request->validated();
        $reconciliations = $account->reconciliations()
            ->orderByDesc('statement_date')
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 15)
            ->withQueryString();

        return ReconciliationResource::collection($reconciliations);
    }

    public function preview(
        PreviewReconciliationRequest $request,
        Account $account,
        ReconciliationService $service
    ): ReconciliationPreviewResource {
        $statementDate = $request->validated('statement_date');

        return new ReconciliationPreviewResource([
            'statement_date' => $statementDate,
            'calculated_balance' => $service->calculate($account, $statementDate),
        ]);
    }

    public function store(
        StoreReconciliationRequest $request,
        Account $account,
        ReconciliationService $service
    ): JsonResponse {
        $reconciliation = $service->reconcile(
            $account,
            $request->validated('statement_date'),
            $request->validated('entered_bank_balance')
        );

        return (new ReconciliationResource($reconciliation))
            ->response()
            ->setStatusCode($reconciliation->wasRecentlyCreated ? 201 : 200);
    }

    public function latest(Account $account, ReconciliationService $service): JsonResponse
    {
        $latest = $service->latestValid($account);

        return $latest === null
            ? response()->json(['data' => null])
            : (new ReconciliationResource($latest))->response();
    }
}
