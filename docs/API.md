# FeedbackHub — API Reference

Base URL (local): `http://localhost:8000/api`
Base URL (production): `https://api.yourdomain.com/api`

Authentication uses **Laravel Sanctum** (token-based). Include the token in every authenticated request:

```
Authorization: Bearer <token>
```

All responses are JSON. Validation errors return `422` with an `errors` object. Unauthenticated requests return `401`. Resources that belong to another tenant return `404`.

**Paginated list responses** include `data`, `links`, and `meta`:

```json
{
  "data": [ { ... }, { ... } ],
  "links": {
    "first": "http://localhost/api/feedback?page=1",
    "last":  "http://localhost/api/feedback?page=4",
    "prev":  null,
    "next":  "http://localhost/api/feedback?page=2"
  },
  "meta": {
    "current_page": 1,
    "last_page":    4,
    "per_page":     20,
    "total":        72,
    "from":         1,
    "to":           20
  }
}
```

Use `?page=2` to navigate. Default page size is 20 on all list endpoints.

**Authorization:** Write operations on feedback are role-restricted. Roles are assigned per-division in `user_divisions`. The `role` field on every user response reflects their **highest** role across all divisions they belong to (`admin > manager > member > support`).

| Role | Create | Update Status | Delete |
|------|--------|---------------|--------|
| `support` | ✗ | ✗ | ✗ |
| `member` | ✓ | ✗ | ✗ |
| `manager` | ✓ | ✓ | ✗ |
| `admin` | ✓ | ✓ | ✓ |

Unauthorized write attempts return `403`.

---

## Authentication

### Login
`POST /api/auth/login`

Email uniqueness is per-tenant, so the tenant must be identified at login via its slug.

**Request**
```json
{
  "tenant_slug": "compass-group",
  "email": "alice@compass.com",
  "password": "password"
}
```

**Response `200`**
```json
{
  "token": "1|abc123...",
  "user": {
    "id": 1,
    "name": "Alice Smith",
    "email": "alice@compass.com",
    "tenant_id": 1,
    "role": "admin",
    "created_at": "2026-01-01T00:00:00.000000Z"
  }
}
```

**Response `401`**
```json
{ "message": "Invalid credentials." }
```

---

### Logout
`POST /api/auth/logout` — *auth required*

Revokes the current token.

**Response `200`**
```json
{ "message": "Logged out." }
```

---

### Current User
`GET /api/me` — *auth required*

**Response `200`**
```json
{
  "id": 1,
  "name": "Alice Smith",
  "email": "alice@compass.com",
  "tenant_id": 1,
  "role": "admin",
  "created_at": "2026-01-01T00:00:00.000000Z"
}
```

---

## Divisions

All divisions returned are automatically scoped to the authenticated user's tenant.

### List Divisions
`GET /api/divisions` — *auth required*

**Response `200`**
```json
{
  "data": [
    {
      "id": 1,
      "name": "Engineering",
      "slug": "engineering",
      "tenant_id": 1,
      "user_count": 3,
      "projects": [ { "id": 1, "name": "Project Alpha", "slug": "project-alpha", ... } ],
      "created_at": "2026-01-01T00:00:00.000000Z",
      "updated_at": "2026-01-01T00:00:00.000000Z"
    }
  ]
}
```

### Get Division
`GET /api/divisions/{id}` — *auth required*

**Response `200`** — same shape as a single item above.
**Response `404`** — division not found or belongs to another tenant.

---

## Projects

All projects are scoped to the authenticated user's tenant.

### List Projects
`GET /api/projects` — *auth required*

Returns all projects for the tenant with feedback counts.

**Response `200`**
```json
{
  "data": [
    {
      "id": 1,
      "name": "Project Alpha",
      "slug": "project-alpha",
      "description": "...",
      "division_id": 1,
      "tenant_id": 1,
      "feedback_count": 12,
      "division": { ... },
      "created_at": "...",
      "updated_at": "..."
    }
  ]
}
```

### Get Project
`GET /api/projects/{id}` — *auth required*

Returns the project with feedback counts broken down by status (open, in_progress, resolved).

**Response `200`** — same shape as list item above.
**Response `404`** — project not found or belongs to another tenant.

### List Project Feedback
`GET /api/projects/{id}/feedback` — *auth required*

**Query Parameters**

| Parameter | Type   | Description                                         |
|-----------|--------|-----------------------------------------------------|
| `status`  | string | Optional. Filter by status. See valid values below. |

**Response `200`** — same shape as `GET /api/feedback`.

### Summarize Project Feedback
`POST /api/projects/{id}/summarize` — *auth required*

Runs the full AI summarization pipeline over all feedback for this project.

**Response `200`**
```json
{
  "project_id": 1,
  "feedback_count": 12,
  "summary": "**Key Themes:** ...\n**Critical Issues:** ...\n**Positive Feedback:** ...\n**Recommendations:** ...",
  "usage": {
    "tokens_used": 840,
    "cost_usd": 0.000252
  }
}
```

**Response `422`** — no feedback exists for this project.

---

## Feedback

### List Feedback
`GET /api/feedback` — *auth required*

Returns the 50 most recent feedback items for the tenant. Filter by status using the query parameter.

**Query Parameters**

| Parameter | Type   | Description                          |
|-----------|--------|--------------------------------------|
| `status`  | string | Optional. Filter by a single status. |

**Valid status values:** `draft`, `open`, `seen`, `pending`, `review_required`, `in_progress`, `resolved`, `closed`

**Response `200`**
```json
{
  "data": [
    {
      "id": 1,
      "title": "Login Button Not Responding",
      "description": "Users report the login button does nothing on iOS 17.",
      "status": "open",
      "project_id": 1,
      "user_id": 1,
      "tenant_id": 1,
      "project": { "id": 1, "name": "Project Alpha", ... },
      "author": { "id": 1, "name": "Alice Smith", ... },
      "created_at": "2026-01-01T00:00:00.000000Z",
      "updated_at": "2026-01-01T00:00:00.000000Z"
    }
  ]
}
```

### Create Feedback
`POST /api/feedback` — *auth required*

**Request**
```json
{
  "project_id": 1,
  "title": "Login Button Not Responding",
  "description": "Users report the login button does nothing on iOS 17.",
  "status": "open"
}
```

| Field         | Type    | Required | Notes                              |
|---------------|---------|----------|------------------------------------|
| `project_id`  | integer | Yes      | Must exist in the database         |
| `title`       | string  | Yes      | Max 255 characters                 |
| `description` | string  | No       |                                    |
| `status`      | string  | No       | Defaults to `open`. Only `open` or `draft` allowed on creation. |

**Response `201`** — the created feedback resource.

Side effects (async, via queue):
- Notification dispatched to project members
- Vector embedding generated and stored in Pinecone
- Tenant metrics cache cleared

### Get Feedback
`GET /api/feedback/{id}` — *auth required*

**Response `200`** — single feedback resource (same shape as list item).
**Response `404`** — not found or belongs to another tenant.

### Update Feedback Status
`PATCH /api/feedback/{id}/status` — *auth required*

**Request**
```json
{ "status": "in_progress" }
```

**Valid values:** `draft`, `open`, `seen`, `pending`, `review_required`, `in_progress`, `resolved`, `closed`

**Response `200`** — updated feedback resource.
**Response `404`** — not found or belongs to another tenant.
**Response `422`** — invalid status value.

Side effects (async):
- Metrics cache cleared

### Delete Feedback
`DELETE /api/feedback/{id}` — *auth required*

Soft-deletes the feedback. The record remains in the database with a `deleted_at` timestamp.

**Response `200`**
```json
{ "message": "Feedback deleted." }
```

**Response `404`** — not found or belongs to another tenant.

---

## AI Analysis

### Semantic Search + Summarize
`POST /api/analysis/query` — *auth required*

Runs the full AI pipeline: embeds the query → finds semantically similar feedback via Pinecone → summarizes results with GPT-4o-mini.

**Request**
```json
{
  "query": "What are users saying about performance on mobile?",
  "top_k": 10
}
```

| Field   | Type    | Required | Notes                      |
|---------|---------|----------|----------------------------|
| `query` | string  | Yes      | 3–500 characters           |
| `top_k` | integer | No       | Defaults to 10. Max 25.    |

**Response `200`**
```json
{
  "data": {
    "query": "What are users saying about performance on mobile?",
    "feedback_found": 8,
    "summary": "**Key Themes:** Performance degradation on 3G/4G connections...\n**Critical Issues:** ...\n**Positive Feedback:** ...\n**Recommendations:** ...",
    "feedback": [ { ... }, { ... } ],
    "usage": {
      "tokens_used": 1240,
      "cost_usd": 0.000372
    }
  }
}
```

**Notes:**
- Results are filtered to the authenticated tenant — cross-tenant vectors are never returned
- If no matching feedback is found, `feedback_found` is `0` and `summary` explains there is no data
- Costs are tracked per tenant and subject to daily spending caps

---

## Metrics

### Tenant Dashboard Metrics
`GET /api/metrics` — *auth required*

Returns cached business metrics for the authenticated tenant. Cache TTL: 5 minutes.

**Response `200`**
```json
{
  "data": {
    "total_feedback": 28,
    "total_projects": 12,
    "total_users": 3,
    "feedback_by_status": {
      "open": 10,
      "in_progress": 5,
      "resolved": 8,
      "draft": 3,
      "pending": 2
    },
    "recent_activity": {
      "today": 3,
      "this_week": 14
    },
    "failed_jobs": 0
  }
}
```

---

## Error Responses

| Status | Meaning |
|--------|---------|
| `401`  | Unauthenticated — missing or invalid token |
| `403`  | Forbidden — authenticated but role does not permit this action |
| `404`  | Resource not found or belongs to another tenant |
| `422`  | Validation failed |
| `500`  | Server error — check logs |

**Validation error shape (`422`)**
```json
{
  "message": "The title field is required.",
  "errors": {
    "title": ["The title field is required."]
  }
}
```
