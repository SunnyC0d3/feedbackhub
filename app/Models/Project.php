<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Services\CacheService;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Project extends Model
{
    use HasFactory, SoftDeletes, BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'division_id',
        'name',
        'slug',
        'description'
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    protected function name(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => Str::title($value),
            set: fn ($value) => Str::title($value),
        );
    }

    protected function slug(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => Str::lower($value),
            set: fn ($value) => Str::lower($value),
        );
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function division(): BelongsTo
    {
        return $this->belongsTo(Division::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_projects')
            ->withPivot('assigned_by_user_id')
            ->withTimestamps();
    }

    public function feedbacks(): HasMany
    {
        return $this->hasMany(Feedback::class);
    }

    public function getCachedFeedbackCount(): int
    {
        $key = CacheService::key('project:feedback_count', $this->id);

        return CacheService::remember($key, CacheService::TTL_SHORT, function () {
            return $this->feedbacks()->count();
        });
    }

    public function getCachedFeedbackByStatus(string $status)
    {
        $key = CacheService::key('project:feedback_status', $this->id, $status);

        return CacheService::remember($key, CacheService::TTL_SHORT, function () use ($status) {
            return $this->feedbacks()
                ->where('status', $status)
                ->get();
        });
    }

    public function getCachedAssignedUsers()
    {
        $key = CacheService::key('project:users', $this->id);

        return CacheService::remember($key, CacheService::TTL_MEDIUM, function () {
            return $this->users()
                ->get()
                ->map(function ($user) {
                    return [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                        'assigned_by' => $user->pivot->assigned_by_user_id,
                    ];
                });
        });
    }

    protected static function booted(): void
    {
        static::updated(function (Project $project) {
            $patterns = [
                "project:feedback_count:{$project->id}",
                "project:feedback_status:{$project->id}:*",
                "project:users:{$project->id}",
            ];

            foreach ($patterns as $pattern) {
                CacheService::forget($pattern);
            }
        });

        static::deleted(function (Project $project) {
            CacheService::forget("project:*:{$project->id}:*");
        });
    }
}
