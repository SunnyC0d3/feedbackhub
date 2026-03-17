# FeedbackHub

A multi-tenant SaaS feedback and issue tracking platform with AI-powered semantic search and analysis.

Built with **Laravel 11**, **MySQL**, **Redis**, **OpenAI**, **Pinecone**, and a **React 18 + TypeScript** frontend.

---

## Features

- **Multi-tenant isolation** — automatic `tenant_id` scoping via global Eloquent scopes; one tenant can never see another's data
- **Hierarchical organisation** — Tenant → Division → Project → Feedback with role-based access (Admin, Manager, Member, Support)
- **AI-powered summarization** — GPT-4o-mini generates structured summaries (themes, issues, positives, recommendations) across any feedback set
- **Semantic search** — query feedback by meaning using OpenAI embeddings + Pinecone vector similarity
- **Background job processing** — notifications and embeddings processed async via SQS (production) / Redis (local) with retry and idempotency
- **Usage tracking and cost monitoring** — per-tenant daily AI spend tracked with configurable caps
- **Structured observability** — JSON logs with request context, slow query detection, business metrics, and system health checks

---

## Tech Stack

| Layer | Technology |
|-------|-----------|
| Backend | PHP 8.1, Laravel 11 |
| Database | MySQL 8.0 |
| Cache | Redis |
| Queue | SQS (production) / Redis (local) |
| AI — Summarization | OpenAI GPT-4o-mini |
| AI — Embeddings | OpenAI text-embedding-3-small |
| Vector Database | Pinecone (feedback-embeddings index) |
| Frontend | React 18, TypeScript, Vite, TanStack Query, Axios, Tailwind CSS |
| Deployment | AWS (EC2, RDS, SQS, S3, CloudFront, Lambda, ECR) |

---

## Quick Start

### Prerequisites

- [Docker Desktop](https://www.docker.com/products/docker-desktop/) (or Docker Engine + Compose)
- Node.js 18+, npm (for the frontend dev server only)
- OpenAI API key
- Pinecone API key + index

> MySQL and Redis run inside Docker — no local installation needed.

### Backend (Docker)

```bash
# Copy environment file and configure API keys
cp .env.example .env
# Set OPENAI_API_KEY and PINECONE_* values in .env

# Start all containers (API, queue worker, MySQL, Redis, nginx)
docker compose up -d

# Run migrations and seed test data (first time only)
docker compose exec web php artisan migrate --seed

# API is now live at http://localhost:8000
```

### Frontend

```bash
cd frontend
npm install
npm run dev   # http://localhost:5173 (proxies /api → http://localhost:8000)
```

### Useful Docker commands

```bash
# Stop everything
docker compose down

# View logs
docker compose logs -f web
docker compose logs -f worker

# Run artisan commands
docker compose exec web php artisan <command>

# Open a shell inside the web container
docker compose exec web bash

# Reset the database
docker compose exec web php artisan migrate:fresh --seed
```

### Connecting a database client (e.g. PhpStorm, TablePlus)

| Field | Value |
|-------|-------|
| Host | `127.0.0.1` |
| Port | `3307` |
| User | `root` |
| Password | `secret` |
| Database | `feedbackhub` |

### Environment Variables

```env
# Database — Docker MySQL (127.0.0.1 forces TCP, bypasses local Unix socket)
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3307
DB_DATABASE=feedbackhub
DB_USERNAME=root
DB_PASSWORD=secret

# Cache & Queue (local dev uses Redis queue; production uses SQS)
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
- Metrics cache invalidation is handled in `Feedback::booted()` directly

This keeps the model decoupled from infrastructure concerns.

See [ADR 006](docs/adr/006-event-driven-architecture.md) for the decision rationale.

### 3. Service + Repository Layers
- **Services** — `FeedbackManagementService` (writes), `FeedbackAnalysisService` (AI pipeline)
- **Repositories** — `FeedbackRepository`, `ProjectRepository` (data access)
- **Light CQRS** — `CreateFeedbackCommand`, `UpdateFeedbackStatusCommand`

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
# Full test suite (65 tests, 189 assertions)
./vendor/bin/phpunit

# By suite
./vendor/bin/phpunit --testsuite=Feature
./vendor/bin/phpunit --testsuite=Integration
./vendor/bin/phpunit --testsuite=Performance
```

Tests use a dedicated `feedbackhub_test` database running in Docker MySQL. All external APIs (OpenAI, Pinecone) are mocked — no real API calls are made during testing.

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
├── Repositories/      # Data access layer
└── Services/          # Business logic and external API integrations
docker/
├── entrypoint.sh      # Runs artisan optimize on production startup
├── nginx.conf         # Reverse proxy config (HTTP + commented HTTPS block)
├── php.ini            # OPcache + memory settings
└── supervisord.conf   # Runs PHP-FPM inside the web container
frontend/
├── src/
│   ├── api/           # Axios client + typed API functions (one file per resource)
│   ├── components/    # Layout, Pagination, ProtectedRoute, StatusBadge
│   ├── context/       # AuthContext (token + user storage)
│   ├── hooks/         # useRole
│   ├── pages/         # 10 route-level page components
│   └── types/         # TypeScript interfaces matching API shapes exactly
└── vite.config.ts     # /api proxy → http://localhost:8000
docs/
├── adr/               # Architecture Decision Records
├── API.md             # Full REST API reference
├── DIAGRAMS.md        # Mermaid system diagrams
├── DEPLOYMENT.md      # AWS deployment guide (EC2 free tier — Month 8)
├── ONBOARDING.md      # Guide for new developers
└── RUNBOOK.md         # Operational procedures
Dockerfile             # Multi-stage PHP 8.1-fpm production image
docker-compose.yml     # Local dev: nginx + web + worker + mysql + redis
docker-compose.prod.yml # Production: nginx + web + worker + redis (RDS external)
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
- [Runbook](docs/RUNBOOK.md) — operational procedures for on-call
- [Onboarding Guide](docs/ONBOARDING.md) — how to add features and debug issues
- [AWS Deployment Guide](docs/DEPLOYMENT.md) — EC2 free tier deployment with Docker, SQS, S3, Lambda, CI/CD
