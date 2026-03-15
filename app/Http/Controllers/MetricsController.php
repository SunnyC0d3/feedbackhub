<?php

namespace App\Http\Controllers;

use App\Http\Resources\MetricsResource;
use App\Queries\GetTenantMetricsQuery;
use Illuminate\Http\Request;

class MetricsController extends Controller
{
    public function __construct(private GetTenantMetricsQuery $query) {}

    public function index(Request $request): MetricsResource
    {
        $metrics = $this->query->execute($request->user()->tenant_id);

        return new MetricsResource($metrics);
    }
}
