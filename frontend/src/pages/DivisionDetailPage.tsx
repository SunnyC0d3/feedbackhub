import { useQuery } from '@tanstack/react-query'
import { useParams, Link } from 'react-router-dom'
import { getDivision } from '../api/divisions'

export default function DivisionDetailPage() {
  const { id } = useParams<{ id: string }>()
  const divisionId = Number(id)

  const { data, isLoading, isError } = useQuery({
    queryKey: ['divisions', divisionId],
    queryFn: () => getDivision(divisionId),
    enabled: !isNaN(divisionId),
  })

  if (isLoading) return <p className="text-gray-500">Loading division…</p>
  if (isError) return <p className="text-red-600">Division not found.</p>

  const division = data?.data
  if (!division) return null

  return (
    <div className="space-y-6">
      <div>
        <Link to="/divisions" className="text-sm text-indigo-600 hover:underline">
          ← Divisions
        </Link>
        <h1 className="text-2xl font-bold text-gray-800 mt-1">{division.name}</h1>
        <p className="text-sm text-gray-400">{division.slug}</p>
      </div>

      <div className="bg-white rounded-lg shadow p-5">
        <h2 className="font-semibold text-gray-700 mb-3">Projects</h2>
        {!division.projects || division.projects.length === 0 ? (
          <p className="text-sm text-gray-400">No projects in this division.</p>
        ) : (
          <div className="space-y-2">
            {division.projects.map((project) => (
              <Link
                key={project.id}
                to={`/projects/${project.id}`}
                className="flex items-center justify-between p-3 rounded border hover:bg-gray-50"
              >
                <div>
                  <p className="font-medium text-gray-800">{project.name}</p>
                  <p className="text-xs text-gray-400">{project.slug}</p>
                </div>
                {project.feedback_count !== undefined && (
                  <span className="text-sm text-gray-500">{project.feedback_count} feedback</span>
                )}
              </Link>
            ))}
          </div>
        )}
      </div>
    </div>
  )
}
