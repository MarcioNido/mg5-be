<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TenantController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $request->user()
                ->tenants()
                ->orderBy('name')
                ->get(['tenants.id', 'tenants.name', 'tenants.slug']),
        ]);
    }
}
