<?php

namespace App\Events;

use App\Models\Feedback;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class FeedbackCreated
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Feedback $feedback,
    ) {}
}
