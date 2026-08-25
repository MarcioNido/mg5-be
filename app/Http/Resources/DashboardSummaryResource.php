<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class DashboardSummaryResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'period' => $this->resource['period'],
            'as_of_date' => $this->resource['as_of_date'],
            'accounts' => $this->resource['accounts'],
            'account_totals_by_currency' => $this->resource['account_totals_by_currency'],
            'period_activity' => $this->resource['period_activity'],
            'workflow' => $this->resource['workflow'],
        ];
    }
}
