import client from './client'
import type { Project, PaginatedResponse } from '../types'

export async function getProjects(page = 1): Promise<PaginatedResponse<Project>> {
  const { data } = await client.get<PaginatedResponse<Project>>('/projects', { params: { page } })
  return data
}

export async function getProject(id: number): Promise<{ data: Project }> {
  const { data } = await client.get<{ data: Project }>(`/projects/${id}`)
  return data
}

export async function summarizeProject(id: number): Promise<{ project_id: number; feedback_count: number; summary: string; usage: { tokens_used: number; cost_usd: number } }> {
  const { data } = await client.post<{ project_id: number; feedback_count: number; summary: string; usage: { tokens_used: number; cost_usd: number } }>(`/projects/${id}/summarize`)
  return data
}
