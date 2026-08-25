<?php

namespace App\Http\Controllers;

use App\Http\Requests\DashboardSummaryRequest;
use App\Http\Resources\DashboardSummaryResource;
use App\Services\DashboardSummaryService;

class DashboardSummaryController extends Controller
{
    public function __invoke(
        DashboardSummaryRequest $request,
        DashboardSummaryService $service
    ): DashboardSummaryResource {
        return new DashboardSummaryResource(
            $service->summarize($request->validated('month'))
        );
    }
}
