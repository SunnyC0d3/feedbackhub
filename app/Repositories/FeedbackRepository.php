<?php

namespace App\Repositories;

use App\Models\Feedback;
use Illuminate\Database\Eloquent\Collection;

class FeedbackRepository
{
    public function findByProject(int $projectId): Collection
    {
        return Feedback::where('project_id', $projectId)
            ->orderBy('created_at', 'desc')
            ->get();
    }

    public function findByStatus(string $status, int $tenantId): Collection
    {
        return Feedback::where('status', $status)
            ->where('tenant_id', $tenantId)
            ->orderBy('created_at', 'desc')
            ->get();
    }

    public function findPendingForTenant(int $tenantId): Collection
    {
        return Feedback::whereIn('status', ['draft', 'seen', 'pending', 'review_required'])
            ->where('tenant_id', $tenantId)
            ->orderBy('created_at', 'asc')
            ->get();
    }

    public function findForTenant(int $feedbackId, int $tenantId): ?Feedback
    {
        return Feedback::where('id', $feedbackId)
            ->where('tenant_id', $tenantId)
            ->first();
    }

    public function findByProjectAndStatus(int $projectId, string $status): Collection
    {
        return Feedback::where('project_id', $projectId)
            ->where('status', $status)
            ->orderBy('created_at', 'desc')
            ->get();
    }

    public function findRecentForTenant(int $tenantId, int $limit = 10): Collection
    {
        return Feedback::where('tenant_id', $tenantId)
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }
}
