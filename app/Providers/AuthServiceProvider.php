<?php

namespace App\Providers;

use App\Models\Feedback;
use App\Policies\FeedbackPolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    protected $policies = [
        Feedback::class => FeedbackPolicy::class,
    ];

    public function boot(): void
    {
        //
    }
}
