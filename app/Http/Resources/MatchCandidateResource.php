<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class MatchCandidateResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'suggestion_id' => $this->id,
            'confidence' => $this->confidence,
            'transaction' => new MatchTransactionResource($this->whenLoaded('pendingTransaction')),
        ];
    }
}
