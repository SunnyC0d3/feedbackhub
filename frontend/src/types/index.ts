export type FeedbackStatus =
  | 'draft'
  | 'open'
  | 'seen'
  | 'pending'
  | 'review_required'
  | 'in_progress'
  | 'resolved'
  | 'closed'

export type UserRole = 'admin' | 'manager' | 'member' | 'support'

export interface User {
  id: number
  name: string
  email: string
  tenant_id: number
  role: UserRole
  created_at: string
}

export interface Division {
  id: number
  name: string
  slug: string
  tenant_id: number
  user_count?: number
  projects?: Project[]
  created_at: string
  updated_at: string
}

export interface Project {
  id: number
  name: string
  slug: string
  description: string | null
  division_id: number
  tenant_id: number
  feedback_count?: number
  division?: Division
  created_at: string
  updated_at: string
}

export interface Feedback {
  id: number
  title: string
  description: string | null
  status: FeedbackStatus
  project_id: number
  user_id: number
  tenant_id: number
  project?: Project
  author?: User
  created_at: string
  updated_at: string
}

export interface Metrics {
  tenant_id: number
  timestamp: string
  total_feedback: number
  total_projects: number
  total_users: number
  feedback_by_status: Record<string, number>
  feedback_today: number
  feedback_this_week: number
  failed_jobs: number
}

export interface AnalysisUsage {
  tokens_used: number
  cost_usd: number
}

export interface AnalysisResult {
  query: string
  feedback_found: number
  summary: string
  feedback: Feedback[]
  usage: AnalysisUsage
}

export interface PaginatedResponse<T> {
  data: T[]
  links: {
    first: string
    last: string
    prev: string | null
    next: string | null
  }
  meta: {
    current_page: number
    last_page: number
    per_page: number
    total: number
  }
}

export interface LoginResponse {
  token: string
  user: User
}

