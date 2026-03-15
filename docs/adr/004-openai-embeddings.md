# ADR 004: OpenAI for Embeddings and Summarization

## Status
Accepted

## Context

FeedbackHub needs two AI capabilities:

1. **Text embeddings** — converting feedback text into vectors for semantic search
2. **Summarization** — generating structured summaries of multiple feedback items for a given query

We evaluated which AI provider(s) to use for each capability.

**For embeddings**, options considered:
- OpenAI `text-embedding-3-small` — $0.02 per 1M tokens
- OpenAI `text-embedding-3-large` — $0.13 per 1M tokens
- Cohere Embed — $0.10 per 1M tokens
- Claude (Anthropic) — does not offer embedding models

**For summarization**, options considered:
- OpenAI `gpt-4o-mini` — Input $0.150/1M tokens, Output $0.600/1M tokens
- OpenAI `gpt-4o` — ~10x more expensive
- Claude `claude-haiku` / `claude-sonnet` — comparable capability and pricing
- Local LLM (Ollama, LLaMA) — free but requires GPU infrastructure

## Decision

**Embeddings:** OpenAI `text-embedding-3-small`
**Summarization:** OpenAI `gpt-4o-mini`

We consolidate on a single provider (OpenAI) to simplify API key management, billing, and error handling. Both models represent the best cost/performance ratio in their respective categories.

Prompts for summarization are structured to return four sections: Key Themes, Critical Issues, Positive Feedback, and Recommendations.

Usage is tracked per tenant (daily aggregation in cache) with configurable daily spending caps enforced in `AiService::checkUsageLimits()`.

## Consequences

**Pros:**
- `text-embedding-3-small` is extremely cheap — embedding 1M tokens costs $0.02, making per-feedback costs negligible
- `gpt-4o-mini` is fast and cost-effective for structured summarization tasks
- Single provider simplifies auth, billing, and SDK management
- OpenAI's APIs are mature, well-documented, and have generous rate limits

**Cons:**
- Single-provider dependency — an OpenAI outage takes down both AI features simultaneously
- Data privacy — feedback text is sent to OpenAI's servers (relevant for compliance-sensitive tenants)
- Cost can grow unbounded without limits — mitigated by daily caps and usage tracking
- Claude (Anthropic) has no embedding model, so we can't be fully Anthropic-native even if preferred

**Cost estimates at scale:**
- 10,000 feedback items embedded: ~$0.002 (effectively free)
- 1,000 summarization requests/month (avg 500 tokens in/out): ~$0.38/month
- Costs only become meaningful at enterprise scale (millions of requests)

**Future consideration:**
If compliance requirements emerge (e.g., HIPAA customers who can't send data to OpenAI), the `EmbeddingService` and `AiService` are abstracted behind service classes, making it straightforward to swap in a self-hosted model without changing calling code.
