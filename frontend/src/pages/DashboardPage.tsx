import { useQuery } from '@tanstack/react-query'
import { getMetrics } from '../api/metrics'

function MetricCard({ label, value }: { label: string; value: number | string }) {
  return (
    <div className="bg-white rounded-lg shadow p-5">
      <p className="text-sm text-gray-500">{label}</p>
      <p className="text-3xl font-bold text-gray-800 mt-1">{value}</p>
    </div>
  )
}

export default function DashboardPage() {
  const { data, isLoading, isError } = useQuery({
    queryKey: ['metrics'],
    queryFn: getMetrics,
  })

  if (isLoading) return <p className="text-gray-500">Loading metrics…</p>
  if (isError) return <p className="text-red-600">Failed to load metrics.</p>

  const m = data?.data
  if (!m) return null

  const statusEntries = Object.entries(m.feedback_by_status)

  return (
    <div className="space-y-6">
      <h1 className="text-2xl font-bold text-gray-800">Dashboard</h1>

      <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
        <MetricCard label="Total Feedback" value={m.total_feedback} />
        <MetricCard label="Total Projects" value={m.total_projects} />
        <MetricCard label="Total Users" value={m.total_users} />
        <MetricCard label="Failed Jobs" value={m.failed_jobs} />
      </div>

      <div className="grid grid-cols-2 gap-4">
        <MetricCard label="Feedback Today" value={m.feedback_today} />
        <MetricCard label="Feedback This Week" value={m.feedback_this_week} />
      </div>

      <div className="bg-white rounded-lg shadow p-5">
        <h2 className="text-sm font-medium text-gray-500 mb-3">Feedback by Status</h2>
        {statusEntries.length === 0 ? (
          <p className="text-sm text-gray-400">No data.</p>
        ) : (
          <div className="grid grid-cols-2 md:grid-cols-4 gap-3">
            {statusEntries.map(([status, count]) => (
              <div key={status} className="bg-gray-50 rounded p-3">
                <p className="text-xs text-gray-500 capitalize">{status.replace('_', ' ')}</p>
                <p className="text-xl font-bold text-gray-700">{count}</p>
              </div>
            ))}
          </div>
        )}
      </div>
    </div>
  )
}
