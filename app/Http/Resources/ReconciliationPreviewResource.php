<?php

namespace App\Http\Resources;

use App\Services\Money;
use Illuminate\Http\Resources\Json\JsonResource;

class ReconciliationPreviewResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'statement_date' => $this->resource['statement_date'],
            'calculated_balance' => Money::decimal(Money::units($this->resource['calculated_balance'])),
        ];
    }
}
