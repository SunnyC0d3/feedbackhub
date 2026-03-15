import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useNavigate, Link } from 'react-router-dom'
import { createFeedback } from '../api/feedback'
import { getProjects } from '../api/projects'
import type { FeedbackStatus } from '../types'
import type { AxiosError } from 'axios'

const STATUSES: FeedbackStatus[] = ['draft', 'open', 'pending']

export default function CreateFeedbackPage() {
  const navigate = useNavigate()
  const queryClient = useQueryClient()

  const [title, setTitle] = useState('')
  const [description, setDescription] = useState('')
  const [status, setStatus] = useState<FeedbackStatus>('open')
  const [projectId, setProjectId] = useState<number | ''>('')

  const { data: projectsData } = useQuery({
    queryKey: ['projects', { page: 1 }],
    queryFn: () => getProjects(1),
  })

  const mutation = useMutation({
    mutationFn: () =>
      createFeedback({
        title,
        description,
        status,
        project_id: projectId as number,
      }),
    onSuccess: (res) => {
      void queryClient.invalidateQueries({ queryKey: ['feedback'] })
      navigate(`/feedback/${res.data.id}`)
    },
  })

  const validationErrors = (mutation.error as AxiosError<{ errors?: Record<string, string[]> }> | null)
    ?.response?.data?.errors

  function handleSubmit(e: React.FormEvent) {
    e.preventDefault()
    if (!projectId) return
    mutation.mutate()
  }

  return (
    <div className="max-w-lg space-y-4">
      <div>
        <Link to="/feedback" className="text-sm text-indigo-600 hover:underline">
          ← Feedback
        </Link>
        <h1 className="text-2xl font-bold text-gray-800 mt-1">New Feedback</h1>
      </div>

      <form onSubmit={handleSubmit} className="bg-white rounded-lg shadow p-6 space-y-4">
        <div>
          <label className="block text-sm font-medium text-gray-700 mb-1">Project</label>
          <select
            value={projectId}
            onChange={(e) => setProjectId(Number(e.target.value))}
            required
            className="w-full border rounded px-3 py-2 text-sm"
          >
            <option value="">Select a project…</option>
            {(projectsData?.data ?? []).map((p) => (
              <option key={p.id} value={p.id}>{p.name}</option>
            ))}
          </select>
          {validationErrors?.project_id && (
            <p className="text-red-600 text-xs mt-1">{validationErrors.project_id[0]}</p>
          )}
        </div>

        <div>
          <label className="block text-sm font-medium text-gray-700 mb-1">Title</label>
          <input
            type="text"
            value={title}
            onChange={(e) => setTitle(e.target.value)}
            required
            className="w-full border rounded px-3 py-2 text-sm"
          />
          {validationErrors?.title && (
            <p className="text-red-600 text-xs mt-1">{validationErrors.title[0]}</p>
          )}
        </div>

        <div>
          <label className="block text-sm font-medium text-gray-700 mb-1">Description</label>
          <textarea
            value={description}
            onChange={(e) => setDescription(e.target.value)}
            rows={4}
            className="w-full border rounded px-3 py-2 text-sm"
          />
          {validationErrors?.description && (
            <p className="text-red-600 text-xs mt-1">{validationErrors.description[0]}</p>
          )}
        </div>

        <div>
          <label className="block text-sm font-medium text-gray-700 mb-1">Status</label>
          <select
            value={status}
            onChange={(e) => setStatus(e.target.value as FeedbackStatus)}
            className="w-full border rounded px-3 py-2 text-sm"
          >
            {STATUSES.map((s) => (
              <option key={s} value={s}>{s}</option>
            ))}
          </select>
        </div>

        {mutation.error && !validationErrors && (
          <p className="text-red-600 text-sm">Failed to create feedback. Please try again.</p>
        )}

        <button
          type="submit"
          disabled={mutation.isPending || !projectId}
          className="w-full bg-indigo-600 text-white rounded py-2 text-sm font-medium hover:bg-indigo-700 disabled:opacity-60"
        >
          {mutation.isPending ? 'Creating…' : 'Create Feedback'}
        </button>
      </form>
    </div>
  )
}
