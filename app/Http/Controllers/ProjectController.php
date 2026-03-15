<?php

namespace App\Http\Controllers;

use App\Http\Resources\ProjectResource;
use App\Repositories\ProjectRepository;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ProjectController extends Controller
{
    public function __construct(private ProjectRepository $projects) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $paginator = $this->projects->paginateForTenant($request->user()->tenant_id);

        return ProjectResource::collection($paginator);
    }

    public function show(Request $request, int $id): ProjectResource
    {
        $project = $this->projects->findWithMetrics($id);

        abort_unless($project && $project->tenant_id === $request->user()->tenant_id, 404);

        return new ProjectResource($project);
    }
}
