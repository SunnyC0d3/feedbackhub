# FeedbackHub — System Diagrams

All diagrams use [Mermaid](https://mermaid.js.org/) syntax. Render in GitHub, VS Code (Mermaid Preview extension), or [mermaid.live](https://mermaid.live).

---

## 1. Architecture Overview

High-level view of all system components and how they connect.

```mermaid
graph TB
    subgraph Client["Client Layer"]
        UI[Browser / API Client]
    end

    subgraph App["Laravel Application"]
        direction TB
        HTTP[HTTP Controllers]
        SVC[Service Layer<br/>FeedbackAnalysisService<br/>FeedbackManagementService<br/>AiService / CacheService]
        REPO[Repository Layer<br/>FeedbackRepository<br/>ProjectRepository]
        EVENTS[Event System<br/>FeedbackCreated<br/>FeedbackStatusChanged]
        LISTENERS[Listeners<br/>NotifyOnFeedbackCreated<br/>EmbedFeedbackOnCreated]
        JOBS[Background Jobs<br/>StoreFeedbackEmbedding<br/>SendIdempotentNotification<br/>CleanupExpiredInvitations]
    end

    subgraph Storage["Storage Layer"]
        MYSQL[(MySQL 8.0<br/>Primary Database)]
        REDIS[(Redis<br/>Cache only)]
        SQS[(SQS<br/>Job Queue)]
    end

    subgraph External["External APIs"]
        OPENAI[OpenAI<br/>GPT-4o-mini<br/>text-embedding-3-small]
        PINECONE[Pinecone<br/>Vector Database<br/>feedback-embeddings]
    end

    UI --> HTTP
    HTTP --> SVC
    SVC --> REPO
    REPO --> MYSQL
    SVC --> EVENTS
    EVENTS --> LISTENERS
    LISTENERS --> JOBS
    JOBS --> SQS
    SVC --> REDIS
    JOBS --> OPENAI
    JOBS --> PINECONE
    SVC --> OPENAI
    SVC --> PINECONE
```

---

## 2. Feedback Creation Data Flow

Traces exactly what happens from the moment a feedback item is created through to embedding storage.

```mermaid
sequenceDiagram
    actor User
    participant MS as FeedbackManagement<br/>Service
    participant FM as Feedback Model
    participant ES as EventServiceProvider
    participant N as NotifyOnFeedback<br/>Created
    participant E as EmbedFeedbackOn<br/>Created
    participant CM as ClearMetricsCache<br/>OnFeedback
    participant Q as SQS Queue
    participant J1 as SendIdempotent<br/>Notification Job
    participant J2 as StoreFeedback<br/>Embedding Job
    participant OAI as OpenAI API
    participant PIN as Pinecone API

    User->>MS: createFeedback(CreateFeedbackCommand)
    MS->>FM: Feedback::create([...])
    FM->>FM: booted() fires FeedbackCreated event
    FM-->>ES: dispatch(FeedbackCreated)

    par Listeners fire in parallel
        ES->>N: handle(FeedbackCreated)
        N->>Q: dispatch SendIdempotentNotification
    and
        ES->>E: handle(FeedbackCreated)
        E->>Q: dispatch StoreFeedbackEmbedding
    and
        ES->>CM: handle(FeedbackCreated)
        CM->>CM: Cache::forget(metrics key)
    end

    FM-->>MS: Feedback instance
    MS-->>User: Feedback created ✓

    Note over Q,J2: Queue worker processes async

    Q->>J1: run SendIdempotentNotification
    J1->>J1: check idempotency key in cache
    J1->>J1: send notification email
    J1->>J1: store idempotency key (24hr TTL)

    Q->>J2: run StoreFeedbackEmbedding
    J2->>OAI: POST /embeddings (feedback text)
    OAI-->>J2: 1536-dim vector
    J2->>PIN: upsert(vector, metadata)
    PIN-->>J2: upserted ✓
```

---

## 3. Multi-Tenant Isolation Model

Shows how tenant isolation is enforced at every layer of the stack.

```mermaid
graph TB
    subgraph TenantA["Tenant A — Compass Group (tenant_id = 1)"]
        UA[Alice, Bob]
        DA[Engineering, Product]
        PA[Project Alpha, Project Beta]
        FA[Feedback items 1–18]
    end

    subgraph TenantB["Tenant B — Acme Corporation (tenant_id = 2)"]
        UB[David, Eve]
        DB[Sales, Support]
        PB[Project Gamma, Project Delta]
        FB[Feedback items 19–36]
    end

    subgraph DB_Layer["MySQL — Shared Tables"]
        T_users["users<br/>tenant_id | email | name"]
        T_divisions["divisions<br/>tenant_id | name"]
        T_projects["projects<br/>tenant_id | division_id | name"]
        T_feedback["feedback<br/>tenant_id | project_id | title | status"]
    end

    subgraph Scope["Application Isolation Layer"]
        TS["TenantScope (Global Scope)<br/>WHERE tenant_id = auth()->user()->tenant_id"]
        BT["BelongsToTenant Trait<br/>auto-sets tenant_id on create<br/>auto-applies TenantScope"]
    end

    subgraph Vector["Pinecone — Vector Store"]
        V1["Vectors (tenant_id=1 metadata)"]
        V2["Vectors (tenant_id=2 metadata)"]
    end

    UA --> T_users
    UB --> T_users
    DA --> T_divisions
    DB --> T_divisions
    PA --> T_projects
    PB --> T_projects
    FA --> T_feedback
    FB --> T_feedback

    BT --> TS
    TS -->|"appended to every query"| T_users
    TS -->|"appended to every query"| T_divisions
    TS -->|"appended to every query"| T_projects
    TS -->|"appended to every query"| T_feedback

    FA --> V1
    FB --> V2

    note1["Pinecone filter:<br/>filter: ['tenant_id' => 1]"]
    V1 --- note1
```

---

## 4. API Integration Map

Shows all external service integrations, what data flows to each, and which internal components own the integration.

```mermaid
graph LR
    subgraph App["Laravel Application"]
        AS[AiService]
        ES[EmbeddingService]
        PS[PineconeService]
        JM[JobMonitor]
        Q[Queue Worker]
    end

    subgraph OpenAI["OpenAI"]
        EMB["text-embedding-3-small<br/>(1536-dim vectors)<br/>$0.02 / 1M tokens"]
        GPT["gpt-4o-mini<br/>(structured summaries)<br/>$0.15 in / $0.60 out per 1M"]
    end

    subgraph Pinecone["Pinecone"]
        IDX["feedback-embeddings index<br/>1536 dims, cosine similarity<br/>100K vectors free tier"]
    end

    subgraph Storage["Storage"]
        CACHE["Redis Cache<br/>Tenant-scoped keys<br/>TTL: 5m / 30m / 1h / 24h"]
        SQS_Q["SQS Queue<br/>3 retries, exponential backoff<br/>60s → 300s → 900s"]
        IDEMPOTENCY["Redis Idempotency Keys<br/>24hr TTL<br/>prevents duplicate jobs"]
    end

    ES -->|"feedback text"| EMB
    EMB -->|"1536-dim vector"| ES
    ES --> PS

    AS -->|"feedback array + prompt"| GPT
    GPT -->|"structured summary"| AS
    AS -->|"token count + cost"| CACHE

    PS -->|"upsert vector + metadata"| IDX
    PS -->|"query vector + tenant filter"| IDX
    IDX -->|"top-K matches + scores"| PS

    AS --> CACHE
    JM --> CACHE
    Q --> SQS_Q
    JM --> SQS_Q
    Q --> IDEMPOTENCY
```

---

## 5. Complete Semantic Search Pipeline

End-to-end flow when a user queries for semantically similar feedback.

```mermaid
sequenceDiagram
    actor User
    participant FAS as FeedbackAnalysis<br/>Service
    participant ES as EmbeddingService
    participant OAI as OpenAI<br/>text-embedding-3-small
    participant PS as PineconeService
    participant PIN as Pinecone Index
    participant FR as FeedbackRepository
    participant DB as MySQL
    participant AS as AiService
    participant GPT as OpenAI<br/>gpt-4o-mini

    User->>FAS: analyzeByQuery("login issues on mobile", tenant_id)

    FAS->>ES: generateEmbedding("login issues on mobile")
    ES->>OAI: POST /embeddings
    OAI-->>ES: [0.023, -0.118, ...] (1536 dims)
    ES-->>FAS: query vector

    FAS->>PS: query(vector, topK=10, filter={tenant_id})
    PS->>PIN: POST /query
    PIN-->>PS: [{id, score, metadata}, ...]
    PS-->>FAS: top-K matches

    FAS->>FR: findByIds([id1, id2, ...])
    FR->>DB: SELECT * FROM feedback WHERE id IN (...)
    DB-->>FR: Feedback collection
    FR-->>FAS: Feedback models

    FAS->>AS: summarizeFeedback(feedbackArray, tenant_id)
    AS->>AS: checkUsageLimits(tenant_id)
    AS->>GPT: POST /chat/completions (structured prompt)
    GPT-->>AS: Key Themes, Issues, Positives, Recommendations
    AS->>AS: trackUsage(tenant_id, tokens, cost)
    AS-->>FAS: {summary, tokens_used, cost_usd}

    FAS-->>User: {matches, summary, cost}
```

---

## 6. AWS Deployment Architecture (Month 8)

Production deployment using AWS free tier services.

```mermaid
graph TB
    subgraph Internet["Internet"]
        USER[Browser]
        GH[GitHub Actions]
    end

    subgraph AWS_Public["AWS — Public Subnet"]
        EC2["EC2 t2.micro<br/>nginx + PHP-FPM container<br/>queue worker container<br/>Redis container"]
    end

    subgraph AWS_Private["AWS — Private Subnet"]
        RDS["RDS MySQL 8.0<br/>db.t3.micro single-AZ"]
    end

    subgraph AWS_Serverless["AWS — Serverless / Global"]
        CF[CloudFront CDN]
        S3[S3 Bucket<br/>React frontend]
        EB[EventBridge<br/>every 1 min]
        LAM[Lambda<br/>scheduler trigger]
        SQS_D[SQS Queue<br/>+ Dead Letter Queue]
        ECR[ECR<br/>Docker registry]
        SSM[SSM Parameter Store<br/>secrets]
        CW[CloudWatch<br/>logs + alarms]
    end

    USER -->|HTTPS API calls| EC2
    USER -->|React app| CF
    CF --> S3

    GH -->|OIDC → push image| ECR
    GH -->|SSM Run Command → deploy| EC2
    GH -->|s3 sync| S3
    GH -->|CF invalidation| CF

    EC2 -->|reads secrets at startup| SSM
    EC2 -->|polls jobs| SQS_D
    EC2 -->|queries| RDS
    EC2 -->|ships logs| CW

    EB -->|every minute| LAM
    LAM -->|SSM Run Command| EC2
```
