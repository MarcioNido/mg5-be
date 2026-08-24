<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class MatchActionResource extends JsonResource
{
    public function toArray($request): array
    {
        $data = [
            'review_id' => $this->resource['review_id'],
            'suggestion_id' => $this->resource['suggestion_id'],
            'resolution' => $this->resource['resolution'],
        ];

        if (array_key_exists('remaining_candidates', $this->resource)) {
            $data['remaining_candidates'] = $this->resource['remaining_candidates'];
        }

        if (array_key_exists('transaction', $this->resource)) {
            $data['transaction'] = new TransactionResource($this->resource['transaction']);
        }

        return $data;
    }
}
