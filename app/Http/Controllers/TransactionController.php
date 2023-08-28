<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreTransactionRequest;
use App\Http\Requests\UpdateTransactionRequest;
use App\Http\Resources\TransactionResource;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class TransactionController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $transactions = Transaction::filters($request->get("filter"))
            ->orders($request->get("orderBy"))
            ->latest("transaction_date")
            ->with(["category", "account"]);

        $filters = $request->get("filter");
        if (isset($filters["month"])) {
            $firstDayOfMonth = (new Carbon(
                $filters["month"] . "-01"
            ))->firstOfMonth();
            $lastDayOfMonth = (new Carbon(
                $filters["month"] . "-01"
            ))->endOfMonth();
            $transactions->where("transaction_date", ">=", $firstDayOfMonth);
            $transactions->where("transaction_date", "<=", $lastDayOfMonth);
        }

        return TransactionResource::collection($transactions->paginate());
    }

    public function store(StoreTransactionRequest $request): TransactionResource
    {
        $validated = $request->validated();

        $validated["category_id"] = $validated["category"]["id"];
        $validated["account_number"] = $validated["account"]["account_number"];

        unset($validated["category"]);
        unset($validated["account"]);

        $transaction = Transaction::create($validated);
        return new TransactionResource($transaction);
    }

    public function show(Transaction $transaction): TransactionResource
    {
        $transaction->load(["category", "account"]);
        return new TransactionResource($transaction);
    }

    public function update(
        UpdateTransactionRequest $request,
        Transaction $transaction
    ): TransactionResource {
        $updatedTransaction = $request->validated();

        $updatedTransaction["category_id"] =
            $updatedTransaction["category"]["id"];

        $updatedTransaction["account_number"] =
            $updatedTransaction["account"]["account_number"];

        unset($updatedTransaction["category"]);
        unset($updatedTransaction["account"]);

        $transaction->update($updatedTransaction);
        return new TransactionResource($transaction->fresh());
    }

    public function destroy(Transaction $transaction): Response
    {
        $transaction->delete();
        return response()->noContent();
    }
}
