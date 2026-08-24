<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

class MatchTransactionResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'transaction_date' => Carbon::parse($this->transaction_date)->toDateString(),
            'amount' => $this->amount,
            'description' => $this->description,
            'notes' => $this->notes,
            'status' => $this->status->value,
            'origin' => $this->origin->value,
            'category' => new CategoryResource($this->whenLoaded('category')),
            'splits' => TransactionSplitResource::collection($this->whenLoaded('splits')),
        ];
    }
}
