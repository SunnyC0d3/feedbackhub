import { useQuery } from '@tanstack/react-query'
import { Link, useSearchParams } from 'react-router-dom'
import { getProjects } from '../api/projects'
import Pagination from '../components/Pagination'

export default function ProjectsPage() {
  const [searchParams, setSearchParams] = useSearchParams()
  const page = Number(searchParams.get('page') ?? '1')

  const { data, isLoading, isError } = useQuery({
    queryKey: ['projects', { page }],
    queryFn: () => getProjects(page),
  })

  function handlePageChange(newPage: number) {
    setSearchParams({ page: String(newPage) })
  }

  if (isLoading) return <p className="text-gray-500">Loading projects…</p>
  if (isError) return <p className="text-red-600">Failed to load projects.</p>

  const projects = data?.data ?? []
  const meta = data?.meta

  return (
    <div className="space-y-4">
      <h1 className="text-2xl font-bold text-gray-800">Projects</h1>
      {projects.length === 0 ? (
        <p className="text-gray-500">No projects found.</p>
      ) : (
        <div className="space-y-3">
          {projects.map((project) => (
            <Link
              key={project.id}
              to={`/projects/${project.id}`}
              className="block bg-white rounded-lg shadow p-5 hover:shadow-md transition-shadow"
            >
              <div className="flex items-start justify-between">
                <div>
                  <h2 className="font-semibold text-gray-800">{project.name}</h2>
                  {project.description && (
                    <p className="text-sm text-gray-500 mt-1 line-clamp-2">{project.description}</p>
                  )}
                </div>
                {project.feedback_count !== undefined && (
                  <span className="text-sm text-gray-400 ml-4 shrink-0">
                    {project.feedback_count} feedback
                  </span>
                )}
              </div>
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
