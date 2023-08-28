<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class RuleResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            "id" => $this->id,
            "content" => $this->content,
            "category" => new CategoryResource($this->whenLoaded("category")),
            "account" => new AccountResource($this->whenLoaded("account")),
            "created_at" => $this->created_at,
            "updated_at" => $this->updated_at,
            "deleted_at" => $this->deleted_at,
        ];
    }
}
