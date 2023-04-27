<?php

namespace App\Http\Resources;

class BalanceResource extends \Illuminate\Http\Resources\Json\JsonResource
{
    public function toArray($request)
    {
        return [
            "initialBalance" => $this->initialBalance,
            "totalCredits" => $this->totalCredits,
            "totalDebits" => $this->totalDebits,
            "finalBalance" => $this->finalBalance,
        ];
    }
}
