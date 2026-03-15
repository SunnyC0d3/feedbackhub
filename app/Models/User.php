<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Services\CacheService;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes, BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'name',
        'email',
        'password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
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

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'user_projects')
            ->withPivot('assigned_by_user_id')
            ->withTimestamps();
    }

    public function divisions(): BelongsToMany
    {
        return $this->belongsToMany(Division::class, 'user_divisions')
            ->withPivot('role')
            ->withTimestamps();
    }

    public function feedbacks(): HasMany
    {
        return $this->hasMany(Feedback::class);
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(Invitation::class);
    }

    public function getHighestRole(): string
    {
        $priority = ['admin' => 4, 'manager' => 3, 'member' => 2, 'support' => 1];

        $roles = $this->divisions()
            ->withoutGlobalScopes()
            ->pluck('user_divisions.role')
            ->toArray();

        if (empty($roles)) {
            return 'support';
        }

        usort($roles, fn ($a, $b) => ($priority[$b] ?? 0) <=> ($priority[$a] ?? 0));

        return $roles[0];
    }

    public function getCachedDivisions()
    {
        $key = CacheService::key('user:divisions', $this->id);

        return CacheService::remember($key, CacheService::TTL_MEDIUM, function () {
            return $this->divisions()
                ->get()
                ->map(function ($division) {
                    return [
                        'id' => $division->id,
                        'name' => $division->name,
                        'slug' => $division->slug,
                        'role' => $division->pivot->role,
                    ];
                });
        });
    }

    public function getCachedProjectCount(): int
    {
        $key = CacheService::key('user:project_count', $this->id);

        return CacheService::remember($key, CacheService::TTL_SHORT, function () {
            return $this->projects()->count();
        });
    }

    public function getCachedFeedbackCount(): int
    {
        $key = CacheService::key('user:feedback_count', $this->id);

        return CacheService::remember($key, CacheService::TTL_SHORT, function () {
            return $this->feedbacks()->count();
        });
    }

    protected static function booted(): void
    {
        static::updated(function (User $user) {
            CacheService::clearUserCache();
        });

        static::deleted(function (User $user) {
            CacheService::clearUserCache();
        });
    }
}
