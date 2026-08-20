<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class AccountResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'account_number' => $this->account_number,
            'name' => $this->name,
            'type' => $this->type,
            'currency' => $this->currency,
            'opening_balance' => $this->opening_balance,
            'opening_balance_date' => $this->opening_balance_date,
            'transactions' => TransactionResource::collection(
                $this->whenLoaded('transactions')
            ),
        ];
    }
}
