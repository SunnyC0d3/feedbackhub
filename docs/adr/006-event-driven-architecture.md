# ADR 006: Event-Driven Architecture for Feedback Side Effects

## Status
Accepted

## Context

When a piece of feedback is created or its status changes, several side effects need to happen:

1. Send a notification to relevant users
2. Generate and store a vector embedding (for semantic search)
3. Invalidate cached metrics (so dashboards show fresh data)

The naive approach is to put all this logic directly in the `Feedback` model's `booted()` method or in a controller. As the number of side effects grows, this creates tight coupling — the `Feedback` model needs to know about jobs, cache keys, and notification logic.

## Decision

We use **Laravel's event/listener system** to decouple feedback lifecycle events from their side effects.

**Events:**
- `FeedbackCreated` — fired after a feedback item is saved
- `FeedbackStatusChanged` — fired when a feedback item's status is updated

**Listeners:**
- `NotifyOnFeedbackCreated` — dispatches `SendIdempotentFeedbackNotification` job
- `EmbedFeedbackOnCreated` — dispatches `StoreFeedbackEmbedding` job

The `Feedback` model fires events; it has no knowledge of what listens to them. Listeners are registered in `EventServiceProvider`. Metrics cache invalidation is handled directly in the `Feedback` model's `booted()` hooks (`created`, `updated`, `deleted`) via `MetricsService::clearMetricsCache()`.

We also introduced a **light CQRS pattern**:
- Commands: `CreateFeedbackCommand`, `UpdateFeedbackStatusCommand` (write operations)
- `FeedbackManagementService` executes commands; `FeedbackAnalysisService` handles the AI pipeline

## Consequences

**Pros:**
- `Feedback` model is decoupled — it doesn't know what happens after creation
- Adding a new side effect requires only a new listener, with zero changes to existing code (Open/Closed Principle)
- Listeners can be tested independently by firing events in isolation
- Event listeners can be queued (async) without changing the model or business logic
- CQRS separation makes it clear which code reads vs. writes, improving readability

**Cons:**
- Indirection makes it harder to trace what happens when feedback is created — must check `EventServiceProvider`
- `Event::fake()` in tests must be used carefully — faking all events intercepts Eloquent model events and breaks `booted()` callbacks. Always use `Event::fake([SpecificEvent::class])` with explicit event classes
- Slightly more files/classes for simple operations

**Key lesson learned:**
During testing, we discovered that `Event::fake()` without arguments intercepts internal Eloquent model events (not just application events). This prevented `booted()` observer callbacks from firing, causing test failures that were hard to diagnose. The fix is always passing specific event classes to `Event::fake()`. This is documented in `CLAUDE.md` and tests as a critical pitfall.
