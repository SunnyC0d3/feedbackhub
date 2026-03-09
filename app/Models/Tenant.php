<?php

namespace App\Models;

use App\Services\CacheService;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Tenant extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'active',
    ];

    protected $casts = [
        'active' => 'boolean',
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

    public function divisions(): HasMany
    {
        return $this->hasMany(Division::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function feedbacks(): HasMany
    {
        return $this->hasMany(Feedback::class);
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(Invitation::class);
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    public function getCachedDashboardMetrics(): array
    {
        $key = CacheService::key('tenant:dashboard', $this->id);

        return CacheService::remember($key, CacheService::TTL_SHORT, function () {
            return [
                'divisions_count' => $this->divisions()->count(),
                'users_count' => $this->users()->count(),
                'projects_count' => $this->projects()->count(),
                'feedback_count' => $this->feedbacks()->count(),
                'pending_feedback_count' => $this->feedbacks()
                    ->where('status', 'pending')
                    ->count(),
            ];
        });
    }

    protected static function booted(): void
    {
        static::updated(function (Tenant $tenant) {
            CacheService::forget("tenant:*:{$tenant->id}:*");
        });
    }
}
