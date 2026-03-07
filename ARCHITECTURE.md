# FeedbackHub - Architecture Documentation

**Project:** Multi-Tenant Feedback & Issue Platform  
**Author:** Sukh  
**Date:** March 2026  
**Status:** Month 1 Complete - Foundation Built

---

## Table of Contents

1. [System Overview](#system-overview)
2. [Domain Model](#domain-model)
3. [Multi-Tenant Architecture](#multi-tenant-architecture)
4. [Database Schema](#database-schema)
5. [Security Layers](#security-layers)
6. [Key Design Decisions](#key-design-decisions)
7. [Trade-offs & Constraints](#trade-offs--constraints)
8. [What's Next](#whats-next)

---

## System Overview

### Problem Statement
### Problem Statement

Organizations struggle to collect, organize, and track feedback across multiple teams and projects. Issues get lost in email threads, Slack channels, and spreadsheets, making it impossible to prioritize improvements or identify patterns.

FeedbackHub solves this by providing a centralized, multi-tenant SaaS platform where companies can:
- Organize feedback across divisions and projects
- Control access through role-based permissions
- Track feedback lifecycle from submission to resolution
- Maintain complete data isolation between tenants

The platform serves aggregators (like Compass Group) with complex organizational structures spanning multiple brands and divisions, ensuring each team sees only relevant feedback while admins maintain visibility across the entire organization.

### Core Features
- Multi-tenant SaaS platform
- Organizational structure: Tenants → Divisions → Projects → Feedback
- Role-based access control per division
- Feedback lifecycle management (draft → closed)
- User invitation system with expiry

### Tech Stack
- **Backend:** PHP 8.1, Laravel 11
- **Database:** MySQL 8.0
- **Authentication:** Laravel Sanctum
- **Caching:** Redis (Month 2)
- **Queues:** Redis (Month 2)

---

## Domain Model

### Entities

#### Core Entities
1. **Tenant** - A company/organization using the platform
2. **Division** - Organizational unit within a tenant (departments, brands, teams)
3. **User** - People who use the system (belongs to one tenant)
4. **Project** - Work initiatives where feedback is collected
5. **Feedback** - Issues, bugs, feature requests submitted on projects

#### Supporting Entities
6. **Invitation** - Pending user invitations with token-based access
7. **UserDivision** - Pivot: User roles per division
8. **UserProject** - Pivot: User project assignments

### Entity Relationships
```
Tenant (1) ──→ (Many) Divisions
Tenant (1) ──→ (Many) Users
Tenant (1) ──→ (Many) Projects
Tenant (1) ──→ (Many) Feedback

Division (1) ──→ (Many) Projects
Division (Many) ←→ (Many) Users [via user_divisions, stores role]

Project (1) ──→ (Many) Feedback
Project (Many) ←→ (Many) Users [via user_projects, tracks assignment]

User (1) ──→ (Many) Feedback [as creator]
User (1) ──→ (Many) Invitations [as inviter]
```

---

## Multi-Tenant Architecture

### Tenant Isolation Strategy

**Approach:** Shared database with tenant_id filtering

### Why this approach:

**Decision:** Shared database with tenant_id filtering (vs. separate database per tenant)

**Reasoning:**

1. **MVP Simplicity**
    - Reduces infrastructure complexity during initial development
    - Single database to manage, backup, and monitor
    - Faster iteration and deployment cycles

2. **Cost Efficiency**
    - Lower hosting costs at small scale (2-100 tenants)
    - Single connection pool vs. managing hundreds of connections
    - Simpler backup and disaster recovery strategy

3. **Development Speed**
    - No multi-database connection logic required
    - Standard Eloquent queries with global scopes
    - Easier to seed, test, and debug

4. **Scalability Path**
    - Can migrate to separate databases later if needed
    - Global scopes make this transition easier (tenant filtering already exists)
    - Performance can be monitored before making infrastructure changes

**When to reconsider:**
- Individual tenants exceed 1M records
- Noisy neighbor issues impact performance
- Compliance requires physical data separation
- Tenant count exceeds 1000+

**Trade-offs Accepted:**
- All tenants share database resources (noisy neighbor risk)
- Schema changes affect all tenants simultaneously
- Cannot offer tenant-specific database configurations
- Single point of failure (mitigated by backups and replication)

**Security Mitigation:**
- Global scopes enforce tenant_id filtering at application level
- Database-level row security (RLS) can be added later if needed
- All queries tested to prevent cross-tenant data leaks

**Implementation:**
- Global scope (`TenantScope`) on all tenant-scoped models
- Automatic `WHERE tenant_id = ?` on every query
- Auto-set `tenant_id` on record creation

**Models with tenant isolation:**
- Division
- User
- Project
- Feedback
- Invitation

**Models without tenant isolation:**
- Tenant (obviously)
- UserDivision, UserProject (pivot tables)

### How It Works
```php
// Without authentication - sees all data
Division::all();  // Returns all divisions

// After login - automatic filtering
auth()->login($user);
Division::all();  // Only returns user's tenant divisions

// Security: Cannot access other tenant's data even with ID
Division::find($otherTenantDivisionId);  // Returns null
```

---

## Database Schema

### Tables Overview

| Table | Rows (Test) | Purpose |
|-------|-------------|---------|
| tenants | 2 | Companies using the platform |
| divisions | 6 | Organizational units |
| users | 5 | Platform users |
| projects | 12 | Work initiatives |
| feedback | 24-36 | Issues/features/bugs |
| user_divisions | ~10 | User roles per division |
| user_projects | 2 | Member project assignments |
| invitations | 3 | Pending invites |

### Key Constraints

#### Unique Constraints

**Tenants Table:**
```sql
-- tenants.name: UNIQUE (name)
-- Why: Prevents duplicate company names across the platform
-- Decision: Enforced at database level to ensure no confusion
-- Tradeoff: "Compass Group LLC" and "Compass Group Inc" would conflict

-- tenants.slug: UNIQUE (slug)
-- Why: Used in URLs (feedbackhub.com/compass-group), must be globally unique
-- Technical: Required for routing and tenant identification
```

**Divisions Table:**
```sql
-- divisions: UNIQUE (tenant_id, name)
-- Why: Compass cannot have two divisions both named "Sales"
-- Allows: Compass "Sales" AND Acme "Sales" (different tenants)
-- UX benefit: Prevents user confusion within one organization

-- divisions: UNIQUE (tenant_id, slug)
-- Why: Used in URLs (compass-group/sales/projects), must be unique per tenant
-- Allows: Multiple tenants can use same slug (sales, engineering, etc.)
```

**Users Table:**
```sql
-- users: UNIQUE (tenant_id, email)
-- Why: bob@email.com can exist at Compass AND Acme (different tenants)
-- Security: Strong tenant isolation - no cross-tenant user access
-- Tradeoff: Login must specify tenant (more complex auth flow)
```

**Projects Table:**
```sql
-- projects: UNIQUE (tenant_id, division_id, name)
-- Why: E-Foods cannot have two "Mobile App" projects
-- Allows: E-Foods "Mobile App" AND Bidfood "Mobile App" (different divisions)

-- projects: UNIQUE (tenant_id, division_id, slug)
-- Why: Used in URLs, must be unique per division
-- Pattern: /compass-group/e-foods/mobile-app
```

**User Relationships:**
```sql
-- user_divisions: UNIQUE (user_id, division_id)
-- Why: User can only have ONE role per division
-- Prevents: Alice being both Manager AND Member in same division
-- Allows: Alice being Manager in E-Foods AND Member in Bidfood

-- user_projects: UNIQUE (user_id, project_id)
-- Why: User can only be assigned to a project once
-- Prevents: Duplicate assignment records
```

**Invitations Table:**
```sql
-- invitations: UNIQUE (token)
-- Why: Security - each invite link must be globally unique
-- Technical: 64-character random token for URL access

-- invitations: UNIQUE (tenant_id, email)
-- Why: Prevents duplicate pending invites to same email
-- UX: Cannot send multiple invites to bob@email.com at Compass
-- Note: After acceptance/expiry, new invite can be sent (row deleted)
```

---

#### Foreign Keys

**Cascade Strategy: RESTRICT on ALL foreign keys**
```sql
-- Why RESTRICT everywhere:
-- 1. Explicit cleanup required before deletion
-- 2. Prevents accidental data loss from cascading deletes
-- 3. Matches soft-delete strategy (deactivate, then clean up, then delete)
-- 4. Forces developers to think about data dependencies

-- Example cascade rules:
FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE RESTRICT
FOREIGN KEY (division_id) REFERENCES divisions(id) ON DELETE RESTRICT
FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT
FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE RESTRICT

-- Tradeoff:
-- ❌ More code required for deletions (manual cleanup)
-- ✅ Maximum safety - cannot accidentally orphan data
-- ✅ Clear audit trail of what needs cleanup
-- ✅ Explicit intent in deletion logic

-- Example deletion flow:
// To delete a user:
$user->divisions()->detach();      // Manual step 1
$user->projects()->detach();       // Manual step 2  
$user->feedbacks()->delete();      // Manual step 3
$user->delete();                   // Final step

-- vs CASCADE (dangerous):
$user->delete();  // Auto-deletes everything, no control
```

**Exception: user_projects.assigned_by_user_id**
```sql
FOREIGN KEY (assigned_by_user_id) REFERENCES users(id) ON DELETE RESTRICT

-- Why RESTRICT here too:
-- When Manager Alice assigns Bob to a project, then Alice gets deleted
-- RESTRICT forces: Update assigned_by_user_id to NULL manually first
-- Alternative considered: SET NULL (auto-cleanup)
-- Decision: Kept RESTRICT for consistency across all FKs
```

---

#### Indexes

**Primary Indexes (Auto-Created):**
```sql
-- Every table has PRIMARY KEY (id)
-- Auto-indexed as BTREE
-- Used for: Direct ID lookups, joins
```

**Unique Constraint Indexes (Auto-Created):**
```sql
-- All UNIQUE constraints automatically create indexes
-- Examples:
-- tenants: UNIQUE (name) → creates index on name
-- tenants: UNIQUE (slug) → creates index on slug
-- users: UNIQUE (tenant_id, email) → creates index on (tenant_id, email)
```

**Strategic Performance Indexes:**

**Feedback Table:**
```sql
-- INDEX idx_project_status (project_id, status)
-- Why: Optimizes filtering feedback by status within a project
-- Query: SELECT * FROM feedback WHERE project_id = ? AND status = 'pending'
-- Usage: Dashboard showing "pending feedback for Mobile App project"
-- Frequency: High (every project dashboard load)

-- INDEX idx_project_created (project_id, created_at)
-- Why: Optimizes sorting feedback by date within a project
-- Query: SELECT * FROM feedback WHERE project_id = ? ORDER BY created_at DESC
-- Usage: "Show latest feedback for this project"
-- Frequency: High (default sort order)
```

**User Divisions Table:**
```sql
-- INDEX idx_division_role (division_id, role)
-- Why: Optimizes finding users by role within a division
-- Query: SELECT * FROM user_divisions WHERE division_id = ? AND role = 'manager'
-- Usage: "Find all managers in E-Foods division"
-- Frequency: Medium (permission checks, admin views)
```

**User Projects Table:**
```sql
-- INDEX idx_project_user (project_id, user_id)
-- Why: Optimizes finding users assigned to a project
-- Query: SELECT * FROM user_projects WHERE project_id = ?
-- Usage: "Show all users on Mobile App project"
-- Frequency: High (project member lists)

-- Note: Also have UNIQUE (user_id, project_id) for reverse lookup
-- Covers: "Show all projects for user X"
```

**Invitations Table:**
```sql
-- INDEX idx_expires_at (expires_at)
-- Why: Optimizes cleanup job for expired invitations
-- Query: DELETE FROM invitations WHERE expires_at < NOW()
-- Usage: Daily cron job to purge expired invites
-- Frequency: Low (daily), but important for cleanup
```

**Foreign Key Indexes (Auto-Created by Laravel):**
```sql
-- Laravel's foreignId()->constrained() automatically creates indexes:
-- divisions: INDEX (tenant_id)
-- users: INDEX (tenant_id)
-- projects: INDEX (tenant_id), INDEX (division_id)
-- feedback: INDEX (tenant_id), INDEX (project_id), INDEX (user_id)

-- Why these are important:
-- Every query filters by tenant_id first (tenant isolation)
-- Joins between tables use these foreign keys
```

**Index Strategy Summary:**
- ✅ Primary keys (id) on all tables
- ✅ Unique constraints double as indexes
- ✅ Foreign keys auto-indexed by Laravel
- ✅ Strategic composite indexes on common query patterns
- ✅ Cleanup indexes for background jobs

**What We're NOT Indexing:**
- ❌ Low-cardinality columns (active boolean) - not selective enough
- ❌ Rarely queried columns (description text fields)
- ❌ Columns only used in SELECT, not WHERE/JOIN

---

## Security Layers

### Layer 1: Tenant Isolation (Hard Boundary)

**Rule:** Users CANNOT see data from other tenants  
**Enforcement:** Global scope on all queries  
**Result:** Cross-tenant data access is impossible

**Test results:**
```
✅ Alice (Compass) sees 6 projects (Compass only)
✅ Bob (Acme) sees 6 projects (Acme only)
✅ Alice cannot access Acme projects even with ID
```

### Layer 2: Division Access (Soft Boundary)

**Rule:** Users see only divisions they're assigned to  
**Enforcement:** user_divisions pivot table with roles  
**Roles:** Admin, Manager, Member, Support

**Example:**
- Alice: Compass Admin → sees ALL Compass divisions
- Bob: E-Foods Manager → sees only E-Foods division
- Carol: E-Foods Member → sees only E-Foods division

### Layer 3: Project Assignment (Granular)

**Rule:** Members see only assigned projects  
**Enforcement:** user_projects pivot table  
**Exception:** Admins/Managers bypass this (see all division projects)

**Example:**
- Carol (Member) assigned to "Mobile App" → sees only that project
- Bob (Manager) → sees ALL E-Foods projects automatically

---

## Key Design Decisions

### Decision 1: Email Uniqueness

**Choice:** Email unique per tenant (not globally)

**Reasoning:**
- bob@email.com can exist at Compass AND Acme
- Users belong to ONE tenant only (from domain doc)
- Stronger tenant isolation

**Tradeoff:**
- More complex login (must identify tenant first)
- Pro: Flexibility for users with same email across tenants

### Decision 2: Division Structure

**Choice:** Always create default division, hide if only one exists

**Reasoning:**
- Keeps data model simple (no NULL division_id checks)
- Small tenants don't see complexity
- Scales naturally as org grows

**Tradeoff:**
- Slight storage overhead
- Pro: Consistent code, no special cases

### Decision 3: Foreign Key Cascades

**Choice:** RESTRICT on all foreign keys

**Reasoning:**
- Forces explicit cleanup before deletion
- Prevents accidental data loss
- Matches soft-delete strategy

**Tradeoff:**
- More deletion code required
- Pro: Maximum safety, clear audit trail

### Decision 4: Pivot Table Design

**Choice:** No soft deletes on pivot tables

**Reasoning:**
- Relationships are temporary
- Hard delete is simpler
- No need for historical "was once a member" data

**Tradeoff:**
- Cannot recover accidentally removed assignments
- Pro: Simpler queries, smaller tables

---

## Trade-offs & Constraints

### What We Optimized For
- **Security:** Tenant isolation is automatic and unbreakable
- **Simplicity:** Single database, straightforward queries
- **Safety:** RESTRICT cascades prevent accidents
- **Performance:** Strategic indexes on common query patterns

### What We Sacrificed
- **Flexibility:** Cannot have cross-tenant users
- **Speed:** Deletion requires manual cleanup
- **Isolation:** All tenants share same DB (noisy neighbor risk)
- **Complexity:** Some orgs might not need divisions

### Constraints Accepted
- Single database for all tenants (for MVP)
- Users belong to one tenant only
- Projects belong to one division only
- Email must be unique per tenant

---

## What's Next

### Month 2: Performance & Scaling
- [ ] Run EXPLAIN on heavy queries
- [ ] Install Redis for caching
- [ ] Move email notifications to queues
- [ ] Add retry policies and failure handling

### Month 3: Reliability & Observability
- [ ] Add structured logging
- [ ] Track metrics (feedback count, job failures)
- [ ] Simulate failure scenarios

### Month 4: AI Integration
- [ ] Add "Summarize feedback per project" feature
- [ ] Track token usage and costs
- [ ] Rate limiting per tenant

### Future Considerations
- Separate databases per tenant (if needed)
- Real-time notifications (WebSockets)
- Public feedback portals
- API for third-party integrations

---

**Last Updated:** [Date]  
**Version:** 1.0 - Month 1 Complete
