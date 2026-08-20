<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\TenantFinder\HeaderTenantFinder;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTenantMembership
{
    public function __construct(private readonly HeaderTenantFinder $tenantFinder) {}

    public function handle(Request $request, Closure $next): Response
    {
        $tenant = $this->tenantFinder->findForRequest($request);

        abort_if($tenant === null, Response::HTTP_NOT_FOUND, 'Tenant not found.');
        abort_unless(
            $request->user()?->tenants()->whereKey($tenant->getKey())->exists(),
            Response::HTTP_FORBIDDEN,
            'You do not belong to this tenant.'
        );

        $tenant->makeCurrent();

        try {
            return $next($request);
        } finally {
            Tenant::forgetCurrent();
        }
    }
}
