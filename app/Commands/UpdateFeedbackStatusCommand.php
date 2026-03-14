<?php

namespace App\Commands;

class UpdateFeedbackStatusCommand
{
    public function __construct(
        public readonly int $feedbackId,
        public readonly int $tenantId,
        public readonly string $newStatus,
    ) {}
}
