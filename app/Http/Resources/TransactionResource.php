<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class TransactionResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            "id" => $this->id,
            "account" => new AccountResource($this->whenLoaded("account")),
            "transaction_date" => $this->transaction_date,
            "amount" => $this->amount,
            "description" => $this->description,
            "category" => new CategoryResource($this->whenLoaded("category")),
        ];
    }
}
