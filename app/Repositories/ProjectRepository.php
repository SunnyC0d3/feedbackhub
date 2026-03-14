<?php

namespace App\Repositories;

use App\Models\Project;
use Illuminate\Database\Eloquent\Collection;

class ProjectRepository
{
    public function findWithMetrics(int $projectId): ?Project
    {
        return Project::withCount([
            'feedbacks',
            'feedbacks as open_feedback_count' => fn ($q) => $q->where('status', 'open'),
            'feedbacks as in_progress_feedback_count' => fn ($q) => $q->where('status', 'in_progress'),
            'feedbacks as resolved_feedback_count' => fn ($q) => $q->where('status', 'resolved'),
        ])
        ->with('division')
        ->find($projectId);
    }

    public function findActiveBySlug(string $slug, int $tenantId): ?Project
    {
        return Project::where('slug', $slug)
            ->where('tenant_id', $tenantId)
            ->first();
    }

    public function findAllForTenant(int $tenantId): Collection
    {
        return Project::where('tenant_id', $tenantId)
            ->withCount('feedbacks')
            ->orderBy('name')
            ->get();
    }

    public function findForUser(int $userId, int $tenantId): Collection
    {
        return Project::where('tenant_id', $tenantId)
            ->whereHas('users', fn ($q) => $q->where('users.id', $userId))
            ->withCount('feedbacks')
            ->orderBy('name')
            ->get();
    }
}
