<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class TransactionSplitResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'category_id' => $this->category_id,
            'amount' => $this->amount,
            'description' => $this->description,
            'category' => new CategoryResource($this->whenLoaded('category')),
        ];
    }
}
