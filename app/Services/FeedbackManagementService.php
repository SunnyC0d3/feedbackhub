<?php

namespace App\Services;

use App\Commands\CreateFeedbackCommand;
use App\Commands\UpdateFeedbackStatusCommand;
use App\Models\Feedback;
use App\Repositories\FeedbackRepository;
use Illuminate\Support\Facades\DB;

class FeedbackManagementService
{
    public function __construct(
        private FeedbackRepository $feedbackRepository,
    ) {}

    public function handle(CreateFeedbackCommand $command): Feedback
    {
        return $this->createFeedback([
            'project_id' => $command->projectId,
            'user_id' => $command->userId,
            'title' => $command->title,
            'description' => $command->description,
            'status' => $command->status,
        ], $command->tenantId);
    }

    public function handleStatusUpdate(UpdateFeedbackStatusCommand $command): Feedback
    {
        $feedback = $this->feedbackRepository->findForTenant($command->feedbackId, $command->tenantId);

        if (!$feedback) {
            throw new \RuntimeException("Feedback {$command->feedbackId} not found for tenant {$command->tenantId}");
        }

        return $this->updateStatus($feedback, $command->newStatus);
    }

    public function createFeedback(array $data, int $tenantId): Feedback
    {
        return DB::transaction(function () use ($data, $tenantId) {
            return Feedback::create(array_merge($data, [
                'tenant_id' => $tenantId,
            ]));
        });
    }

    public function updateStatus(Feedback $feedback, string $newStatus): Feedback
    {
        $oldStatus = $feedback->status;

        $feedback->update(['status' => $newStatus]);

        return $feedback->fresh();
    }

    public function deleteFeedback(Feedback $feedback): void
    {
        $feedback->delete();
    }
}
