<?php

namespace App\Queries;

use App\Repositories\FeedbackRepository;
use Illuminate\Database\Eloquent\Collection;

class GetProjectFeedbackQuery
{
    public function __construct(
        private FeedbackRepository $feedbackRepository,
    ) {}

    public function execute(int $projectId, ?string $status = null): Collection
    {
        if ($status !== null) {
            return $this->feedbackRepository->findByProjectAndStatus($projectId, $status);
        }

        return $this->feedbackRepository->findByProject($projectId);
    }
}
