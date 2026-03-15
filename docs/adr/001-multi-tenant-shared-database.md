# ADR 001: Multi-Tenant Shared Database

## Status
Accepted

## Context

FeedbackHub is a multi-tenant SaaS platform where multiple companies (tenants) store feedback, projects, and users. We needed a tenancy strategy that balances isolation, cost, and operational simplicity for an MVP-stage product.

Three common approaches were considered:

1. **Database-per-tenant** — each tenant gets their own database
2. **Schema-per-tenant** — each tenant gets their own schema within a shared database
3. **Shared database with tenant_id** — all tenants share tables, rows are scoped by `tenant_id`

## Decision

We use a **shared database with `tenant_id` column on every tenant-scoped table**.

Isolation is enforced at the application layer via:
- A `TenantScope` global scope that automatically appends `WHERE tenant_id = ?` to all queries
- A `BelongsToTenant` trait applied to all tenant-scoped models that activates the scope and auto-sets `tenant_id` on creation
- All 5 core tables (`divisions`, `users`, `projects`, `feedback`, `invitations`) use this pattern

## Consequences

**Pros:**
- Simple to operate — one database, one set of migrations, one backup
- Low cost — no per-tenant database provisioning
- Easy to query across tenants for platform-level analytics (admin tooling)
- Laravel global scopes make isolation automatic and hard to accidentally bypass

**Cons:**
- A bug in the scoping layer could expose cross-tenant data — the most critical risk
- A single large tenant can affect query performance for others (noisy neighbour)
- Harder to offer tenant-level data exports or wipes (must filter every table)
- Compliance requirements (GDPR, HIPAA) may eventually force per-tenant isolation

**Mitigations:**
- `TenantScope` is tested in `TenantIsolationTest` — cross-tenant queries are verified impossible
- Indexes always include `tenant_id` as a leading or compound column
- Email uniqueness is enforced per-tenant (not globally), preventing cross-tenant collisions
- If a tenant requires dedicated isolation in future, the `tenant_id` column makes migration to database-per-tenant straightforward
