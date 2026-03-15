<?php

namespace App\Http\Controllers;

use App\Http\Resources\MetricsResource;
use App\Services\MetricsService;
use Illuminate\Http\Request;

class MetricsController extends Controller
{
    public function index(Request $request): MetricsResource
    {
        $metrics = MetricsService::getDashboardMetrics($request->user()->tenant_id);

        return new MetricsResource($metrics);
    }
}
