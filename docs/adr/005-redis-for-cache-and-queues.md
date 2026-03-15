# ADR 005: Redis for Caching and Queue Backend

## Status
Accepted

## Context

FeedbackHub needs two infrastructure capabilities:

1. **Caching** — reducing database load for frequently accessed data (dashboard metrics, user divisions, project lists)
2. **Job queues** — background processing for notifications, AI embeddings, and cleanup tasks

Laravel supports multiple drivers for each:

**Cache drivers:** file, database, Redis, Memcached, DynamoDB
**Queue drivers:** sync, database, Redis, SQS, Beanstalkd

## Decision

**Both caching and queuing use Redis.**

- Cache stored in Redis database 1 (separate from default to avoid key collisions)
- Queue connection: `redis` with a dedicated `default` queue
- All tenant-scoped cache keys include `tenant_id` and (where applicable) `user_id`
- Cache TTL: MetricsService uses 300s (5min), AI usage tracking uses 7 days
- Job retry policy: 3 attempts with exponential backoff [60s, 300s, 900s]

Laravel's `Cache::` facade is used directly throughout the app. A `CacheService` wrapper was introduced in Month 2 but removed after it was found to be inconsistently adopted and added no real value over the facade itself.

## Consequences

**Pros:**
- Single dependency serves both use cases — one service to deploy, monitor, and maintain
- Redis is extremely fast for both key-value reads and queue operations
- Laravel has first-class Redis support with Horizon available if queue visibility is needed later
- In-memory storage makes cache reads sub-millisecond for dashboard and metric queries
- Redis persistence options (RDB/AOF) can survive restarts if needed

**Cons:**
- Redis is an additional infrastructure dependency — must be running for the app to work properly
- Memory is finite — large numbers of tenants or very long TTLs can exhaust Redis memory
- Cache invalidation bugs are silent — stale data shown without errors, hard to detect
- If Redis goes down, both caching AND queuing are affected simultaneously (single point of failure)

**Why not database queue:**
The `database` queue driver works but uses MySQL for queue storage, adding write load to the primary database. Redis queues are faster, use a separate resource pool, and don't compete with application queries.

**Why not file cache:**
File cache doesn't support atomic operations needed for idempotency patterns, doesn't work across multiple web servers, and has no TTL management built in.

**Cache invalidation strategy:**
Metrics cache is invalidated directly in the `Feedback` model's `booted()` hooks (`created`, `updated`, `deleted`) via `MetricsService::clearMetricsCache()`. Model-level invalidation was chosen over a dedicated listener to avoid double-firing — the `booted()` hook fires for all writes regardless of how they originate.
