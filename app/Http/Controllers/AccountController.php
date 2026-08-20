<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreAccountRequest;
use App\Http\Requests\UpdateAccountRequest;
use App\Http\Resources\AccountResource;
use App\Models\Account;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class AccountController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return AccountResource::collection(
            Account::query()
                ->orderBy('name')
                ->get()
        );
    }

    public function store(StoreAccountRequest $request): AccountResource
    {
        return new AccountResource(Account::query()->create($request->validated()));
    }

    public function show(Account $account): AccountResource
    {
        return new AccountResource($account);
    }

    public function update(UpdateAccountRequest $request, Account $account): AccountResource
    {
        $account->update($request->validated());

        return new AccountResource($account->fresh());
    }

    public function destroy(Account $account): Response
    {
        $account->delete();

        return response()->noContent();
    }
}
