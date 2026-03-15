import client from './client'
import type { Feedback, FeedbackStatus, PaginatedResponse } from '../types'

export async function getFeedback(page = 1, status?: FeedbackStatus): Promise<PaginatedResponse<Feedback>> {
  const { data } = await client.get<PaginatedResponse<Feedback>>('/feedback', {
    params: { page, ...(status ? { status } : {}) },
  })
  return data
}

export async function getProjectFeedback(projectId: number, page = 1, status?: FeedbackStatus): Promise<PaginatedResponse<Feedback>> {
  const { data } = await client.get<PaginatedResponse<Feedback>>(`/projects/${projectId}/feedback`, {
    params: { page, ...(status ? { status } : {}) },
  })
  return data
}

export async function getFeedbackItem(id: number): Promise<{ data: Feedback }> {
  const { data } = await client.get<{ data: Feedback }>(`/feedback/${id}`)
  return data
}

export async function createFeedback(payload: {
  title: string
  description: string
  status: FeedbackStatus
  project_id: number
}): Promise<{ data: Feedback }> {
  const { data } = await client.post<{ data: Feedback }>('/feedback', payload)
  return data
}

export async function updateFeedbackStatus(id: number, status: FeedbackStatus): Promise<{ data: Feedback }> {
  const { data } = await client.patch<{ data: Feedback }>(`/feedback/${id}/status`, { status })
  return data
}

export async function deleteFeedback(id: number): Promise<void> {
  await client.delete(`/feedback/${id}`)
}
