<?php

namespace App\Models;

use App\Jobs\SendIdempotentFeedbackNotification;
use App\Models\Concerns\BelongsToTenant;
use App\Services\LogService;
use App\Services\MetricsService;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class Feedback extends Model
{
    use HasFactory, SoftDeletes, BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'project_id',
        'user_id',
        'title',
        'description',
        'status',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    protected function title(): Attribute
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

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected static function booted(): void
    {
        static::created(function (Feedback $feedback) {
            LogService::info('Feedback created', [
                'feedback_id' => $feedback->id,
                'project_id' => $feedback->project_id,
                'user_id' => $feedback->user_id,
                'tenant_id' => $feedback->tenant_id,
                'status' => $feedback->status,
                'event' => 'feedback_created',
            ]);

            SendIdempotentFeedbackNotification::dispatch($feedback->id);

            LogService::info('Feedback notification job dispatched', [
                'feedback_id' => $feedback->id,
                'job' => 'SendIdempotentNotification',
                'event' => 'job_dispatched',
            ]);

            MetricsService::clearMetricsCache($feedback->tenant_id);
        });

        static::updated(function (Feedback $feedback) {
            if ($feedback->wasChanged('status')) {
                LogService::info('Feedback status changed', [
                    'feedback_id' => $feedback->id,
                    'old_status' => $feedback->getOriginal('status'),
                    'new_status' => $feedback->status,
                    'changed_by' => auth()->id(),
                    'event' => 'feedback_status_changed',
                ]);
            }

            MetricsService::clearMetricsCache($feedback->tenant_id);
        });

        static::deleted(function (Feedback $feedback) {
            LogService::info('Feedback deleted', [
                'feedback_id' => $feedback->id,
                'deleted_by' => auth()->id(),
                'event' => 'feedback_deleted',
            ]);

            MetricsService::clearMetricsCache($feedback->tenant_id);
        });
    }
}
