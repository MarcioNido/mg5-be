<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * @method static BaseModel|Builder filters(array $filters)
 * @method static BaseModel|Builder orders(array $orders)
 */
class BaseModel extends Model
{
    protected array $allowedFilters = [];
    protected array $allowedOrders = [];

    public function scopeFilters(Builder $query, ?array $filters = []): void
    {
        if (!$filters) {
            return;
        }

        foreach ($filters as $key => $value) {
            if (!$this->isAllowedFilter($key)) {
                continue;
            }

            if (Str::startsWith($value, ["gte,", "lte,", "gt,", "lt,"])) {
                $query->where(
                    $key,
                    $this->getOperator($value),
                    Str::after($value, ",")
                );
                continue;
            }

            $query->where($key, $value);
        }
    }

    public function scopeOrders(Builder $query, ?array $orders = []): void
    {
        if (empty($orders)) {
            return;
        }

        foreach ($orders as $order) {
            if (Str::contains($order, [",asc", ",desc"])) {
                $aOrder = explode(",", $order);
                if ($this->isAllowedOrder($aOrder[0])) {
                    $query->orderBy($aOrder[0], $aOrder[1]);
                }
                continue;
            }
            if ($this->isAllowedOrder($order)) {
                $query->orderBy($order);
            }
        }
    }

    protected function isAllowedFilter(string $key): bool
    {
        if (empty($this->allowedFilters)) {
            return true;
        }

        return in_array($key, $this->allowedFilters);
    }

    protected function isAllowedOrder(string $key): bool
    {
        if (empty($this->allowedOrders)) {
            return true;
        }

        return in_array($key, $this->allowedOrders);
    }

    protected function getOperator(string $value): string
    {
        if (Str::startsWith($value, "gte,")) {
            return ">=";
        }

        if (Str::startsWith($value, "lte,")) {
            return "<=";
        }

        if (Str::startsWith($value, "gt,")) {
            return ">";
        }

        if (Str::startsWith($value, "lt,")) {
            return "<";
        }

        return "=";
    }
}
