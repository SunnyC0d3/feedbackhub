# ADR 002: ON DELETE RESTRICT on All Foreign Keys

## Status
Accepted

## Context

When designing the database schema, we had to choose a deletion strategy for foreign key constraints. The main options were:

1. **ON DELETE CASCADE** — child rows are automatically deleted when the parent is deleted
2. **ON DELETE SET NULL** — child rows have their foreign key set to NULL
3. **ON DELETE RESTRICT** — deletion is blocked if child rows exist (must clean up manually)

The platform has a deep hierarchy: `Tenant → Division → Project → Feedback`, plus pivot tables for user assignments and invitations. Deleting a tenant, for example, would need to cascade through potentially thousands of rows.

## Decision

**All foreign keys use `ON DELETE RESTRICT`.**

No cascading deletes exist anywhere in the schema. Every deletion must be handled explicitly in application code, working from the leaves up the hierarchy.

Additionally, core tables (`tenants`, `users`, `projects`, `feedback`, `divisions`) use **soft deletes** (`deleted_at`) rather than hard deletes, so most "deletions" don't remove rows at all.

## Consequences

**Pros:**
- Prevents accidental mass deletion — you cannot delete a tenant and silently wipe all its data
- Creates a clear audit trail — soft-deleted rows remain in the database
- Forces intentional cleanup code, which is easier to review and test
- Soft deletes allow data recovery if something is deleted by mistake
- Pivot tables (`user_divisions`, `user_projects`, `invitations`) use hard deletes since these relationships are genuinely temporary

**Cons:**
- More application code required for deletions — must delete or soft-delete children before parents
- Tests must account for constraint violations when testing deletion order
- Slightly more complex queries due to `WHERE deleted_at IS NULL` filtering (handled automatically by Laravel's `SoftDeletes` trait)

**Why not CASCADE:**
Cascade deletes are fast to implement but dangerous at scale. A single erroneous `$tenant->delete()` call would wipe every user, project, and feedback item for that tenant with no recovery path. RESTRICT forces the developer to write explicit cleanup, making the blast radius of any bug much smaller.
