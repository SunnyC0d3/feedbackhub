import { useState } from 'react'
import { useMutation } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { queryAnalysis } from '../api/analysis'
import StatusBadge from '../components/StatusBadge'
import type { AxiosError } from 'axios'

export default function AnalysisPage() {
  const [query, setQuery] = useState('')

  const mutation = useMutation({
    mutationFn: () => queryAnalysis(query),
  })

  const error = mutation.error as AxiosError<{ message: string }> | null

  function handleSubmit(e: React.FormEvent) {
    e.preventDefault()
    if (query.trim()) mutation.mutate()
  }

  const result = mutation.data?.data

  return (
    <div className="space-y-6 max-w-3xl">
      <h1 className="text-2xl font-bold text-gray-800">AI Search</h1>

      <form onSubmit={handleSubmit} className="flex gap-3">
        <input
          type="text"
          value={query}
          onChange={(e) => setQuery(e.target.value)}
          placeholder="What are users saying about performance?"
          required
          className="flex-1 border rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-300"
        />
        <button
          type="submit"
          disabled={mutation.isPending || !query.trim()}
          className="bg-indigo-600 text-white px-5 py-2 rounded text-sm font-medium hover:bg-indigo-700 disabled:opacity-60"
        >
          {mutation.isPending ? 'Searching…' : 'Search'}
        </button>
      </form>

      {error && (
        <p className="text-red-600 text-sm">
          {error.response?.data?.message ?? 'Search failed. Please try again.'}
        </p>
      )}

      {result && (
        <div className="space-y-5">
          {result.summary && (
            <div className="bg-indigo-50 border border-indigo-200 rounded-lg p-5">
              <div className="flex items-center justify-between mb-2">
                <h2 className="font-semibold text-indigo-800">AI Summary</h2>
                <div className="text-xs text-indigo-500">
                  {result.feedback_found} matches &middot; {result.usage.tokens_used} tokens &middot; ${result.usage.cost_usd!.toFixed(6)} USD
                </div>
              </div>
              <p className="text-sm text-gray-700 whitespace-pre-line">{result.summary}</p>
            </div>
          )}

          <div>
            <h2 className="font-semibold text-gray-700 mb-3">Matched Feedback</h2>
            {result.feedback.length === 0 ? (
              <p className="text-gray-400 text-sm">No matching feedback found.</p>
            ) : (
              <div className="space-y-2">
                {result.feedback.map((item) => (
                  <Link
                    key={item.id}
                    to={`/feedback/${item.id}`}
                    className="flex items-center justify-between bg-white rounded-lg shadow p-4 hover:shadow-md transition-shadow"
                  >
                    <div>
                      <p className="font-medium text-gray-800">{item.title}</p>
                      {item.description && (
                        <p className="text-xs text-gray-500 mt-0.5 line-clamp-1">{item.description}</p>
                      )}
                    </div>
                    <StatusBadge status={item.status} />
                  </Link>
                ))}
              </div>
            )}
          </div>
        </div>
      )}
    </div>
  )
}
