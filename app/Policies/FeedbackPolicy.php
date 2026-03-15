<?php

namespace App\Policies;

use App\Models\Feedback;
use App\Models\Project;
use App\Models\User;

class FeedbackPolicy
{
    public function create(User $user, Project $project): bool
    {
        return $this->hasRoleInDivision($user, $project->division_id, ['admin', 'manager', 'member']);
    }

    public function updateStatus(User $user, Feedback $feedback): bool
    {
        return $this->hasRoleInDivision($user, $feedback->project->division_id, ['admin', 'manager']);
    }

    public function delete(User $user, Feedback $feedback): bool
    {
        return $this->hasRoleInDivision($user, $feedback->project->division_id, ['admin']);
    }

    private function hasRoleInDivision(User $user, int $divisionId, array $roles): bool
    {
        $role = $user->divisions()
            ->where('divisions.id', $divisionId)
            ->first()?->pivot->role;

        return in_array($role, $roles);
    }
}
