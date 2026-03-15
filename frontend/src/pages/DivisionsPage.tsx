import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { getDivisions } from '../api/divisions'

export default function DivisionsPage() {
  const { data, isLoading, isError } = useQuery({
    queryKey: ['divisions'],
    queryFn: getDivisions,
  })

  if (isLoading) return <p className="text-gray-500">Loading divisions…</p>
  if (isError) return <p className="text-red-600">Failed to load divisions.</p>

  const divisions = data?.data ?? []

  return (
    <div className="space-y-4">
      <h1 className="text-2xl font-bold text-gray-800">Divisions</h1>
      {divisions.length === 0 ? (
        <p className="text-gray-500">No divisions found.</p>
      ) : (
        <div className="grid md:grid-cols-2 gap-4">
          {divisions.map((division) => (
            <Link
              key={division.id}
              to={`/divisions/${division.id}`}
              className="bg-white rounded-lg shadow p-5 hover:shadow-md transition-shadow"
            >
              <h2 className="font-semibold text-gray-800">{division.name}</h2>
              <p className="text-sm text-gray-400 mt-0.5">{division.slug}</p>
              <div className="flex gap-4 mt-3 text-sm text-gray-500">
                {division.user_count !== undefined && (
                  <span>{division.user_count} users</span>
                )}
              </div>
            </Link>
          ))}
        </div>
      )}
    </div>
  )
}
