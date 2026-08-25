<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CategoryService
{
    public function create(array $attributes): Category
    {
        return DB::transaction(function () use ($attributes): Category {
            $this->lockTenant();
            $name = trim($attributes['name']);
            $parent = $this->parent($attributes['parent_id'] ?? null);
            $this->ensureDepth($parent?->level + 1 ?? 1);
            $this->ensureUniqueName($name, $parent?->id);

            return Category::query()->create([
                'name' => $name,
                'type' => $attributes['type'],
                'parent_id' => $parent?->id,
            ]);
        });
    }

    public function update(Category $category, array $attributes): Category
    {
        return DB::transaction(function () use ($category, $attributes): Category {
            $this->lockTenant();
            $category = Category::query()->lockForUpdate()->findOrFail($category->id);
            $name = array_key_exists('name', $attributes) ? trim($attributes['name']) : $category->name;
            $parentId = array_key_exists('parent_id', $attributes) ? $attributes['parent_id'] : $category->parent_id;
            $parent = $this->parent($parentId);

            $descendants = $this->descendants($category);
            if ($parent?->is($category)) {
                throw ValidationException::withMessages(['parent_id' => 'A category cannot be its own parent.']);
            }
            if ($parent && $descendants->contains('id', $parent->id)) {
                throw ValidationException::withMessages(['parent_id' => 'A category cannot be moved under one of its descendants.']);
            }

            $newLevel = $parent?->level + 1 ?? 1;
            $deepestRelativeLevel = $descendants->max(fn (Category $item): int => $item->level - $category->level) ?? 0;
            $this->ensureDepth($newLevel + $deepestRelativeLevel);
            $this->ensureUniqueName($name, $parent?->id, $category->id);

            $levelDelta = $newLevel - $category->level;
            $category->fill([
                'name' => $name,
                'type' => $attributes['type'] ?? $category->type,
                'parent_id' => $parent?->id,
            ]);
            $category->level = $newLevel;
            $category->save();

            if ($levelDelta !== 0) {
                foreach ($descendants->sortBy('level') as $descendant) {
                    $descendant->level += $levelDelta;
                    $descendant->save();
                }
            }

            return $category->refresh();
        });
    }

    public function delete(Category $category): void
    {
        if ($category->children()->exists()) {
            $this->cannotDelete('This category has child categories.');
        }
        if ($category->transactions()->withTrashed()->exists()) {
            $this->cannotDelete('This category is used by transactions.');
        }
        if ($category->splits()->exists()) {
            $this->cannotDelete('This category is used by transaction splits.');
        }
        if ($category->rules()->exists()) {
            $this->cannotDelete('This category is referenced by active categorization rules.');
        }

        $category->delete();
    }

    private function parent(mixed $parentId): ?Category
    {
        if ($parentId === null) {
            return null;
        }

        $parent = Category::query()->lockForUpdate()->find($parentId);
        if (! $parent) {
            throw ValidationException::withMessages(['parent_id' => 'The selected parent category is invalid.']);
        }

        return $parent;
    }

    private function lockTenant(): void
    {
        DB::table('tenants')
            ->where('id', Tenant::current()?->getKey())
            ->lockForUpdate()
            ->first();
    }

    private function descendants(Category $category)
    {
        $found = collect();
        $parentIds = [$category->id];

        while ($parentIds !== []) {
            $children = Category::query()->whereIn('parent_id', $parentIds)->lockForUpdate()->get();
            $found = $found->concat($children);
            $parentIds = $children->pluck('id')->all();
        }

        return $found;
    }

    private function ensureDepth(int $level): void
    {
        if ($level > 3) {
            throw ValidationException::withMessages(['parent_id' => 'Categories may be at most three levels deep.']);
        }
    }

    private function ensureUniqueName(string $name, ?int $parentId, ?int $exceptId = null): void
    {
        $duplicate = Category::query()
            ->when($parentId === null, fn ($query) => $query->whereNull('parent_id'))
            ->when($parentId !== null, fn ($query) => $query->where('parent_id', $parentId))
            ->when($exceptId !== null, fn ($query) => $query->whereKeyNot($exceptId))
            ->whereRaw('LOWER(name) = LOWER(?)', [$name])
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages(['name' => 'A category with this name already exists under the selected parent.']);
        }
    }

    private function cannotDelete(string $message): never
    {
        throw ValidationException::withMessages(['category' => $message]);
    }
}
