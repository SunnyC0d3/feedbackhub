import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { useParams, Link, useNavigate } from 'react-router-dom'
import { getFeedbackItem, updateFeedbackStatus, deleteFeedback } from '../api/feedback'
import StatusBadge from '../components/StatusBadge'
import { useRole } from '../hooks/useRole'
import type { FeedbackStatus } from '../types'
import type { AxiosError } from 'axios'

const ALL_STATUSES: FeedbackStatus[] = [
  'draft', 'open', 'seen', 'pending', 'review_required', 'in_progress', 'resolved', 'closed',
]

export default function FeedbackDetailPage() {
  const { id } = useParams<{ id: string }>()
  const feedbackId = Number(id)
  const queryClient = useQueryClient()
  const navigate = useNavigate()
  const { canUpdateStatus, canDelete } = useRole()

  const { data, isLoading, isError } = useQuery({
    queryKey: ['feedback', feedbackId],
    queryFn: () => getFeedbackItem(feedbackId),
    enabled: !isNaN(feedbackId),
  })

  const statusMutation = useMutation({
    mutationFn: (status: FeedbackStatus) => updateFeedbackStatus(feedbackId, status),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['feedback', feedbackId] })
      void queryClient.invalidateQueries({ queryKey: ['feedback'] })
    },
  })

  const deleteMutation = useMutation({
    mutationFn: () => deleteFeedback(feedbackId),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['feedback'] })
      navigate('/feedback')
    },
  })

  if (isLoading) return <p className="text-gray-500">Loading…</p>
  if (isError) return <p className="text-red-600">Feedback not found.</p>

  const item = data?.data
  if (!item) return null

  function handleDelete() {
    if (confirm('Delete this feedback? This cannot be undone.')) {
      deleteMutation.mutate()
    }
  }

  const statusError = statusMutation.error as AxiosError<{ message: string }> | null
  const deleteError = deleteMutation.error as AxiosError<{ message: string }> | null

  return (
    <div className="space-y-6 max-w-2xl">
      <div>
        <Link to="/feedback" className="text-sm text-indigo-600 hover:underline">
          ← Feedback
        </Link>
        <div className="flex items-start justify-between mt-1">
          <h1 className="text-2xl font-bold text-gray-800">{item.title}</h1>
          <StatusBadge status={item.status} />
        </div>
      </div>

      <div className="bg-white rounded-lg shadow p-5 space-y-3">
        {item.description && (
          <p className="text-gray-700 whitespace-pre-line">{item.description}</p>
        )}
        <div className="text-sm text-gray-400 flex gap-4 flex-wrap">
          <span>Project: <span className="text-gray-600">{item.project?.name ?? `#${item.project_id}`}</span></span>
          {item.author && (
            <span>Author: <span className="text-gray-600">{item.author.name}</span></span>
          )}
          <span>Created: <span className="text-gray-600">{new Date(item.created_at).toLocaleString()}</span></span>
          <span>Updated: <span className="text-gray-600">{new Date(item.updated_at).toLocaleString()}</span></span>
        </div>
      </div>

      <div className="flex items-center gap-3 flex-wrap">
        {canUpdateStatus && (
          <div className="flex items-center gap-2">
            <label className="text-sm text-gray-600 font-medium">Status:</label>
            <select
              value={item.status}
              onChange={(e) => statusMutation.mutate(e.target.value as FeedbackStatus)}
              disabled={statusMutation.isPending}
              className="border rounded px-2 py-1 text-sm"
            >
              {ALL_STATUSES.map((s) => (
                <option key={s} value={s}>{s.replace('_', ' ')}</option>
              ))}
            </select>
            {statusMutation.isPending && <span className="text-xs text-gray-400">Saving…</span>}
          </div>
        )}

        {canDelete && (
          <button
            onClick={handleDelete}
            disabled={deleteMutation.isPending}
            className="ml-auto bg-red-600 text-white px-4 py-1.5 rounded text-sm font-medium hover:bg-red-700 disabled:opacity-60"
          >
            {deleteMutation.isPending ? 'Deleting…' : 'Delete'}
          </button>
        )}
      </div>

      {statusError && (
        <p className="text-red-600 text-sm">
          {statusError.response?.data?.message ?? 'Failed to update status.'}
        </p>
      )}
      {deleteError && (
        <p className="text-red-600 text-sm">
          {deleteError.response?.data?.message ?? 'Failed to delete.'}
        </p>
      )}
    </div>
  )
}
