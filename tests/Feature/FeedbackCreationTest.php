<?php

namespace Tests\Feature;

use App\Events\FeedbackCreated;
use App\Events\FeedbackStatusChanged;
use App\Jobs\SendIdempotentFeedbackNotification;
use App\Jobs\StoreFeedbackEmbedding;
use App\Models\Division;
use App\Models\Feedback;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\User;
use App\Services\FeedbackManagementService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class FeedbackCreationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;
    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $division = Division::factory()->create(['tenant_id' => $this->tenant->id]);

        $this->project = Project::factory()->create([
            'tenant_id' => $this->tenant->id,
            'division_id' => $division->id,
        ]);

        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->actingAs($this->user);
    }

    public function test_feedback_is_created_with_correct_data(): void
    {
        $service = app(FeedbackManagementService::class);

        $feedback = $service->createFeedback([
            'project_id' => $this->project->id,
            'user_id' => $this->user->id,
            'title' => 'Login button not working',
            'description' => 'The login button does nothing on mobile.',
            'status' => 'draft',
        ], $this->tenant->id);

        $this->assertInstanceOf(Feedback::class, $feedback);
        $this->assertDatabaseHas('feedback', [
            'id' => $feedback->id,
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'user_id' => $this->user->id,
            'status' => 'draft',
        ]);
    }

    public function test_feedback_creation_fires_feedback_created_event(): void
    {
        Event::fake([FeedbackCreated::class, FeedbackStatusChanged::class]);

        $service = app(FeedbackManagementService::class);
        $service->createFeedback([
            'project_id' => $this->project->id,
            'user_id' => $this->user->id,
            'title' => 'Test feedback',
            'description' => 'Some description.',
            'status' => 'draft',
        ], $this->tenant->id);

        Event::assertDispatched(FeedbackCreated::class, function (FeedbackCreated $event) {
            return $event->feedback->tenant_id === $this->tenant->id;
        });
    }

    public function test_feedback_created_event_dispatches_notification_and_embedding_jobs(): void
    {
        Bus::fake();

        $service = app(FeedbackManagementService::class);
        $feedback = $service->createFeedback([
            'project_id' => $this->project->id,
            'user_id' => $this->user->id,
            'title' => 'Test feedback',
            'description' => 'Some description.',
            'status' => 'draft',
        ], $this->tenant->id);

        Bus::assertDispatched(SendIdempotentFeedbackNotification::class, function ($job) use ($feedback) {
            return $job->feedbackId === $feedback->id;
        });

        Bus::assertDispatched(StoreFeedbackEmbedding::class, function ($job) use ($feedback) {
            return $job->feedbackId === $feedback->id;
        });
    }

    public function test_update_status_fires_feedback_status_changed_event(): void
    {
        $feedback = Feedback::withoutEvents(fn () => Feedback::factory()->create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'user_id' => $this->user->id,
            'status' => 'draft',
        ]));

        Event::fake([FeedbackStatusChanged::class]);

        $service = app(FeedbackManagementService::class);
        $service->updateStatus($feedback, 'pending');

        Event::assertDispatched(FeedbackStatusChanged::class, function (FeedbackStatusChanged $event) {
            return $event->oldStatus === 'draft' && $event->newStatus === 'pending';
        });
    }

    public function test_feedback_creation_rolls_back_on_invalid_project(): void
    {
        $this->expectException(QueryException::class);

        $service = app(FeedbackManagementService::class);
        $service->createFeedback([
            'project_id' => 99999,
            'user_id' => $this->user->id,
            'title' => 'Should not be saved',
            'description' => 'This should roll back.',
            'status' => 'draft',
        ], $this->tenant->id);
    }

    public function test_delete_feedback_soft_deletes_record(): void
    {
        $feedback = Feedback::withoutEvents(fn () => Feedback::factory()->create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'user_id' => $this->user->id,
        ]));

        $service = app(FeedbackManagementService::class);
        $service->deleteFeedback($feedback);

        $this->assertSoftDeleted('feedback', ['id' => $feedback->id]);
    }
}
