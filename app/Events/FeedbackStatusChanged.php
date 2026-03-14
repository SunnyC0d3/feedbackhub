<?php

namespace App\Events;

use App\Models\Feedback;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class FeedbackStatusChanged
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Feedback $feedback,
        public readonly string $oldStatus,
        public readonly string $newStatus,
    ) {}
}
