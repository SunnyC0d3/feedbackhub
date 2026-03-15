import client from './client'
import type { AnalysisResult } from '../types'

export async function queryAnalysis(query: string): Promise<{ data: AnalysisResult }> {
  const { data } = await client.post<{ data: AnalysisResult }>('/analysis/query', { query })
  return data
}
