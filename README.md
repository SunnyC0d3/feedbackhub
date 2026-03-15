# FeedbackHub

A multi-tenant SaaS feedback and issue tracking platform with AI-powered semantic search and analysis.

Built with **Laravel 11**, **MySQL**, **Redis**, **OpenAI**, and **Pinecone**.

---

## Features

- **Multi-tenant isolation** — automatic `tenant_id` scoping via global Eloquent scopes; one tenant can never see another's data
- **Hierarchical organisation** — Tenant → Division → Project → Feedback with role-based access (Admin, Manager, Member, Support)
- **AI-powered summarization** — GPT-4o-mini generates structured summaries (themes, issues, positives, recommendations) across any feedback set
- **Semantic search** — query feedback by meaning using OpenAI embeddings + Pinecone vector similarity
- **Background job processing** — notifications and embeddings processed async via Redis queues with retry and idempotency
- **Usage tracking and cost monitoring** — per-tenant daily AI spend tracked with configurable caps
- **Structured observability** — JSON logs with request context, slow query detection, business metrics, and system health checks

---

## Tech Stack

| Layer | Technology |
|-------|-----------|
| Backend | PHP 8.1, Laravel 11 |
| Database | MySQL 8.0 |
| Cache & Queue | Redis |
| AI — Summarization | OpenAI GPT-4o-mini |
| AI — Embeddings | OpenAI text-embedding-3-small |
| Vector Database | Pinecone (feedback-embeddings index) |

---

## Quick Start

### Prerequisites

- PHP 8.1+
- MySQL 8.0 (running on port 3307 by default)
- Redis
- Composer
- OpenAI API key
- Pinecone API key + index

### Installation

```bash
# Install dependencies
composer install

# Copy environment file
cp .env.example .env

# Generate app key
php artisan key:generate

# Configure your .env (see Environment Variables below)

# Run migrations and seed test data
php artisan migrate --seed

# Start the queue worker (separate terminal)
php artisan queue:work redis --verbose
```

### Environment Variables

```env
# Database
DB_CONNECTION=mysql
DB_HOST=localhost
DB_PORT=3307
DB_DATABASE=feedbackhub
DB_USERNAME=root
DB_PASSWORD=

# Cache & Queue
CACHE_DRIVER=redis
QUEUE_CONNECTION=redis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379

# OpenAI
OPENAI_API_KEY=sk-proj-...

# Pinecone
PINECONE_API_KEY=...
PINECONE_ENVIRONMENT=us-east-1-aws
PINECONE_INDEX=feedback-embeddings
PINECONE_HOST=feedback-embeddings-XXXXX.svc.aped-XXXX-XXXX.pinecone.io
```

---

## Architecture

FeedbackHub is built around four architectural pillars:

### 1. Multi-Tenancy
All tenants share one database. Every tenant-scoped table has a `tenant_id` column. A `TenantScope` global scope automatically appends `WHERE tenant_id = ?` to every query when a user is authenticated. The `BelongsToTenant` trait applies this scope and auto-sets `tenant_id` on record creation.

See [ADR 001](docs/adr/001-multi-tenant-shared-database.md) for the decision rationale.

### 2. Event-Driven Side Effects
The `Feedback` model fires domain events (`FeedbackCreated`, `FeedbackStatusChanged`). Listeners handle side effects independently:
- `NotifyOnFeedbackCreated` → dispatches notification job
- `EmbedFeedbackOnCreated` → dispatches embedding job
- `ClearMetricsCacheOnFeedback` → invalidates cached dashboard metrics

This keeps the model decoupled from infrastructure concerns.

See [ADR 006](docs/adr/006-event-driven-architecture.md) for the decision rationale.

### 3. Service + Repository Layers
- **Services** — `FeedbackManagementService` (writes), `FeedbackAnalysisService` (AI pipeline)
- **Repositories** — `FeedbackRepository`, `ProjectRepository` (data access)
- **Light CQRS** — `CreateFeedbackCommand`, `UpdateFeedbackStatusCommand`, `GetProjectFeedbackQuery`, `GetTenantMetricsQuery`

### 4. AI Pipeline
```
User query
  → EmbeddingService (OpenAI) → 1536-dim vector
  → PineconeService → top-K similar feedback IDs
  → FeedbackRepository → Feedback models
  → AiService (GPT-4o-mini) → structured summary
```

Full architecture diagrams (including sequence diagrams) are in [docs/DIAGRAMS.md](docs/DIAGRAMS.md).

For all key architectural decisions, see [docs/adr/](docs/adr/).

---

## Key Concepts

### Tenant Isolation
Every model that uses the `BelongsToTenant` trait is automatically scoped to the authenticated user's tenant. You cannot accidentally query another tenant's data — the scope is enforced at the Eloquent query builder level.

```php
// This only returns feedback for the current tenant — always
$feedback = Feedback::where('status', 'open')->get();
```

### Semantic Search vs Keyword Search
Traditional search matches exact words. Semantic search matches meaning. A query for `"slow load times"` will surface feedback titled `"App feels sluggish on 3G"` because their vector representations are similar.

### Job Idempotency
Background jobs use cache-based idempotency keys to prevent duplicate execution. If a job is retried (due to a transient failure), it checks whether it has already completed before doing any work. Keys expire after 24 hours.

### Cost Tracking
Every OpenAI API call logs token usage and estimated cost. `AiService` aggregates daily usage per tenant in Redis and enforces configurable spending caps before making API calls.

---

## Running Tests

```bash
# Full test suite (69 tests, 196 assertions)
php artisan test

# By suite
php artisan test --testsuite=Feature
php artisan test --testsuite=Integration
php artisan test --testsuite=Performance
```

Tests use a dedicated `feedbackhub_test` database. All external APIs (OpenAI, Pinecone) are mocked — no real API calls are made during testing.

---

## Monitoring

```bash
# Watch for slow queries (>100ms)
tail -f storage/logs/laravel.log | grep slow_query

# Watch for job failures
tail -f storage/logs/laravel.log | grep job_failed

# Watch AI usage
tail -f storage/logs/laravel.log | grep ai_usage_tracked
```

```php
// System health check (via tinker)
App\Services\MetricsService::getSystemHealth();
// Returns: health_score, failed_jobs, queue_depth, cache status, db status

// AI usage stats for a tenant (last 7 days)
app(App\Services\AiService::class)->getUsageStats($tenantId, 7);
```

---

## Project Structure

```
app/
├── Commands/          # CQRS write commands
├── Events/            # Domain events (FeedbackCreated, FeedbackStatusChanged)
├── Jobs/              # Background jobs (notifications, embeddings, cleanup)
├── Listeners/         # Event listeners (notify, embed, cache-clear)
├── Models/            # Eloquent models with global scopes
│   ├── Concerns/      # BelongsToTenant trait
│   └── Scopes/        # TenantScope global scope
├── Queries/           # CQRS read queries
├── Repositories/      # Data access layer
└── Services/          # Business logic and external API integrations
docs/
├── adr/               # Architecture Decision Records
├── ARCHITECTURE.md    # Detailed domain documentation
├── DIAGRAMS.md        # Mermaid system diagrams
├── DEPLOYMENT.md      # Production deployment guide
├── ONBOARDING.md      # Guide for new developers
└── RUNBOOK.md         # Operational procedures
```

---

## API

FeedbackHub exposes a REST API using Laravel Sanctum token authentication.

```bash
# Login (returns a token)
curl -X POST http://localhost/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{"tenant_slug":"compass-group","email":"alice@compass.com","password":"password"}'

# Use the token on subsequent requests
curl http://localhost/api/feedback \
  -H "Authorization: Bearer <token>"
```

**Endpoints at a glance:**

| Method | Endpoint | Description |
|--------|----------|-------------|
| `POST` | `/api/auth/login` | Get a token |
| `POST` | `/api/auth/logout` | Revoke token |
| `GET`  | `/api/me` | Current user |
| `GET`  | `/api/divisions` | List divisions |
| `GET`  | `/api/projects` | List projects |
| `GET`  | `/api/projects/{id}/feedback` | Feedback for a project |
| `POST` | `/api/projects/{id}/summarize` | AI summary of project feedback |
| `GET`  | `/api/feedback` | List feedback (filter by `?status=`) |
| `POST` | `/api/feedback` | Create feedback |
| `PATCH`| `/api/feedback/{id}/status` | Update status |
| `DELETE`| `/api/feedback/{id}` | Soft-delete feedback |
| `POST` | `/api/analysis/query` | Semantic search + AI summary |
| `GET`  | `/api/metrics` | Tenant dashboard metrics |

Full request/response documentation: [docs/API.md](docs/API.md)

---

## Further Reading

- [API Reference](docs/API.md) — all endpoints, request/response shapes, error codes
- [Architecture Decision Records](docs/adr/) — why key decisions were made
- [System Diagrams](docs/DIAGRAMS.md) — data flow, isolation model, API integrations
- [Architecture Reference](docs/ARCHITECTURE.md) — full domain documentation
- [Runbook](docs/RUNBOOK.md) — operational procedures for on-call
- [Onboarding Guide](docs/ONBOARDING.md) — how to add features and debug issues
