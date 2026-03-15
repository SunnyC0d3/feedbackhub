import client from './client'
import type { Metrics } from '../types'

export async function getMetrics(): Promise<{ data: Metrics }> {
  const { data } = await client.get<{ data: Metrics }>('/metrics')
  return data
}
