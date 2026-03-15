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
- Cache TTL constants defined in `CacheService`: SHORT (5min), MEDIUM (30min), LONG (1hr), DAY (24hr)
- Job retry policy: 3 attempts with exponential backoff [60s, 300s, 900s]

A centralized `CacheService` handles all cache interactions, providing consistent key generation and tenant isolation. No code outside `CacheService` calls `Cache::` directly.

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
Cache is invalidated via Laravel model event listeners (`updated`, `deleted`) wired through the event system. The `ClearMetricsCacheOnFeedback` listener handles both `FeedbackCreated` and `FeedbackStatusChanged` events. This keeps invalidation decoupled from business logic.
