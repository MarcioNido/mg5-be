<?php

namespace App\TenantFinder;

use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Spatie\Multitenancy\Contracts\IsTenant;
use Spatie\Multitenancy\TenantFinder\TenantFinder;

class HeaderTenantFinder extends TenantFinder
{
    public const PRIMARY_HEADER = 'X-Tenant-Slug';

    public const LEGACY_HEADER = 'X-Tenant';

    public function findForRequest(Request $request): ?IsTenant
    {
        if (! Schema::hasTable('tenants')) {
            return null;
        }

        $slug = $request->header(self::PRIMARY_HEADER)
            ?? $request->header(self::LEGACY_HEADER)
            ?? 'personal';

        $slug = trim((string) $slug);

        if ($slug === '') {
            return null;
        }

        return Tenant::query()->where('slug', $slug)->first();
    }
}
