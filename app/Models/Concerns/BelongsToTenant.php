<?php

namespace App\Models\Concerns;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope('tenant', function (Builder $builder): void {
            $tenant = Tenant::current();

            if ($tenant === null) {
                $builder->whereRaw('1 = 0');

                return;
            }

            $builder->where(
                $builder->getModel()->qualifyColumn('tenant_id'),
                $tenant->getKey()
            );
        });

        static::creating(function (Model $model): void {
            $tenant = Tenant::current();

            if ($tenant === null) {
                throw new LogicException('A current tenant is required to create financial data.');
            }

            if ($model->getAttribute('tenant_id') !== null
                && (int) $model->getAttribute('tenant_id') !== (int) $tenant->getKey()) {
                throw new LogicException('Financial data cannot be assigned to another tenant.');
            }

            $model->setAttribute('tenant_id', $tenant->getKey());
        });

        static::updating(function (Model $model): void {
            $tenant = Tenant::current();

            if ($tenant === null
                || (int) $model->getAttribute('tenant_id') !== (int) $tenant->getKey()) {
                throw new LogicException('Financial data cannot be moved between tenants.');
            }
        });

        static::deleting(function (Model $model): void {
            $tenant = Tenant::current();

            if ($tenant === null
                || (int) $model->getAttribute('tenant_id') !== (int) $tenant->getKey()) {
                throw new LogicException('Financial data cannot be deleted from another tenant.');
            }
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
