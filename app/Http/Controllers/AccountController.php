<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreAccountRequest;
use App\Http\Requests\UpdateAccountRequest;
use App\Http\Resources\AccountResource;
use App\Models\Account;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AccountController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return AccountResource::collection(
            Account::query()
                ->orderBy("name")
                ->get()
        );
    }

    public function store(StoreAccountRequest $request)
    {
        //
    }

    public function show(Account $account)
    {
        //
    }

    public function update(UpdateAccountRequest $request, Account $account)
    {
        //
    }

    public function destroy(Account $account)
    {
        //
    }
}
