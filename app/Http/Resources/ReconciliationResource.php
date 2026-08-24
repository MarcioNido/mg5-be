<?php

namespace App\Http\Resources;

use App\Services\Money;
use Illuminate\Http\Resources\Json\JsonResource;

class ReconciliationResource extends JsonResource
{
    public function toArray($request): array
    {
        $entered = Money::units($this->entered_bank_balance);
        $calculated = Money::units($this->calculated_balance);

        return [
            'id' => $this->id,
            'statement_date' => $this->statement_date->toDateString(),
            'entered_bank_balance' => Money::decimal($entered),
            'calculated_balance' => Money::decimal($calculated),
            'difference' => Money::decimal($entered - $calculated),
            'is_valid' => $entered === $calculated,
            'reconciled_at' => $this->reconciled_at?->toISOString(),
        ];
    }
}
