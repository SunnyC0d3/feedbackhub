# FeedbackHub — Runbook

Operational procedures for diagnosing and resolving production issues.

**Audience:** Developers on-call or responding to an incident.
**Assumption (local/staging):** You have shell access and can run `php artisan` commands.
**Assumption (production/AWS):** You have AWS CLI access and can exec into ECS tasks or tail CloudWatch logs. See `docs/DEPLOYMENT.md` for the production architecture. Key difference: production uses **SQS for queues** and **ElastiCache Redis for cache only** — Redis going down does not affect job processing in production.

---

## Table of Contents

1. [Quick Health Check](#1-quick-health-check)
2. [Failed Jobs](#2-failed-jobs)
3. [Queue Backed Up](#3-queue-backed-up)
4. [Cache Issues](#4-cache-issues)
5. [AI Cost Alert](#5-ai-cost-alert)
6. [Redis Down](#6-redis-down)
7. [Database Issues](#7-database-issues)
8. [Slow Queries](#8-slow-queries)
9. [Pinecone / Embedding Issues](#9-pinecone--embedding-issues)

---

## 1. Quick Health Check

Run this first for any incident to get a system-wide status snapshot.

```php
# Via tinker
php artisan tinker
>>> app(App\Services\MetricsService::class)->getSystemHealth();
```

**What to look for:**

| Field | Healthy | Degraded | Unhealthy |
|-------|---------|----------|-----------|
| `health_score` | 100 | 80–99 | < 80 |
| `failed_jobs` | 0 | 1–10 | > 10 |
| `queue_depth` | < 50 | 50–200 | > 200 |
| `cache` | `"healthy"` | — | `"unhealthy"` |
| `database` | `"healthy"` | — | `"unhealthy"` |

**Check logs for recent errors:**
```bash
tail -100 storage/logs/laravel.log | grep -E "ERROR|CRITICAL"
```

---

## 2. Failed Jobs

### Symptoms
- Users not receiving notifications
- Feedback items missing from semantic search
- Log entries containing `job_failed`

### Diagnose

```bash
# Count failed jobs
php artisan tinker
>>> app(App\Services\JobMonitor::class)->getFailedJobsCount();

# View failed job details
php artisan queue:failed

# Check logs for failure reason
tail -f storage/logs/laravel.log | grep job_failed
```

### Retry all failed jobs

```bash
php artisan queue:retry all
```

### Retry a specific job

```bash
# Get the UUID from `php artisan queue:failed`
php artisan queue:retry <uuid>
```

### Flush failed jobs (after investigation)

Only flush once you understand why jobs failed and are confident retrying won't help.

```bash
php artisan queue:flush
```

### Common failure causes

| Symptom | Likely cause | Fix |
|---------|-------------|-----|
| `StoreFeedbackEmbedding` failing | OpenAI API key invalid or rate limited | Check `OPENAI_API_KEY`, verify API quota |
| `SendIdempotentNotification` failing | Mail config issue | Check mail settings in `.env` |
| Any job after Redis restart | Queue connection lost | Restart queue worker (see below) |
| Jobs failing after 3 retries | Permanent error (bad data, API down) | Check the specific exception in `queue:failed` |

### Restart queue worker

```bash
# Gracefully restart (finishes current job first)
php artisan queue:restart

# If using Supervisor, it will automatically restart the worker after this
```

---

## 3. Queue Backed Up

### Symptoms
- Notifications delayed by minutes or hours
- New feedback not appearing in semantic search
- `queue_depth` > 200 in health check

### Diagnose

```bash
# Check queue depth
php artisan tinker
>>> app(App\Services\MetricsService::class)->getSystemHealth()['queue_depth'];

# Check if a worker is running
ps aux | grep "queue:work"
```

### Fix — worker not running

```bash
# Local: start a worker manually
php artisan queue:work redis --verbose

# Local: or if using Supervisor
supervisorctl status
supervisorctl start feedbackhub-worker:*

# Production (AWS): force a new ECS deployment for the worker service
aws ecs update-service \
  --cluster feedbackhub-prod \
  --service feedbackhub-worker \
  --force-new-deployment
```

### Fix — worker running but slow

```bash
# Local: run additional workers to drain the backlog (separate terminals)
php artisan queue:work redis --verbose
php artisan queue:work redis --verbose

# Production (AWS): increase desired task count on the worker service
aws ecs update-service \
  --cluster feedbackhub-prod \
  --service feedbackhub-worker \
  --desired-count 3
# Scale back down once the queue drains (auto-scaling will also handle this)
```

Kill the extra workers once the queue drains (Ctrl+C).

---

## 4. Cache Issues

### Symptoms
- Dashboard showing stale metrics after data changes
- Repeated slow queries (cache not being hit)
- Redis connectivity errors in logs

### Check cache health

```bash
php artisan tinker
>>> Cache::store('redis')->put('health_check', true, 10);
>>> Cache::store('redis')->get('health_check'); // Should return true
```

### Clear cache for a specific tenant

Use this when a specific tenant is seeing stale data. Safer than a full flush.

```php
php artisan tinker
>>> $tenantId = 1; // replace with actual tenant ID
>>> app(App\Services\MetricsService::class)->clearMetricsCache($tenantId);
```

### Clear all application cache

Only do this if you cannot identify the specific stale key. This will cause a brief spike in database load as caches warm back up.

```bash
php artisan cache:clear
```

### Cache not invalidating after updates

Check that model events are firing. Metrics cache is cleared in `Feedback::booted()` on `created`, `updated`, and `deleted` hooks via `MetricsService::clearMetricsCache()`. If invalidation is broken:

1. Confirm the model is firing events (not using `withoutEvents()` in the code path)
2. Check Redis connectivity

---

## 5. AI Cost Alert

### Symptoms
- Tenant hitting daily spending cap (summarization requests rejected)
- Unexpectedly high OpenAI bill
- Log entries with `ai_usage_tracked` showing high costs

### Check current usage

```php
php artisan tinker
>>> $tenantId = 1; // replace with actual tenant ID
>>> app(App\Services\AiService::class)->getUsageStats($tenantId, 7);
// Returns 7-day breakdown of tokens used and cost per day
```

### Check if a tenant is over their limit

```php
>>> app(App\Services\AiService::class)->checkUsageLimits($tenantId);
// Returns true if under limit, throws exception if over
```

### Investigate high usage

```bash
# Find which tenant is making the most AI calls
grep "ai_usage_tracked" storage/logs/laravel.log | tail -200
```

Look for repeated requests from the same `tenant_id` in a short window — this may indicate a bug causing excessive calls (e.g., a loop, a misconfigured scheduled job).

### Emergency — disable AI for a tenant

Raise the `OPENAI_DAILY_LIMIT` threshold for specific tenants or temporarily disable the AI pipeline by returning early from `AiService::checkUsageLimits()`. This requires a code change and deployment — escalate if needed.

---

## 6. Redis Down

### Symptoms
- Cache returning null for all keys
- Jobs not being dispatched or processed
- `cache: "unhealthy"` in health check

### Diagnose

```bash
redis-cli ping
# Expected: PONG
# If connection refused: Redis is down
```

### Restart Redis

```bash
# If running as a service
sudo systemctl restart redis

# If running via Docker
docker restart <redis-container-name>
```

### Verify recovery

```bash
redis-cli ping
# PONG

php artisan tinker
>>> Cache::store('redis')->put('test', 1, 60);
>>> Cache::store('redis')->get('test'); // Should return 1
```

### Impact while Redis is down

**Local / staging** (Redis handles both cache and queues):

| Feature | Impact |
|---------|--------|
| Caching | All cache misses — database takes full load |
| Job queue | Jobs cannot be dispatched or processed |
| Idempotency keys | Lost — duplicate jobs may run after recovery |
| AI usage tracking | Usage data lost for the downtime window |

After Redis recovers, restart the queue worker to reconnect:

```bash
php artisan queue:restart
```

**Production / AWS** (Redis is cache-only; jobs use SQS):

| Feature | Impact |
|---------|--------|
| Caching | All cache misses — database takes full load |
| Job queue | **No impact** — SQS is independent of Redis |
| Idempotency keys | Lost for the downtime window |
| AI usage tracking | Usage data lost for the downtime window |

In production, jobs continue to process normally even when ElastiCache is unavailable. Only caching is affected.

---

## 7. Database Issues

### Symptoms
- `database: "unhealthy"` in health check
- 500 errors across the application
- `SQLSTATE` errors in logs

### Check connectivity

```bash
php artisan tinker
>>> DB::select('SELECT 1');
// Should return without error
```

### Check MySQL is running

```bash
# Check process
ps aux | grep mysql

# Or via systemctl
sudo systemctl status mysql
```

### Check slow query log

```bash
grep "slow_query" storage/logs/laravel.log | tail -50
```

Slow queries (>100ms) are logged automatically by the `LogQueries` middleware. If you see many slow queries on the same table, an index may be missing or a query pattern has changed.

### Connection pool exhausted

If you see `Too many connections` errors:

```bash
# Check current connections
mysql -u root -p -e "SHOW STATUS LIKE 'Threads_connected';"

# Check max connections setting
mysql -u root -p -e "SHOW VARIABLES LIKE 'max_connections';"
```

Restart queue workers to release stale connections:

```bash
php artisan queue:restart
```

---

## 8. Slow Queries

### Diagnose

```bash
# Find slow queries in logs (>100ms threshold)
grep "slow_query" storage/logs/laravel.log | tail -50

# Run EXPLAIN on a suspected query via tinker
php artisan tinker
>>> DB::listen(fn($q) => dump($q->sql, $q->bindings));
>>> App\Models\Feedback::where('project_id', 1)->where('status', 'open')->get();
```

### Common causes and fixes

| Cause | Fix |
|-------|-----|
| Missing index | Add compound index covering the query columns |
| N+1 queries | Use eager loading — `with('project', 'user')` |
| Missing `tenant_id` in query | Ensure `BelongsToTenant` trait is applied to the model |
| Large table scan | Verify `EXPLAIN` shows index usage, not `type: ALL` |

Run `php artisan test --testsuite=Performance` to verify index usage via EXPLAIN after any schema changes.

---

## 9. Pinecone / Embedding Issues

### Symptoms
- Semantic search returning no results or irrelevant results
- `StoreFeedbackEmbedding` jobs failing
- Log entries with `api_error` referencing Pinecone

### Check Pinecone index health

```php
php artisan tinker
>>> app(App\Services\PineconeService::class)->describeIndexStats();
// Returns vector count, dimension, fullness
```

### Missing embeddings

If feedback exists in MySQL but has no embedding in Pinecone (e.g., after a job failure period):

```php
php artisan tinker
// Find feedback without embeddings and re-dispatch embedding jobs
>>> $feedback = App\Models\Feedback::whereNull('embedded_at')->get(); // if column exists
// Or manually dispatch for specific IDs:
>>> App\Jobs\StoreFeedbackEmbedding::dispatch(App\Models\Feedback::find(42));
```

### Verify tenant filter is working

```php
>>> $vec = app(App\Services\EmbeddingService::class)->generateEmbedding("test query");
>>> app(App\Services\PineconeService::class)->query($vec, 5, ['tenant_id' => 1]);
// Results should only contain feedback with tenant_id = 1
```

### OpenAI embedding failures

```bash
grep "api_error" storage/logs/laravel.log | grep embedding | tail -20
```

Common causes: invalid API key, rate limit exceeded, network timeout. Check the OpenAI status page and verify `OPENAI_API_KEY` in `.env`.
