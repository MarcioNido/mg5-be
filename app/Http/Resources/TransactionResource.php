<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class TransactionResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'accountNumber' => $this->account_number,
            'transactionDate' => $this->transaction_date,
            'amount' => $this->amount,
            'description' => $this->description,
        ];
    }

}
