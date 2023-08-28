<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class AccountResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            "account_number" => $this->account_number,
            "name" => $this->name,
            "type" => $this->type,
            "transactions" => TransactionResource::collection(
                $this->whenLoaded("transactions")
            ),
        ];
    }
}
