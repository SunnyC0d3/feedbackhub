# ADR 003: Pinecone for Semantic Search (Vector Database)

## Status
Accepted

## Context

FeedbackHub needs semantic search — the ability to find feedback by meaning rather than exact keyword matches. For example, a query for "slow load times" should surface feedback about "performance issues" or "app feels sluggish" even if those exact words aren't used.

This requires storing vector embeddings (1536-dimensional float arrays) and performing fast approximate nearest-neighbour (ANN) queries against them. Options considered:

1. **Pinecone** — managed vector database, purpose-built for ANN search
2. **pgvector** — PostgreSQL extension for vector similarity search
3. **Weaviate / Qdrant / Milvus** — open-source vector databases (self-hosted)
4. **MySQL full-text search** — keyword-based, not semantic

## Decision

We use **Pinecone** as the vector database.

- Index: `feedback-embeddings` (1536 dimensions, cosine similarity)
- Each feedback item gets one vector, stored with metadata: `feedback_id`, `tenant_id`, `project_id`, `title`, `status`
- Tenant filtering is applied at query time via Pinecone's metadata filter (`filter: ['tenant_id' => $id]`)
- Embeddings are generated asynchronously via the `StoreFeedbackEmbedding` background job

## Consequences

**Pros:**
- Fully managed — no infrastructure to run or maintain
- Purpose-built for ANN search — fast and accurate at scale
- Simple REST API, easy to integrate
- Free tier supports 100K vectors (sufficient for MVP and early growth)
- Metadata filtering means tenant isolation works at the vector DB layer too

**Cons:**
- External dependency — adds latency and a potential failure point
- Cost at scale — $70+/month for production index beyond free tier
- Vendor lock-in — migrating away requires re-embedding all feedback
- Eventual consistency — embedding is async, so newly created feedback isn't immediately searchable

**Why not pgvector:**
pgvector would eliminate an external dependency and stay within MySQL... except pgvector requires PostgreSQL. Migrating from MySQL to PostgreSQL was considered out of scope for this project. If we were starting fresh on PostgreSQL, pgvector would be the preferred option for simplicity and cost.

**Why not self-hosted (Weaviate/Qdrant):**
Self-hosting adds operational burden (deployment, monitoring, backups) that isn't justified at MVP scale. Pinecone's managed offering gives us production-grade vector search for free at current data volumes.
