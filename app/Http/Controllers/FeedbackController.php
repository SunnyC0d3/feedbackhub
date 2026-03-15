<?php

namespace App\Http\Controllers;

use App\Commands\CreateFeedbackCommand;
use App\Commands\UpdateFeedbackStatusCommand;
use App\Http\Requests\CreateFeedbackRequest;
use App\Http\Requests\UpdateFeedbackStatusRequest;
use App\Http\Resources\FeedbackResource;
use App\Models\Feedback;
use App\Models\Project;
use App\Repositories\FeedbackRepository;
use App\Services\FeedbackManagementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class FeedbackController extends Controller
{
    public function __construct(
        private FeedbackManagementService $management,
        private FeedbackRepository $repository,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $paginator = $this->repository->paginateForTenant(
            tenantId: $request->user()->tenant_id,
            status:   $request->query('status'),
        );

        $paginator->load('project', 'user');

        return FeedbackResource::collection($paginator);
    }

    public function indexByProject(Request $request, int $projectId): AnonymousResourceCollection
    {
        $paginator = $this->repository->paginateByProject(
            projectId: $projectId,
            status:    $request->query('status'),
        );

        $paginator->load('project', 'user');

        return FeedbackResource::collection($paginator);
    }

    public function store(CreateFeedbackRequest $request): JsonResponse
    {
        $project = Project::findOrFail($request->integer('project_id'));

        $this->authorize('create', [Feedback::class, $project]);

        $command = new CreateFeedbackCommand(
            tenantId:    $request->user()->tenant_id,
            projectId:   $project->id,
            userId:      $request->user()->id,
            title:       $request->string('title'),
            description: $request->input('description'),
            status:      $request->input('status', 'open'),
        );

        $feedback = $this->management->handle($command);

        return (new FeedbackResource($feedback->load('project', 'user')))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Request $request, int $id): FeedbackResource
    {
        $feedback = $this->repository->findForTenant($id, $request->user()->tenant_id);

        abort_unless($feedback, 404);

        return new FeedbackResource($feedback->load('project', 'user'));
    }

    public function updateStatus(UpdateFeedbackStatusRequest $request, Feedback $feedback): FeedbackResource
    {
        $this->authorize('updateStatus', $feedback);

        $command = new UpdateFeedbackStatusCommand(
            feedbackId: $feedback->id,
            tenantId:   $request->user()->tenant_id,
            newStatus:  $request->string('status'),
        );

        $this->management->handleStatusUpdate($command);

        return new FeedbackResource($feedback->fresh()->load('project', 'user'));
    }

    public function destroy(Request $request, Feedback $feedback): JsonResponse
    {
        $this->authorize('delete', $feedback);

        $this->management->deleteFeedback($feedback);

        return response()->json(['message' => 'Feedback deleted.']);
    }
}
