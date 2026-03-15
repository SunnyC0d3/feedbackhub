import { useAuth } from '../context/AuthContext'
import type { UserRole } from '../types'

export function useRole() {
  const { user } = useAuth()
  const role = user?.role ?? 'support'

  return {
    role,
    isAdmin: role === 'admin',
    isManager: role === 'manager' || role === 'admin',
    canCreateFeedback: role !== 'support',
    canUpdateStatus: role === 'admin' || role === 'manager',
    canDelete: role === 'admin',
  }
}

export function hasRole(userRole: UserRole, required: UserRole[]): boolean {
  return required.includes(userRole)
}
