import client from './client'
import type { Division, PaginatedResponse } from '../types'

export async function getDivisions(): Promise<PaginatedResponse<Division>> {
  const { data } = await client.get<PaginatedResponse<Division>>('/divisions')
  return data
}

export async function getDivision(id: number): Promise<{ data: Division }> {
  const { data } = await client.get<{ data: Division }>(`/divisions/${id}`)
  return data
}
