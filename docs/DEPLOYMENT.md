# AWS Deployment Guide

This guide deploys FeedbackHub to AWS using Docker, ECS Fargate, RDS, SQS, Lambda, and a GitHub Actions CI/CD pipeline. The goal is a production-grade architecture that showcases a wide range of AWS services.

---

## AWS Services Used

| Service | Purpose | Why |
|---------|---------|-----|
| **ECR** | Docker image registry | Stores the containerised app; ECS pulls from it |
| **ECS Fargate** | Serverless containers | Runs API, queue worker, and scheduled task — no EC2 to manage |
| **RDS MySQL 8.0** | Production database | Managed MySQL with Multi-AZ failover and automated backups |
| **ElastiCache Redis** | Cache layer | Laravel `CACHE_DRIVER=redis`; Redis handles cache only (not queues) |
| **SQS** | Job queue | Replaces Redis queues; durable, managed, has dead-letter queue support |
| **S3** | React frontend hosting | Static build output served via CloudFront |
| **CloudFront** | CDN for frontend | Global edge caching; handles client-side routing with custom 404→200 rule |
| **ALB** | Load balancer | Routes HTTPS traffic to ECS API tasks; terminates TLS |
| **Lambda** | Serverless function | Triggers `php artisan schedule:run` every minute via EventBridge |
| **EventBridge Scheduler** | Cron trigger | Fires Lambda every minute to run scheduled tasks |
| **Secrets Manager** | Environment variables | All `.env` secrets injected into ECS tasks at runtime — no secrets in images |
| **ACM** | SSL certificates | Free, auto-renewing HTTPS certificate attached to ALB and CloudFront |
| **Route 53** | DNS | `api.yourdomain.com` → ALB; `app.yourdomain.com` → CloudFront |
| **IAM** | Roles and policies | Least-privilege roles for ECS, Lambda, and GitHub Actions |
| **VPC** | Network isolation | Public subnets for ALB; private subnets for ECS, RDS, ElastiCache |
| **CloudWatch** | Logs, metrics, alarms | Structured log ingestion, metric filters on app events, threshold alarms |
| **GitHub Actions + OIDC** | CI/CD | Tests → build → push → deploy; no long-lived AWS keys |

---

## Architecture Diagram

```
Internet
  └── Route 53
        ├── api.yourdomain.com  ──→  ALB  ──→  ECS Fargate: feedbackhub-web (2 tasks)
        └── app.yourdomain.com  ──→  CloudFront  ──→  S3 (React build)

VPC (10.0.0.0/16)
  ├── Public Subnets (AZ-a 10.0.1.0/24, AZ-b 10.0.2.0/24)
  │     ├── ALB (internet-facing)
  │     └── NAT Gateway (outbound for OpenAI / Pinecone calls)
  │
  └── Private Subnets (AZ-a 10.0.3.0/24, AZ-b 10.0.4.0/24)
        ├── ECS Fargate: feedbackhub-web      (Nginx + PHP-FPM, autoscales 1–10)
        ├── ECS Fargate: feedbackhub-worker   (queue:work sqs, autoscales 1–5)
        ├── RDS MySQL 8.0 (Multi-AZ, db.t3.medium)
        └── ElastiCache Redis 7 (cache.t3.micro, cache-only)

Serverless
  └── EventBridge Scheduler (every 1 min)
        └── Lambda: feedbackhub-scheduler-trigger (Python, ~20 lines)
              └── ECS RunTask: feedbackhub-scheduler
                    └── runs: php artisan schedule:run  →  exits

CI/CD
  └── GitHub Actions (push to master)
        ├── Run PHPUnit tests (MySQL service container)
        ├── TypeScript typecheck + React build
        ├── Build Docker image → push to ECR
        ├── Update ECS task definition + force new deployment
        ├── Sync frontend/dist/ → S3
        └── CloudFront cache invalidation

Secrets
  └── AWS Secrets Manager → injected into ECS task environment at runtime

Observability
  └── CloudWatch Logs (all ECS + Lambda log groups, 30-day retention)
  └── CloudWatch Metric Filters (job_failed, slow_query, api_error events)
  └── CloudWatch Alarms → SNS → email alerts
  └── CloudWatch Container Insights (per-task CPU/memory/network)
```

---

## Key Architecture Decisions

### SQS replaces Redis for queues
In production, ElastiCache has no persistence by default. If the Redis node restarts, queued jobs are lost. SQS is 11-nines durable, has Dead Letter Queue support, and integrates natively with Laravel's SQS driver. Redis is kept for `CACHE_DRIVER=redis` only.

### Three ECS task types, one Docker image
The same image runs as three different ECS services by overriding the `CMD`:
- **Web**: `php-fpm` behind Nginx on port 80 (ALB target)
- **Worker**: `php artisan queue:work sqs --sleep=3 --tries=3 --max-time=3600`
- **Scheduler**: `php artisan schedule:run` (runs once and exits — triggered by Lambda)

### Lambda as scheduler trigger
EventBridge Scheduler fires Lambda every minute. Lambda calls `ecs:RunTask` to launch a short-lived scheduler task, waits for it to complete, and logs the result. This replaces the `* * * * * php artisan schedule:run` cron with a proper serverless + container pattern.

### GitHub Actions OIDC — no long-lived AWS keys
GitHub Actions uses OIDC federation to assume an IAM role. No `AWS_ACCESS_KEY_ID` or `AWS_SECRET_ACCESS_KEY` secrets are stored in GitHub. The IAM role trust policy restricts to `repo:your-org/feedback-hub:ref:refs/heads/master` only.

---

## File Structure Created in This Step

```
feedback-hub/
├── Dockerfile                                    # Multi-stage PHP 8.1 build
├── .dockerignore                                 # Excludes node_modules, vendor, .env, tests
├── docker/
│   ├── nginx.conf                                # API proxy: forwards all traffic to PHP-FPM
│   ├── supervisord.conf                          # Runs Nginx + PHP-FPM together in web container
│   └── php.ini                                   # OPcache settings for production
├── docker-compose.yml                            # Local development only (not used in AWS)
├── .github/
│   └── workflows/
│       └── deploy.yml                            # CI/CD pipeline
└── infrastructure/
    ├── terraform/
    │   ├── main.tf                               # Provider, backend (S3 state)
    │   ├── variables.tf                          # All configurable values
    │   ├── outputs.tf                            # ALB DNS, CloudFront URL, ECR URL
    │   ├── vpc.tf                                # VPC, subnets, IGW, NAT, route tables
    │   ├── security_groups.tf                    # sg-alb, sg-ecs, sg-rds, sg-redis
    │   ├── ecr.tf                                # ECR repository + lifecycle policy
    │   ├── ecs.tf                                # Cluster, task definitions, services, autoscaling
    │   ├── rds.tf                                # MySQL 8.0 Multi-AZ instance
    │   ├── elasticache.tf                        # Redis cache cluster
    │   ├── sqs.tf                                # Main queue + DLQ with redrive policy
    │   ├── alb.tf                                # ALB, listeners, target group
    │   ├── s3.tf                                 # Frontend bucket + ALB access logs bucket
    │   ├── cloudfront.tf                         # Distribution with OAC, SPA routing
    │   ├── acm.tf                                # SSL certificates (ALB + CloudFront)
    │   ├── route53.tf                            # DNS records for api + app subdomains
    │   ├── iam.tf                                # All roles: ECS exec, task, Lambda, GitHub OIDC
    │   ├── secrets.tf                            # Secrets Manager secret resources (no values)
    │   ├── lambda.tf                             # Scheduler trigger function + EventBridge rule
    │   └── cloudwatch.tf                         # Log groups, metric filters, alarms, dashboard
    └── lambda/
        └── scheduler_trigger/
            └── handler.py                        # boto3 ECS RunTask call (~25 lines)
```

---

## Prerequisites

- AWS account with admin access
- AWS CLI v2 installed and configured (`aws configure`)
- Terraform 1.6+ installed
- Docker installed and running
- A registered domain in Route 53 (or you can use the ALB/CloudFront URLs directly)
- GitHub repository for the project

---

## Step-by-Step Deployment

### Step 1 — Bootstrap Terraform State

Terraform needs an S3 bucket to store state and a DynamoDB table for state locking. Create these manually once:

```bash
# Create state bucket (choose a unique name)
aws s3 mb s3://feedbackhub-terraform-state --region us-east-1
aws s3api put-bucket-versioning \
  --bucket feedbackhub-terraform-state \
  --versioning-configuration Status=Enabled

# Create DynamoDB lock table
aws dynamodb create-table \
  --table-name feedbackhub-terraform-locks \
  --attribute-definitions AttributeName=LockID,AttributeType=S \
  --key-schema AttributeName=LockID,KeyType=HASH \
  --billing-mode PAY_PER_REQUEST \
  --region us-east-1
```

### Step 2 — Configure Terraform Variables

```bash
cd infrastructure/terraform
cp terraform.tfvars.example terraform.tfvars
```

Edit `terraform.tfvars`:

```hcl
aws_region     = "us-east-1"
domain_name    = "yourdomain.com"
db_password    = "STRONG_PASSWORD_HERE"
app_name       = "feedbackhub"
environment    = "prod"
```

### Step 3 — Provision Infrastructure

```bash
cd infrastructure/terraform

terraform init
terraform plan    # Review — ~50 resources
terraform apply   # Takes 15–20 min (RDS Multi-AZ is slow to provision)
```

After apply, note the outputs:
```
alb_dns_name       = "feedbackhub-alb-XXXX.us-east-1.elb.amazonaws.com"
cloudfront_url     = "dXXXXXXXXXXXX.cloudfront.net"
ecr_repository_url = "123456789.dkr.ecr.us-east-1.amazonaws.com/feedbackhub/app"
rds_endpoint       = "feedbackhub-prod.XXXX.us-east-1.rds.amazonaws.com"
redis_endpoint     = "feedbackhub-prod.XXXX.cache.amazonaws.com"
sqs_queue_url      = "https://sqs.us-east-1.amazonaws.com/123456789/feedbackhub-jobs"
```

### Step 4 — Store Secrets

```bash
# App secrets
aws secretsmanager create-secret \
  --name "feedbackhub/prod/app" \
  --secret-string '{
    "APP_KEY": "base64:...",
    "APP_ENV": "production",
    "APP_DEBUG": "false",
    "APP_URL": "https://api.yourdomain.com"
  }'

# Database
aws secretsmanager create-secret \
  --name "feedbackhub/prod/db" \
  --secret-string '{
    "DB_HOST": "<rds_endpoint>",
    "DB_DATABASE": "feedbackhub",
    "DB_USERNAME": "feedbackhub",
    "DB_PASSWORD": "<your_password>"
  }'

# Redis
aws secretsmanager create-secret \
  --name "feedbackhub/prod/redis" \
  --secret-string '{
    "REDIS_HOST": "<redis_endpoint>",
    "REDIS_PORT": "6379"
  }'

# SQS
aws secretsmanager create-secret \
  --name "feedbackhub/prod/sqs" \
  --secret-string '{
    "SQS_PREFIX": "https://sqs.us-east-1.amazonaws.com/123456789",
    "SQS_QUEUE": "feedbackhub-jobs",
    "AWS_DEFAULT_REGION": "us-east-1"
  }'

# AI keys
aws secretsmanager create-secret \
  --name "feedbackhub/prod/ai" \
  --secret-string '{
    "OPENAI_API_KEY": "sk-proj-...",
    "PINECONE_API_KEY": "...",
    "PINECONE_HOST": "feedback-embeddings-XXXX.svc.pinecone.io",
    "PINECONE_ENVIRONMENT": "us-east-1-aws",
    "PINECONE_INDEX": "feedback-embeddings"
  }'
```

### Step 5 — Run Database Migrations

The ECS tasks are now running but the database is empty. Run migrations as a one-off ECS task:

```bash
aws ecs run-task \
  --cluster feedbackhub-prod \
  --task-definition feedbackhub-app \
  --launch-type FARGATE \
  --network-configuration "awsvpcConfiguration={subnets=[subnet-PRIVATE-A],securityGroups=[sg-ecs-web],assignPublicIp=DISABLED}" \
  --overrides '{"containerOverrides":[{"name":"app","command":["php","artisan","migrate","--force"]}]}'

# Optionally seed initial data
aws ecs run-task \
  --cluster feedbackhub-prod \
  --task-definition feedbackhub-app \
  --launch-type FARGATE \
  --network-configuration "awsvpcConfiguration={subnets=[subnet-PRIVATE-A],securityGroups=[sg-ecs-web],assignPublicIp=DISABLED}" \
  --overrides '{"containerOverrides":[{"name":"app","command":["php","artisan","db:seed","--force"]}]}'
```

### Step 6 — Configure GitHub Actions

Set these repository secrets in GitHub (Settings → Secrets → Actions):

| Secret | Value |
|--------|-------|
| `AWS_ACCOUNT_ID` | Your 12-digit AWS account ID |
| `AWS_REGION` | `us-east-1` |
| `ECR_REPOSITORY` | `feedbackhub/app` |
| `ECS_CLUSTER` | `feedbackhub-prod` |
| `ECS_SERVICE_WEB` | `feedbackhub-web` |
| `ECS_SERVICE_WORKER` | `feedbackhub-worker` |
| `S3_FRONTEND_BUCKET` | `feedbackhub-frontend-prod` |
| `CLOUDFRONT_DISTRIBUTION_ID` | From Terraform output |

The `AWS_ROLE_TO_ASSUME` ARN is also output by Terraform.

### Step 7 — First Deployment

```bash
git push origin master
```

GitHub Actions will:
1. Run all 69 PHPUnit tests
2. Run `npm run typecheck` + `npm run build`
3. Build the Docker image, push to ECR
4. Register a new ECS task definition revision with the new image
5. Force new deployments on web and worker services (rolling update)
6. Wait for both services to stabilise
7. Sync the React build to S3
8. Invalidate CloudFront cache

First deployment takes ~8–10 minutes. Subsequent deployments: ~4–5 minutes.

### Step 8 — Verify

```bash
# Check ECS services are ACTIVE with desired task count
aws ecs describe-services \
  --cluster feedbackhub-prod \
  --services feedbackhub-web feedbackhub-worker \
  --query 'services[*].{name:serviceName,running:runningCount,desired:desiredCount}'

# Hit the API
curl https://api.yourdomain.com/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{"tenant_slug":"compass-group","email":"alice@compass.com","password":"password"}'

# Check queue worker is processing jobs
aws logs tail /ecs/feedbackhub-worker --since 5m

# Verify Lambda scheduler fires
aws logs tail /lambda/feedbackhub-scheduler-trigger --since 5m
```

---

## CI/CD Pipeline Explained

```
push to master
      │
      ├── Job: test-backend
      │     ├── Spin up PHP 8.1 + MySQL service container
      │     ├── composer install
      │     └── php artisan test  (69 tests must pass)
      │
      ├── Job: build-frontend (parallel with test-backend)
      │     ├── npm ci
      │     ├── npm run typecheck  (zero TS errors required)
      │     ├── npm run build
      │     └── Upload frontend/dist/ as artifact
      │
      └── Job: deploy  (needs: test-backend AND build-frontend)
            ├── Configure AWS credentials via OIDC (no stored keys)
            ├── Login to ECR
            ├── Build Docker image → tag :latest + :<git-sha>
            ├── Push both tags to ECR
            ├── Update task definition JSON with new image URI
            ├── Register new task definition revision
            ├── Force new deployment: feedbackhub-web
            ├── Force new deployment: feedbackhub-worker
            ├── Wait for services to stabilise (aws ecs wait)
            ├── Download frontend artifact
            ├── aws s3 sync frontend/dist/ → S3 (--delete removes stale files)
            └── CloudFront invalidation  (/* path)
```

**Zero-downtime rolling deployments:** ECS keeps old tasks running until new tasks pass health checks. The ALB routes traffic to new tasks as they become healthy.

---

## Auto-Scaling

### API (feedbackhub-web)
- **Metric:** ALB `RequestCountPerTarget`
- **Scale out:** > 300 requests per target for 2 minutes → add 1 task
- **Scale in:** < 100 requests per target for 5 minutes → remove 1 task
- **Min:** 1 task, **Max:** 10 tasks

### Queue Worker (feedbackhub-worker)
- **Metric:** SQS `ApproximateNumberOfMessagesVisible`
- **Scale out:** > 100 messages in queue → add 1 worker task
- **Scale in:** < 10 messages for 5 minutes → remove 1 task
- **Min:** 1 task, **Max:** 5 tasks

---

## Monitoring

### CloudWatch Alarms (all notify via SNS → email)

| Alarm | Condition | Why it matters |
|-------|-----------|----------------|
| ECS Web CPU High | > 70% for 5 min | API is under load |
| RDS CPU High | > 80% for 5 min | Database bottleneck |
| RDS Storage Low | < 5 GB free | Disk will fill |
| DLQ Messages | > 0 at any time | A job failed 3 times and was abandoned |
| Lambda Errors | > 2 in 5 min | Scheduled tasks stopped running |
| Job Failures | > 10 in 1 hour | Systemic job failure (metric filter on logs) |

### Log Insights Queries

```
# Find all failed jobs in the last hour
fields @timestamp, event, job, error
| filter event = "job_failed"
| sort @timestamp desc
| limit 50

# Find slow queries (>100ms)
fields @timestamp, duration_ms, query
| filter event = "slow_query"
| sort duration_ms desc
| limit 20

# AI cost in the last 24 hours
fields @timestamp, cost_usd, tokens_used, tenant_id
| filter event = "ai_usage_tracked"
| stats sum(cost_usd) as total_cost by tenant_id
```

### Viewing Logs

```bash
# API logs (live tail)
aws logs tail /ecs/feedbackhub-web --follow

# Queue worker logs
aws logs tail /ecs/feedbackhub-worker --follow

# Lambda scheduler
aws logs tail /lambda/feedbackhub-scheduler-trigger --follow

# Failed jobs (all time)
aws logs filter-log-events \
  --log-group-name /ecs/feedbackhub-worker \
  --filter-pattern '{ $.event = "job_failed" }'
```

---

## Rollback

### Application rollback (previous Docker image)

```bash
# List recent ECR image tags
aws ecr list-images \
  --repository-name feedbackhub/app \
  --query 'imageIds[?imageTag!=`latest`]' \
  --output table

# Roll back to a specific git SHA tag
PREVIOUS_SHA=abc1234

NEW_TASK_DEF=$(aws ecs describe-task-definition \
  --task-definition feedbackhub-app \
  --query 'taskDefinition' \
  | jq --arg img "123456789.dkr.ecr.us-east-1.amazonaws.com/feedbackhub/app:$PREVIOUS_SHA" \
       '.containerDefinitions[0].image = $img | del(.taskDefinitionArn,.revision,.status,.registeredAt,.registeredBy,.requiresAttributes,.compatibilities)')

aws ecs register-task-definition --cli-input-json "$NEW_TASK_DEF"

aws ecs update-service \
  --cluster feedbackhub-prod \
  --service feedbackhub-web \
  --task-definition feedbackhub-app
```

### Database rollback

```bash
# Laravel migration rollback (run as a one-off ECS task)
aws ecs run-task \
  --cluster feedbackhub-prod \
  --task-definition feedbackhub-app \
  --launch-type FARGATE \
  --network-configuration "awsvpcConfiguration={subnets=[subnet-PRIVATE-A],securityGroups=[sg-ecs-web],assignPublicIp=DISABLED}" \
  --overrides '{"containerOverrides":[{"name":"app","command":["php","artisan","migrate:rollback"]}]}'
```

---

## Cost Estimate (us-east-1, lightly loaded)

| Service | Configuration | Monthly Cost (est.) |
|---------|--------------|---------------------|
| ECS Fargate — web | 1 task × 0.5 vCPU, 1 GB, 730 hrs | ~$15 |
| ECS Fargate — worker | 1 task × 0.25 vCPU, 0.5 GB, 730 hrs | ~$7 |
| RDS MySQL | db.t3.medium Multi-AZ | ~$70 |
| ElastiCache Redis | cache.t3.micro | ~$12 |
| SQS | < 1M requests/month | < $1 |
| S3 | < 1 GB storage | < $1 |
| CloudFront | < 10 GB transfer | < $1 |
| ALB | 1 ALB, light traffic | ~$20 |
| NAT Gateway | 1 gateway, < 1 GB data | ~$35 |
| Lambda + EventBridge | < 50K invocations/month | < $1 |
| CloudWatch | Logs + Container Insights | ~$5 |
| Secrets Manager | 5 secrets | ~$3 |
| **Total** | | **~$170/month** |

> To reduce cost during learning: use `db.t3.micro` RDS (no Multi-AZ) and `cache.t3.micro` → drops to ~$80/month. The NAT Gateway ($35) can also be replaced with a NAT instance on a `t3.micro` spot instance (~$5/month).

---

## Security Checklist

- [ ] `APP_DEBUG=false` in production Secrets Manager secret
- [ ] All ECS tasks in private subnets (no public IP assignment)
- [ ] RDS has no public accessibility
- [ ] Secrets Manager secrets are encrypted with CMK (or AWS-managed key)
- [ ] S3 frontend bucket has all public access blocked (CloudFront OAC only)
- [ ] ALB has HTTP→HTTPS redirect rule on port 80
- [ ] IAM roles follow least-privilege (no `*` resource policies)
- [ ] GitHub Actions uses OIDC — no long-lived access keys in repository
- [ ] ECR image scanning enabled on push
- [ ] CloudWatch alarms configured for DLQ, job failures, and CPU spikes
- [ ] CloudTrail enabled for audit logging (recommended addition)
