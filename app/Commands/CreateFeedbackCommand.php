<?php

namespace App\Commands;

class CreateFeedbackCommand
{
    public function __construct(
        public readonly int $tenantId,
        public readonly int $projectId,
        public readonly int $userId,
        public readonly string $title,
        public readonly string $description,
        public readonly string $status = 'open',
    ) {}
}
