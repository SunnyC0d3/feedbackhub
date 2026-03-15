# FeedbackHub — Onboarding Guide

Welcome to FeedbackHub. This guide gets you productive quickly by explaining how the codebase is structured, how to add features, how to debug issues, and what pitfalls to avoid.

**Read time:** ~20 minutes. Worth it.

---

## Table of Contents

1. [Mental Model](#1-mental-model)
2. [Local Setup](#2-local-setup)
3. [How to Add a New Feature](#3-how-to-add-a-new-feature)
4. [How to Debug a Production Issue](#4-how-to-debug-a-production-issue)
5. [Common Pitfalls](#5-common-pitfalls)
6. [Code Review Checklist](#6-code-review-checklist)

---

## 1. Mental Model

Before touching code, understand these four things:

### Everything is scoped to a tenant

Every query against `users`, `divisions`, `projects`, `feedback`, and `invitations` is automatically filtered by `tenant_id`. This happens via a global Eloquent scope (`TenantScope`) applied through the `BelongsToTenant` trait. You don't add `WHERE tenant_id = ?` manually — it's always there.

```php
// This looks like it queries all feedback, but it doesn't.
// It only returns feedback for the authenticated user's tenant.
Feedback::where('status', 'open')->get();
```

If you create a new tenant-scoped model, add the `BelongsToTenant` trait or you will have a data isolation bug.

### Side effects happen through events, not in models

When feedback is created, three things happen: a notification is sent, an embedding is generated, and the metrics cache is cleared. None of this code is in the `Feedback` model. The model fires a `FeedbackCreated` event. Listeners handle the rest.

This means: if you add a new side effect to feedback creation, you add a new listener — you do not touch the model or the service.

### The service layer owns business logic

Controllers (if/when added) delegate immediately to services. Services are where decisions are made. There are two main ones:

- `FeedbackManagementService` — creates, updates, deletes feedback (writes)
- `FeedbackAnalysisService` — queries feedback and runs the AI pipeline (reads)

Repositories (`FeedbackRepository`, `ProjectRepository`) exist purely for data access. No business logic lives there.

### The AI pipeline has a cost

Every call to `AiService::summarizeFeedback()` costs real money. Every call to `EmbeddingService::generateEmbedding()` costs a (very small) amount. Don't call these in loops without thinking about it. Usage is tracked per tenant and capped — but the cap is a safety net, not a licence to be wasteful.

---

## 2. Local Setup

```bash
# 1. Install dependencies
composer install

# 2. Copy and configure environment
cp .env.example .env
# Edit .env: set DB credentials, Redis, OpenAI key, Pinecone key

# 3. Generate app key
php artisan key:generate

# 4. Create test database
mysql -u root -p -e "CREATE DATABASE feedbackhub_test;"

# 5. Run migrations and seed
php artisan migrate --seed

# 6. Verify tests pass
php artisan test

# 7. Start queue worker (keep this running in a separate terminal)
php artisan queue:work redis --verbose
```

### Verify your setup with tinker

```php
php artisan tinker

# Check tenant isolation works
>>> auth()->login(App\Models\User::first())
>>> App\Models\Feedback::count() // Should only show that tenant's feedback

# Check Redis is connected
>>> Cache::put('test', true, 60)
>>> Cache::get('test') // true

# Check system health
>>> app(App\Services\MetricsService::class)->getSystemHealth()
```

---

## 3. How to Add a New Feature

Follow this pattern for any new domain feature. Example: adding a `Comment` model to feedback.

### Step 1 — Migration and Model

```bash
php artisan make:migration create_comments_table
php artisan make:model Comment
```

If comments belong to a tenant (they almost certainly do), add the `BelongsToTenant` trait:

```php
class Comment extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $fillable = ['feedback_id', 'tenant_id', 'user_id', 'body'];
}
```

Add the relationship to `Feedback`:

```php
public function comments(): HasMany
{
    return $this->hasMany(Comment::class);
}
```

### Step 2 — Factory and Seeder

```bash
php artisan make:factory CommentFactory
```

Write the factory using `faker`. Add `CommentSeeder` if needed and register it in `DatabaseSeeder`.

### Step 3 — Repository (if needed)

For simple relationships, you may not need a dedicated repository. If you find yourself writing the same queries in multiple places, create `app/Repositories/CommentRepository.php`.

Query methods belong in repositories. No raw `DB::` calls outside of repositories and migrations.

### Step 4 — Service method

Add the business logic to an existing service (if it fits) or create a new one. Services call repositories, not models directly.

```php
// FeedbackManagementService
public function addComment(Feedback $feedback, User $author, string $body): Comment
{
    return $feedback->comments()->create([
        'user_id' => $author->id,
        'tenant_id' => $feedback->tenant_id,
        'body' => $body,
    ]);
}
```

### Step 5 — Event and Listeners (if side effects exist)

If adding a comment should trigger something (notify the feedback author, update metrics):

```bash
php artisan make:event CommentAdded
php artisan make:listener NotifyOnCommentAdded
```

Register in `EventServiceProvider`. Fire the event from the service, not the model.

### Step 6 — Tests

Write tests before or alongside your code. Minimum coverage for any new feature:

- A test that the happy path works
- A test that tenant isolation is enforced (another tenant cannot see/modify the data)
- A test that side effects fire (use `Event::fake([CommentAdded::class])`)

```bash
php artisan make:test CommentTest
php artisan test --filter=CommentTest
```

### Step 7 — Cache invalidation

If your feature affects any cached data (dashboard metrics, project counts, etc.), add cache invalidation to the relevant listener or model observer.

---

## 4. How to Debug a Production Issue

### Start with logs

```bash
# Recent errors
tail -100 storage/logs/laravel.log | grep -E "ERROR|CRITICAL"

# Trace a specific request by request_id
grep "abc-123-request-id" storage/logs/laravel.log

# Slow queries
grep "slow_query" storage/logs/laravel.log | tail -20

# Job failures
grep "job_failed" storage/logs/laravel.log | tail -20
```

Every log entry includes `request_id`, `tenant_id`, `user_id`, `route`, and `method`. Use the `request_id` to trace everything that happened in a single request.

### Check system health

```php
php artisan tinker
>>> app(App\Services\MetricsService::class)->getSystemHealth()
```

### Reproduce in tinker

Most issues can be reproduced by logging in as an affected user in tinker and running the relevant service method directly:

```php
>>> auth()->login(App\Models\User::where('email', 'alice@compass.com')->first())
>>> app(App\Services\FeedbackAnalysisService::class)->analyzeByQuery("login issues", auth()->user()->tenant_id)
```

### Check failed jobs

```bash
php artisan queue:failed
php artisan queue:retry all
```

### Debugging tenant isolation issues

If a user is seeing data they shouldn't:

1. Check the model has `BelongsToTenant` — if it's missing, all queries are unscoped
2. Check the controller/service isn't using `withoutGlobalScope(TenantScope::class)` accidentally
3. Run `TenantIsolationTest` — `php artisan test --filter=TenantIsolationTest`

---

## 5. Common Pitfalls

These have already caused bugs in this project. Don't repeat them.

### Pitfall 1 — `Event::fake()` without arguments breaks model events

```php
// WRONG — intercepts ALL events including Eloquent's internal ones
// This prevents booted() callbacks from firing
Event::fake();

// CORRECT — only fake the specific events you're testing
Event::fake([FeedbackCreated::class, FeedbackStatusChanged::class]);
```

This is the trickiest bug to diagnose because tests appear to run but model observers silently stop working.

### Pitfall 2 — Forgetting `BelongsToTenant` on a new model

A model without this trait has no tenant scope. Every query will return data from all tenants. This is a security bug, not just a functional bug.

**Checklist when creating a model:** Does this data belong to a tenant? If yes, add `BelongsToTenant`.

### Pitfall 3 — N+1 queries in loops

```php
// WRONG — fires one query per project
$projects = Project::all();
foreach ($projects as $project) {
    echo $project->feedback->count(); // query per iteration
}

// CORRECT — two queries total
$projects = Project::with('feedback')->get();
foreach ($projects as $project) {
    echo $project->feedback->count(); // no query, already loaded
}
```

Run `QueryPerformanceTest` after adding new query patterns to catch N+1 issues.

### Pitfall 4 — Calling AI services in loops

```php
// WRONG — one API call per feedback item = high cost + slow
foreach ($feedbackItems as $item) {
    $summary = $aiService->summarizeFeedback([$item], $tenantId); // $$$$
}

// CORRECT — one API call for the whole collection
$summary = $aiService->summarizeFeedback($feedbackItems->toArray(), $tenantId);
```

### Pitfall 5 — Not invalidating cache after writes

If you add a write operation (create, update, delete) that affects cached data, you must also clear the relevant cache key. The most common place to forget this is dashboard metrics.

```php
// After any write that affects tenant metrics:
app(App\Services\MetricsService::class)->clearMetricsCache($tenantId);
```

Or better: fire a domain event and let `ClearMetricsCacheOnFeedback` handle it.

### Pitfall 6 — Hard-deleting when you should soft-delete

Core tables (`tenants`, `users`, `projects`, `feedback`, `divisions`) use soft deletes. Always call `->delete()` on these models — Laravel will soft-delete automatically. Never use `->forceDelete()` unless you have a specific, reviewed reason.

Pivot tables (`user_divisions`, `user_projects`, `invitations`) use hard deletes — that's intentional.

---

## 6. Code Review Checklist

Use this when reviewing PRs or self-reviewing before pushing.

### Tenant isolation
- [ ] Any new tenant-scoped model has the `BelongsToTenant` trait
- [ ] No query bypasses the tenant scope without explicit justification
- [ ] Tests verify cross-tenant data is not accessible

### Events and side effects
- [ ] New side effects are implemented as listeners, not inline in models or services
- [ ] New listeners are registered in `EventServiceProvider`
- [ ] Tests use `Event::fake([SpecificEvent::class])` — never bare `Event::fake()`

### Data integrity
- [ ] New foreign keys use `ON DELETE RESTRICT` (not CASCADE)
- [ ] New core tables use soft deletes (`SoftDeletes` trait + `deleted_at` column)
- [ ] No hardcoded IDs or tenant-specific values in seeders (use factories)

### Performance
- [ ] New queries use eager loading where relationships are accessed
- [ ] New indexes added for query patterns that filter/sort on new columns
- [ ] No AI API calls inside loops

### Caching
- [ ] Write operations that affect cached data trigger cache invalidation
- [ ] New cache keys follow the tenant-scoped naming convention in `CacheService`

### Testing
- [ ] Happy path is tested
- [ ] Tenant isolation is tested
- [ ] External APIs (OpenAI, Pinecone) are mocked — no real API calls in tests
- [ ] All 31+ existing tests still pass (`php artisan test`)
