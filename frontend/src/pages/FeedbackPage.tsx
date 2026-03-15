import { useQuery } from '@tanstack/react-query'
import { Link, useSearchParams } from 'react-router-dom'
import { getFeedback } from '../api/feedback'
import StatusBadge from '../components/StatusBadge'
import Pagination from '../components/Pagination'
import { useRole } from '../hooks/useRole'
import type { FeedbackStatus } from '../types'

const ALL_STATUSES: FeedbackStatus[] = [
  'draft', 'open', 'seen', 'pending', 'review_required', 'in_progress', 'resolved', 'closed',
]

export default function FeedbackPage() {
  const [searchParams, setSearchParams] = useSearchParams()
  const page = Number(searchParams.get('page') ?? '1')
  const statusFilter = (searchParams.get('status') as FeedbackStatus | null) ?? undefined
  const { canCreateFeedback } = useRole()

  const { data, isLoading, isError } = useQuery({
    queryKey: ['feedback', { page, status: statusFilter }],
    queryFn: () => getFeedback(page, statusFilter),
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

  if (isLoading) return <p className="text-gray-500">Loading feedback…</p>
  if (isError) return <p className="text-red-600">Failed to load feedback.</p>

  const items = data?.data ?? []
  const meta = data?.meta

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between">
        <h1 className="text-2xl font-bold text-gray-800">Feedback</h1>
        <div className="flex items-center gap-3">
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
          {canCreateFeedback && (
            <Link
              to="/feedback/new"
              className="bg-indigo-600 text-white px-4 py-1.5 rounded text-sm font-medium hover:bg-indigo-700"
            >
              New Feedback
            </Link>
          )}
        </div>
      </div>

      {items.length === 0 ? (
        <p className="text-gray-400 text-sm">No feedback found.</p>
      ) : (
        <div className="space-y-2">
          {items.map((item) => (
            <Link
              key={item.id}
              to={`/feedback/${item.id}`}
              className="flex items-center justify-between bg-white rounded-lg shadow p-4 hover:shadow-md transition-shadow"
            >
              <div>
                <p className="font-medium text-gray-800">{item.title}</p>
                <p className="text-xs text-gray-400 mt-0.5">
                  {item.project?.name ?? `Project #${item.project_id}`} &middot;{' '}
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
