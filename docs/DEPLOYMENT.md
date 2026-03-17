# AWS Deployment Guide

This guide deploys FeedbackHub to AWS using Docker and managed services while staying within (or very close to) the AWS Free Tier. The goal is to learn production-grade cloud architecture without a large monthly bill.

Estimated cost: $0–5/month (vs ~$170/month for the full ECS Fargate + ALB + Multi-AZ approach).

---

## Free Tier vs Full Production Comparison

| Concern | This Guide (Free Tier) | Full Production |
|---------|------------------------|-----------------|
| Compute | EC2 t2.micro (750 hrs/month free) | ECS Fargate |
| Reverse proxy | nginx on EC2 + Let's Encrypt | ALB (~$18/month) |
| Database | RDS db.t3.micro single-AZ (750 hrs/month free) | RDS Multi-AZ (~$70/month) |
| Cache | Redis in Docker on EC2 | ElastiCache (~$13/month) |
| Job queue | SQS (1M requests/month free) | SQS (same) |
| Secrets | SSM Parameter Store (free) | Secrets Manager (~$3/month) |
| Scheduler | Lambda → SSM Run Command | Lambda → ECS RunTask |
| Frontend | S3 + CloudFront (free tier) | S3 + CloudFront (same) |
| CI/CD | GitHub Actions + OIDC + SSM | GitHub Actions + OIDC + ECS |
| DNS | EC2 public IP or free DNS | Route 53 (~$0.50/month) |

What stays the same and teaches the same skills: SQS, S3, CloudFront, Lambda, EventBridge, ECR, IAM, VPC, CloudWatch, GitHub Actions OIDC.

---

## AWS Services Used

| Service | Purpose | Free Tier |
|---------|---------|-----------|
| **ECR** | Docker image registry | 500 MB/month free |
| **EC2 t2.micro** | Runs the Laravel API, queue worker, Redis (all via Docker) | 750 hrs/month free (12 months) |
| **RDS MySQL 8.0 db.t3.micro** | Managed database with automated backups | 750 hrs/month + 20 GB free (12 months) |
| **SQS** | Job queue — durable, managed, Dead Letter Queue support | 1M requests/month free |
| **S3** | React frontend static hosting | 5 GB + 20K GET/month free |
| **CloudFront** | CDN for the React frontend | 1 TB transfer + 10M requests/month free |
| **Lambda** | Triggers `php artisan schedule:run` every minute | 1M invocations/month free |
| **EventBridge Scheduler** | Fires Lambda every minute | Always free |
| **SSM Parameter Store** | Stores all `.env` secrets — fetched at runtime | Standard parameters are free |
| **SSM Run Command** | CI/CD deployments and scheduler — no SSH keys needed | Always free |
| **ACM** | Free SSL certificate | Always free |
| **IAM** | Least-privilege roles for EC2, Lambda, GitHub Actions | Always free |
| **VPC** | Network isolation — public subnet for EC2, private for RDS | Always free |
| **CloudWatch** | Logs from EC2 via CloudWatch agent | 5 GB ingestion/month free |
| **GitHub Actions + OIDC** | CI/CD — no long-lived AWS keys stored | Free for public repos |

---

## Architecture

```
Internet
  └── EC2 t2.micro (public subnet)
        ├── nginx (port 80/443)
        │     ├── HTTP → HTTPS redirect
        │     └── proxy_pass → PHP-FPM:9000
        ├── PHP-FPM container  (feedbackhub-web)
        ├── Queue worker container  (feedbackhub-worker: queue:work sqs)
        └── Redis container  (cache only — no persistence needed)

Private Subnet
  └── RDS MySQL 8.0 db.t3.micro (single-AZ, automated backups)

Serverless
  └── EventBridge Scheduler (every 1 min)
        └── Lambda: feedbackhub-scheduler-trigger
              └── SSM Run Command → EC2: docker exec feedbackhub-web php artisan schedule:run

Static
  └── CloudFront distribution
        └── S3 bucket (React frontend build)

CI/CD
  └── GitHub Actions (push to master)
        ├── Run PHPUnit tests
        ├── TypeScript typecheck + React build
        ├── Build Docker image → push to ECR
        ├── SSM Run Command → EC2: docker pull + restart containers
        ├── Sync frontend/dist/ → S3
        └── CloudFront invalidation

Secrets
  └── SSM Parameter Store → fetched by EC2 at container startup

Observability
  └── CloudWatch Logs (CloudWatch agent on EC2 ships Docker logs)
```

---

## Key Architecture Decisions

### EC2 + Docker instead of ECS Fargate
ECS Fargate is not in the free tier (~$15–30/month for even one task). A single EC2 t2.micro running Docker Compose handles the API, queue worker, and Redis container comfortably under low traffic. The Docker image, Dockerfile, and CI/CD pipeline are identical — migrating to Fargate later requires only ECS task definition changes.

### SQS for queues (not Redis)
Redis on the EC2 instance has no persistence. If the instance restarts, queued jobs are lost. SQS is 11-nines durable, has Dead Letter Queue support, and costs nothing under 1M requests/month. Redis is kept as a cache-only service running in Docker on EC2.

### SSM Parameter Store instead of Secrets Manager
Secrets Manager charges $0.40 per secret per month (~$3/month for this app). SSM Parameter Store standard parameters are free and work identically: secrets are stored encrypted, fetched at container startup, and never baked into the Docker image.

### SSM Run Command for deployments
Instead of SSH (which requires storing a private key in GitHub Secrets), GitHub Actions assumes an IAM role via OIDC and uses SSM Run Command to execute deployment commands on the EC2 instance. No long-lived credentials stored anywhere.

### Lambda → SSM Run Command for scheduler
EventBridge fires Lambda every minute. Lambda calls `ssm:SendCommand` to run `docker exec feedbackhub-web php artisan schedule:run` on the EC2 instance and logs the result. Same pattern as the Fargate approach, different target.

### nginx + Let's Encrypt for HTTPS
ALB costs ~$18/month. nginx runs in Docker on EC2 with Certbot obtaining a free Let's Encrypt certificate. ACM is used for the CloudFront distribution (React frontend), which is always free.

---

## File Structure

```
feedback-hub/
├── Dockerfile                                    # ✅ Multi-stage PHP 8.1-fpm build
├── .dockerignore                                 # ✅ Excludes node_modules, vendor, .env, tests
├── docker/
│   ├── entrypoint.sh                             # ✅ Runs artisan optimize on production startup
│   ├── nginx.conf                                # ✅ Reverse proxy to PHP-FPM (HTTPS block commented)
│   ├── supervisord.conf                          # ✅ Runs PHP-FPM in web container
│   └── php.ini                                   # ✅ OPcache settings for production
├── docker-compose.yml                            # ✅ Local development (redis queue, mysql container)
├── docker-compose.prod.yml                       # ✅ Production: web + worker + redis (RDS external)
├── .github/
│   └── workflows/
│       └── deploy.yml                            # ⏳ CI/CD pipeline
└── infrastructure/
    ├── terraform/
    │   ├── main.tf                               # ⏳ Provider, backend (S3 state)
    │   ├── variables.tf                          # ⏳
    │   ├── outputs.tf                            # ⏳
    │   ├── vpc.tf                                # ⏳ VPC, subnets, IGW, route tables
    │   ├── security_groups.tf                    # ⏳ sg-ec2 (80,443,22 inbound), sg-rds
    │   ├── ecr.tf                                # ⏳ ECR repository + lifecycle policy
    │   ├── ec2.tf                                # ⏳ t2.micro instance, IAM instance profile
    │   ├── rds.tf                                # ⏳ MySQL 8.0 db.t3.micro, single-AZ
    │   ├── sqs.tf                                # ⏳ Main queue + DLQ
    │   ├── s3.tf                                 # ⏳ Frontend bucket
    │   ├── cloudfront.tf                         # ⏳ Distribution with OAC, SPA routing
    │   ├── acm.tf                                # ⏳ SSL cert for CloudFront
    │   ├── ssm.tf                                # ⏳ Parameter Store secret placeholders
    │   ├── iam.tf                                # ⏳ EC2 instance role, Lambda role, GitHub OIDC role
    │   ├── lambda.tf                             # ⏳ Scheduler trigger function + EventBridge rule
    │   └── cloudwatch.tf                         # ⏳ Log groups, metric filters, alarms
    └── lambda/
        └── scheduler_trigger/
            └── handler.py                        # ⏳ boto3 SSM SendCommand (~25 lines)
```

---

## Prerequisites

- AWS account (12-month free tier active or costs will be minimal)
- AWS CLI v2 installed and configured (`aws configure`)
- Terraform 1.6+ installed
- Docker installed and running
- GitHub repository for the project
- A domain name (optional — you can use the EC2 public IP directly for learning)

---

## Step-by-Step Deployment

### Step 1 — Bootstrap Terraform State

Create an S3 bucket and DynamoDB table for Terraform state (one-time setup):

```bash
aws s3 mb s3://feedbackhub-terraform-state --region us-east-1
aws s3api put-bucket-versioning \
  --bucket feedbackhub-terraform-state \
  --versioning-configuration Status=Enabled

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
aws_region   = "us-east-1"
app_name     = "feedbackhub"
environment  = "prod"
db_password  = "STRONG_PASSWORD_HERE"
domain_name  = ""   # Leave empty to use EC2 public IP
```

### Step 3 — Provision Infrastructure

```bash
cd infrastructure/terraform
terraform init
terraform plan    # Review — ~30 resources
terraform apply   # Takes 10–15 min (RDS is the slow part)
```

After apply, note the outputs:

```
ec2_public_ip      = "54.123.45.67"
ecr_repository_url = "123456789.dkr.ecr.us-east-1.amazonaws.com/feedbackhub/app"
rds_endpoint       = "feedbackhub-prod.XXXX.us-east-1.rds.amazonaws.com"
sqs_queue_url      = "https://sqs.us-east-1.amazonaws.com/123456789/feedbackhub-jobs"
cloudfront_url     = "dXXXXXXXXXXXX.cloudfront.net"
s3_frontend_bucket = "feedbackhub-frontend-prod"
```

### Step 4 — Store Secrets in SSM Parameter Store

```bash
# Application
aws ssm put-parameter --name "/feedbackhub/prod/APP_KEY"   --value "base64:..." --type SecureString
aws ssm put-parameter --name "/feedbackhub/prod/APP_ENV"   --value "production" --type String
aws ssm put-parameter --name "/feedbackhub/prod/APP_DEBUG" --value "false"      --type String
aws ssm put-parameter --name "/feedbackhub/prod/APP_URL"   --value "https://api.yourdomain.com" --type String

# Database
aws ssm put-parameter --name "/feedbackhub/prod/DB_HOST"     --value "<rds_endpoint>" --type SecureString
aws ssm put-parameter --name "/feedbackhub/prod/DB_DATABASE" --value "feedbackhub"    --type String
aws ssm put-parameter --name "/feedbackhub/prod/DB_USERNAME" --value "feedbackhub"    --type String
aws ssm put-parameter --name "/feedbackhub/prod/DB_PASSWORD" --value "<your_password>" --type SecureString

# Redis (local Docker container on same EC2)
aws ssm put-parameter --name "/feedbackhub/prod/REDIS_HOST" --value "redis" --type String
aws ssm put-parameter --name "/feedbackhub/prod/REDIS_PORT" --value "6379"  --type String

# SQS
aws ssm put-parameter --name "/feedbackhub/prod/SQS_PREFIX" --value "https://sqs.us-east-1.amazonaws.com/123456789" --type String
aws ssm put-parameter --name "/feedbackhub/prod/SQS_QUEUE"  --value "feedbackhub-jobs" --type String
aws ssm put-parameter --name "/feedbackhub/prod/AWS_DEFAULT_REGION" --value "us-east-1" --type String

# AI keys
aws ssm put-parameter --name "/feedbackhub/prod/OPENAI_API_KEY"        --value "sk-proj-..." --type SecureString
aws ssm put-parameter --name "/feedbackhub/prod/PINECONE_API_KEY"      --value "..."         --type SecureString
aws ssm put-parameter --name "/feedbackhub/prod/PINECONE_HOST"         --value "feedback-embeddings-XXXX.svc.pinecone.io" --type SecureString
aws ssm put-parameter --name "/feedbackhub/prod/PINECONE_ENVIRONMENT"  --value "us-east-1-aws" --type String
aws ssm put-parameter --name "/feedbackhub/prod/PINECONE_INDEX"        --value "feedback-embeddings" --type String
```

### Step 5 — Initial EC2 Bootstrap

SSH in (or use SSM Session Manager) and set up the instance:

```bash
# Via SSM Session Manager (no SSH key required)
aws ssm start-session --target <instance-id>

# On the EC2 instance:
# Install Docker, Docker Compose, AWS CLI, CloudWatch agent
# (EC2 user-data script handles this automatically via Terraform)
```

### Step 6 — Run Database Migrations

```bash
# Via SSM Run Command
aws ssm send-command \
  --instance-ids <instance-id> \
  --document-name "AWS-RunShellScript" \
  --parameters 'commands=["docker exec feedbackhub-web php artisan migrate --force"]' \
  --output text

# Optionally seed initial data
aws ssm send-command \
  --instance-ids <instance-id> \
  --document-name "AWS-RunShellScript" \
  --parameters 'commands=["docker exec feedbackhub-web php artisan db:seed --force"]' \
  --output text
```

### Step 7 — Configure GitHub Actions

Set these repository secrets (Settings → Secrets → Actions):

| Secret | Value |
|--------|-------|
| `AWS_ACCOUNT_ID` | Your 12-digit AWS account ID |
| `AWS_REGION` | `us-east-1` |
| `ECR_REPOSITORY` | `feedbackhub/app` |
| `EC2_INSTANCE_ID` | From Terraform output |
| `S3_FRONTEND_BUCKET` | `feedbackhub-frontend-prod` |
| `CLOUDFRONT_DISTRIBUTION_ID` | From Terraform output |

The `AWS_ROLE_TO_ASSUME` ARN is output by Terraform (OIDC — no stored keys).

### Step 8 — First Deployment

```bash
git push origin master
```

GitHub Actions will:
1. Run all 69 PHPUnit tests
2. Run `npm run typecheck` + `npm run build`
3. Build the Docker image and push to ECR (tagged `:latest` + `:<git-sha>`)
4. Use SSM Run Command to pull the new image and restart containers on EC2
5. Sync the React build to S3
6. Invalidate the CloudFront cache

### Step 9 — Verify

```bash
# Check containers are running on EC2
aws ssm send-command \
  --instance-ids <instance-id> \
  --document-name "AWS-RunShellScript" \
  --parameters 'commands=["docker ps"]'

# Hit the API (replace with your EC2 public IP or domain)
curl https://api.yourdomain.com/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{"tenant_slug":"compass-group","email":"alice@compass.com","password":"password"}'

# Tail logs via CloudWatch
aws logs tail /ec2/feedbackhub-web    --follow
aws logs tail /ec2/feedbackhub-worker --follow

# Verify Lambda scheduler fires
aws logs tail /lambda/feedbackhub-scheduler-trigger --follow
```

---

## CI/CD Pipeline

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
      │     ├── npm run typecheck  (zero TS errors)
      │     ├── npm run build
      │     └── Upload frontend/dist/ as artifact
      │
      └── Job: deploy  (needs: test-backend AND build-frontend)
            ├── Configure AWS credentials via OIDC (no stored keys)
            ├── Login to ECR
            ├── Build Docker image → tag :latest + :<git-sha>
            ├── Push both tags to ECR
            ├── SSM Run Command → EC2:
            │     docker pull <ecr-image>
            │     docker compose -f docker-compose.prod.yml up -d
            ├── Download frontend artifact
            ├── aws s3 sync frontend/dist/ → S3 (--delete)
            └── CloudFront invalidation (/* path)
```

**Zero-downtime deployments:** `docker compose up -d` starts new containers before stopping old ones. Brief overlap during container swap — acceptable for a learning project. For strict zero-downtime, health-check the new container before stopping the old one.

---

## Monitoring

### CloudWatch Alarms (all notify via SNS → email)

| Alarm | Condition | Why it matters |
|-------|-----------|----------------|
| EC2 CPU High | > 80% for 5 min | Instance under load |
| RDS CPU High | > 80% for 5 min | Database bottleneck |
| RDS Storage Low | < 5 GB free | Disk will fill (20 GB free tier limit) |
| DLQ Messages | > 0 at any time | A job failed 3 times and was abandoned |
| Lambda Errors | > 2 in 5 min | Scheduled tasks stopped running |

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
aws logs tail /ec2/feedbackhub-web --follow

# Queue worker logs
aws logs tail /ec2/feedbackhub-worker --follow

# Lambda scheduler
aws logs tail /lambda/feedbackhub-scheduler-trigger --follow
```

---

## Rollback

### Application rollback (previous Docker image)

```bash
PREVIOUS_SHA=abc1234

aws ssm send-command \
  --instance-ids <instance-id> \
  --document-name "AWS-RunShellScript" \
  --parameters "commands=[
    \"docker pull 123456789.dkr.ecr.us-east-1.amazonaws.com/feedbackhub/app:$PREVIOUS_SHA\",
    \"IMAGE_TAG=$PREVIOUS_SHA docker compose -f docker-compose.prod.yml up -d\"
  ]"
```

### Database rollback

```bash
aws ssm send-command \
  --instance-ids <instance-id> \
  --document-name "AWS-RunShellScript" \
  --parameters 'commands=["docker exec feedbackhub-web php artisan migrate:rollback"]'
```

---

## Cost Estimate

| Service | Configuration | Monthly Cost |
|---------|--------------|--------------|
| EC2 t2.micro | 730 hrs/month | **Free** (free tier) |
| RDS db.t3.micro | Single-AZ, 20 GB | **Free** (free tier) |
| SQS | < 1M requests | **Free** |
| S3 | < 5 GB | **Free** |
| CloudFront | < 1 TB transfer | **Free** |
| Lambda + EventBridge | < 1M invocations | **Free** |
| ECR | < 500 MB | **Free** |
| CloudWatch | < 5 GB logs | **Free** |
| SSM Parameter Store | Standard params | **Free** |
| **Total (within free tier)** | | **~$0/month** |

> After the 12-month free tier expires: EC2 t2.micro ~$8/month + RDS db.t3.micro ~$15/month = ~$23/month total. Still very cheap.

---

## Migrating to Full Production (When Ready)

The learning value here is in understanding the architecture. When you're ready to move to ECS Fargate + ALB:

1. **Docker image is identical** — no changes needed
2. **ECS task definition** — the same `CMD` override pattern (web/worker/scheduler)
3. **ALB** replaces nginx on EC2
4. **ECS Fargate** replaces the Docker Compose deployment
5. **ElastiCache** replaces Redis-in-Docker
6. **Secrets Manager** replaces SSM Parameter Store
7. **RDS Multi-AZ** replaces single-AZ

The CI/CD pipeline changes from `SSM Run Command` to `aws ecs update-service --force-new-deployment`. Everything else stays the same.

---

## Security Checklist

- [ ] `APP_DEBUG=false` in SSM Parameter Store
- [ ] EC2 security group allows 80/443 inbound, 22 only from your IP (or blocked entirely — use SSM Session Manager)
- [ ] RDS security group allows inbound only from EC2 security group (not public)
- [ ] SSM parameters use `SecureString` for all sensitive values
- [ ] S3 frontend bucket has all public access blocked (CloudFront OAC only)
- [ ] nginx enforces HTTP → HTTPS redirect
- [ ] IAM roles follow least-privilege (no `*` resource policies)
- [ ] GitHub Actions uses OIDC — no long-lived access keys stored
- [ ] ECR image scanning enabled on push
- [ ] CloudWatch alarms configured for DLQ and CPU spikes
