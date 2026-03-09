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

class Division extends Model
{
    use HasFactory, SoftDeletes, BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'name',
        'slug',
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

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_divisions')
            ->withPivot('role')
            ->withTimestamps();
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(Invitation::class);
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    public function getCachedProjects()
    {
        $key = CacheService::key('division:projects', $this->id);

        return CacheService::remember($key, CacheService::TTL_MEDIUM, function () {
            return $this->projects()->get();
        });
    }

    public function getCachedUserCount(): int
    {
        $key = CacheService::key('division:user_count', $this->id);

        return CacheService::remember($key, CacheService::TTL_SHORT, function () {
            return $this->users()->count();
        });
    }

    public function getCachedUsers()
    {
        $key = CacheService::key('division:users', $this->id);

        return CacheService::remember($key, CacheService::TTL_MEDIUM, function () {
            return $this->users()
                ->get()
                ->map(function ($user) {
                    return [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                        'role' => $user->pivot->role,
                    ];
                });
        });
    }

    protected static function booted(): void
    {
        static::updated(function (Division $division) {
            CacheService::forget("division:*:{$division->id}:*");
        });

        static::deleted(function (Division $division) {
            CacheService::forget("division:*:{$division->id}:*");
        });
    }
}
