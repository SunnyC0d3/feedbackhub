import type { FeedbackStatus } from '../types'

const colours: Record<FeedbackStatus, string> = {
  draft: 'bg-gray-100 text-gray-700',
  open: 'bg-blue-100 text-blue-700',
  seen: 'bg-purple-100 text-purple-700',
  pending: 'bg-yellow-100 text-yellow-700',
  review_required: 'bg-orange-100 text-orange-700',
  in_progress: 'bg-cyan-100 text-cyan-700',
  resolved: 'bg-green-100 text-green-700',
  closed: 'bg-gray-200 text-gray-600',
}

export default function StatusBadge({ status }: { status: FeedbackStatus }) {
  return (
    <span className={`inline-block px-2 py-0.5 rounded text-xs font-medium ${colours[status]}`}>
      {status.replace('_', ' ')}
    </span>
  )
}
