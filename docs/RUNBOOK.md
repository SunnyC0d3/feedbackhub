# FeedbackHub — Runbook

Operational procedures for diagnosing and resolving production issues.

**Audience:** Developers on-call or responding to an incident.
**Assumption (local/staging):** Docker is running (`docker compose up -d`). All artisan commands run inside the web container via `docker compose exec web php artisan <cmd>`.
**Assumption (production/AWS):** You have AWS CLI access and can use SSM Run Command to exec into the EC2 instance or tail CloudWatch logs. See `docs/DEPLOYMENT.md` for the production architecture. Key difference: production uses **SQS for queues** and **Redis-in-Docker for cache only** — Redis going down does not affect job processing in production.

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

Local:
```bash
tail -100 storage/logs/laravel.log | grep -E "ERROR|CRITICAL"
```

Production (CloudWatch):
```bash
aws logs tail /ec2/feedbackhub-web --since 30m | grep -E "ERROR|CRITICAL"
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
docker compose exec web php artisan tinker
>>> app(App\Services\JobMonitor::class)->getFailedJobsCount();

# View failed job details
docker compose exec web php artisan queue:failed

# Check logs for failure reason
docker compose logs worker | grep job_failed
```

### Retry all failed jobs

```bash
docker compose exec web php artisan queue:retry all
```

### Retry a specific job

```bash
# Get the UUID from queue:failed
docker compose exec web php artisan queue:retry <uuid>
```

### Flush failed jobs (after investigation)

Only flush once you understand why jobs failed and are confident retrying won't help.

```bash
docker compose exec web php artisan queue:flush
```

### Common failure causes

| Symptom | Likely cause | Fix |
|---------|-------------|-----|
| `StoreFeedbackEmbedding` failing | OpenAI API key invalid or rate limited | Check `OPENAI_API_KEY` in SSM Parameter Store, verify API quota |
| `SendIdempotentNotification` failing | Mail config issue | Check mail settings in SSM Parameter Store |
| Jobs not processing at all | SQS permissions issue | Verify EC2 instance role has `sqs:ReceiveMessage`, `sqs:DeleteMessage` |
| Jobs failing after 3 retries | Permanent error (bad data, API down) | Check the specific exception in `queue:failed` |

### Restart queue worker

Local:
```bash
# Gracefully restart (finishes current job first)
docker compose exec web php artisan queue:restart
# Docker will auto-restart the worker container (restart: unless-stopped)
```

Production (AWS):
```bash
aws ssm send-command \
  --instance-ids <instance-id> \
  --document-name "AWS-RunShellScript" \
  --parameters 'commands=["docker restart feedbackhub-worker"]'
```

---

## 3. Queue Backed Up

### Symptoms
- Notifications delayed by minutes or hours
- New feedback not appearing in semantic search
- `queue_depth` > 200 in health check

### Diagnose

```bash
# Check queue depth locally
php artisan tinker
>>> app(App\Services\MetricsService::class)->getSystemHealth()['queue_depth'];

# Check SQS queue depth in production
aws sqs get-queue-attributes \
  --queue-url <sqs_queue_url> \
  --attribute-names ApproximateNumberOfMessages

# Check if a worker is running (local)
ps aux | grep "queue:work"
```

### Fix — worker not running

Local:
```bash
docker compose up -d worker
docker compose logs -f worker
```

Production (AWS):
```bash
# Restart the worker container on EC2
aws ssm send-command \
  --instance-ids <instance-id> \
  --document-name "AWS-RunShellScript" \
  --parameters 'commands=["docker start feedbackhub-worker"]'
```

### Fix — worker running but slow

Local:
```bash
# Scale up to 2 worker containers to drain the backlog
docker compose up -d --scale worker=2
# Scale back down once queue is clear
docker compose up -d --scale worker=1
```

Production (AWS):
```bash
# Start an additional worker container temporarily
aws ssm send-command \
  --instance-ids <instance-id> \
  --document-name "AWS-RunShellScript" \
  --parameters 'commands=["docker run -d --name feedbackhub-worker-2 <image> php artisan queue:work sqs"]'
# Stop it once the queue drains
```

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
2. Check Redis connectivity (see Section 6)

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
# Local
grep "ai_usage_tracked" storage/logs/laravel.log | tail -200

# Production (CloudWatch)
aws logs filter-log-events \
  --log-group-name /ec2/feedbackhub-web \
  --filter-pattern '{ $.event = "ai_usage_tracked" }'
```

Look for repeated requests from the same `tenant_id` in a short window — this may indicate a bug causing excessive calls.

### Emergency — disable AI for a tenant

Raise the `OPENAI_DAILY_LIMIT` threshold in SSM Parameter Store or temporarily disable the AI pipeline by returning early from `AiService::checkUsageLimits()`. This requires a code change and deployment — escalate if needed.

---

## 6. Redis Down

### Symptoms
- Cache returning null for all keys
- `cache: "unhealthy"` in health check
- Idempotency keys lost

### Diagnose

```bash
redis-cli ping
# Expected: PONG
# If connection refused: Redis is down
```

### Restart Redis

Local:
```bash
docker compose restart redis
```

Production (AWS — Redis runs in Docker on EC2):
```bash
aws ssm send-command \
  --instance-ids <instance-id> \
  --document-name "AWS-RunShellScript" \
  --parameters 'commands=["docker restart feedbackhub-redis"]'
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

In production, jobs continue to process normally even when Redis is unavailable. Only caching is affected.

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

Local:
```bash
docker compose ps mysql
docker compose logs mysql
```

Production (AWS RDS):
```bash
# Check RDS instance status
aws rds describe-db-instances \
  --db-instance-identifier feedbackhub-prod \
  --query 'DBInstances[0].DBInstanceStatus'
# Should return "available"
```

### Check slow query log

```bash
# Local
grep "slow_query" storage/logs/laravel.log | tail -50

# Production (CloudWatch)
aws logs filter-log-events \
  --log-group-name /ec2/feedbackhub-web \
  --filter-pattern '{ $.event = "slow_query" }'
```

Slow queries (>100ms) are logged automatically by the `LogQueries` middleware.

### Connection pool exhausted

If you see `Too many connections` errors:

```bash
# Check current connections
mysql -u root -p -e "SHOW STATUS LIKE 'Threads_connected';"
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
// Manually dispatch embedding jobs for specific IDs:
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

Common causes: invalid API key, rate limit exceeded, network timeout. Check the OpenAI status page and verify `OPENAI_API_KEY` in SSM Parameter Store.
