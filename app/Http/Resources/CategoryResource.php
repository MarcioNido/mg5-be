<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class CategoryResource extends JsonResource
{
    public static function summary($category): ?array
    {
        if (! $category) {
            return null;
        }

        return [
            'id' => $category->id,
            'name' => $category->name,
            'type' => $category->type,
            'level' => $category->level,
        ];
    }

    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type,
            'level' => $this->level,
            'parent' => $this->whenLoaded('parent', fn () => self::summary($this->parent)),
            'children' => CategoryResource::collection($this->whenLoaded('children')),
        ];
    }
}
