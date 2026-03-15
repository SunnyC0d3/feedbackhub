import { useState } from 'react'
import { useQuery, useMutation } from '@tanstack/react-query'
import { useParams, Link, useSearchParams } from 'react-router-dom'
import { getProject, summarizeProject } from '../api/projects'
import { getProjectFeedback } from '../api/feedback'
import StatusBadge from '../components/StatusBadge'
import Pagination from '../components/Pagination'
import type { FeedbackStatus } from '../types'

const ALL_STATUSES: FeedbackStatus[] = [
  'draft', 'open', 'seen', 'pending', 'review_required', 'in_progress', 'resolved', 'closed',
]

export default function ProjectDetailPage() {
  const { id } = useParams<{ id: string }>()
  const projectId = Number(id)
  const [searchParams, setSearchParams] = useSearchParams()
  const page = Number(searchParams.get('page') ?? '1')
  const statusFilter = (searchParams.get('status') as FeedbackStatus | null) ?? undefined

  const [summary, setSummary] = useState<string | null>(null)
  const [summaryCost, setSummaryCost] = useState<number | null>(null)

  const { data: projectData, isLoading: projectLoading } = useQuery({
    queryKey: ['projects', projectId],
    queryFn: () => getProject(projectId),
    enabled: !isNaN(projectId),
  })

  const { data: feedbackData, isLoading: feedbackLoading } = useQuery({
    queryKey: ['projects', projectId, 'feedback', { page, status: statusFilter }],
    queryFn: () => getProjectFeedback(projectId, page, statusFilter),
    enabled: !isNaN(projectId),
  })

  const summarize = useMutation({
    mutationFn: () => summarizeProject(projectId),
    onSuccess: (res) => {
      setSummary(res.data.summary)
      setSummaryCost(res.data.cost_usd)
    },
  })

  function handleStatusChange(status: string) {
    const params: Record<string, string> = { page: '1' }
    if (status) params.status = status
    setSearchParams(params)
  }

  function handlePageChange(newPage: number) {
    const params: Record<string, string> = { page: String(newPage) }
    if (statusFilter) params.status = statusFilter
    setSearchParams(params)
  }

  if (projectLoading) return <p className="text-gray-500">Loading project…</p>

  const project = projectData?.data
  if (!project) return <p className="text-red-600">Project not found.</p>

  const feedbackItems = feedbackData?.data ?? []
  const meta = feedbackData?.meta

  return (
    <div className="space-y-6">
      <div>
        <Link to="/projects" className="text-sm text-indigo-600 hover:underline">
          ← Projects
        </Link>
        <div className="flex items-start justify-between mt-1">
          <div>
            <h1 className="text-2xl font-bold text-gray-800">{project.name}</h1>
            {project.description && (
              <p className="text-sm text-gray-500 mt-1">{project.description}</p>
            )}
          </div>
          <button
            onClick={() => summarize.mutate()}
            disabled={summarize.isPending}
            className="ml-4 bg-indigo-600 text-white px-4 py-2 rounded text-sm font-medium hover:bg-indigo-700 disabled:opacity-60 shrink-0"
          >
            {summarize.isPending ? 'Summarising…' : 'AI Summary'}
          </button>
        </div>
      </div>

      {summary && (
        <div className="bg-indigo-50 border border-indigo-200 rounded-lg p-5">
          <div className="flex items-center justify-between mb-2">
            <h2 className="font-semibold text-indigo-800">AI Summary</h2>
            {summaryCost !== null && (
              <span className="text-xs text-indigo-500">${summaryCost.toFixed(6)} USD</span>
            )}
          </div>
          <p className="text-sm text-gray-700 whitespace-pre-line">{summary}</p>
        </div>
      )}
      {summarize.isError && (
        <p className="text-red-600 text-sm">Failed to generate summary.</p>
      )}

      <div className="flex items-center justify-between">
        <h2 className="font-semibold text-gray-700">Feedback</h2>
        <select
          value={statusFilter ?? ''}
          onChange={(e) => handleStatusChange(e.target.value)}
          className="border rounded px-2 py-1 text-sm"
        >
          <option value="">All statuses</option>
          {ALL_STATUSES.map((s) => (
            <option key={s} value={s}>{s.replace('_', ' ')}</option>
          ))}
        </select>
      </div>

      {feedbackLoading ? (
        <p className="text-gray-500">Loading feedback…</p>
      ) : feedbackItems.length === 0 ? (
        <p className="text-gray-400 text-sm">No feedback found.</p>
      ) : (
        <div className="space-y-2">
          {feedbackItems.map((item) => (
            <Link
              key={item.id}
              to={`/feedback/${item.id}`}
              className="flex items-center justify-between bg-white rounded-lg shadow p-4 hover:shadow-md transition-shadow"
            >
              <div>
                <p className="font-medium text-gray-800">{item.title}</p>
                <p className="text-xs text-gray-400 mt-0.5">
                  {new Date(item.created_at).toLocaleDateString()}
                </p>
              </div>
              <StatusBadge status={item.status} />
            </Link>
          ))}
        </div>
      )}

      {meta && (
        <Pagination
          currentPage={meta.current_page}
          lastPage={meta.last_page}
          onPageChange={handlePageChange}
        />
      )}
    </div>
  )
}
