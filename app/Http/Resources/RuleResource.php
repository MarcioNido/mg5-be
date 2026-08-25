<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class RuleResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'match_text' => $this->content,
            'account' => $this->whenLoaded('account', fn () => $this->account ? [
                'id' => $this->account->id,
                'name' => $this->account->name,
                'type' => $this->account->type,
                'currency' => $this->account->currency,
            ] : null),
            'category' => $this->whenLoaded('category', fn () => [
                ...CategoryResource::summary($this->category),
                'parent' => CategoryResource::summary($this->category->parent),
            ]),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
