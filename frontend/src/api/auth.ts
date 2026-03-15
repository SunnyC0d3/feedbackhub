import client from './client'
import type { LoginResponse, User } from '../types'

export async function login(tenantSlug: string, email: string, password: string): Promise<LoginResponse> {
  const { data } = await client.post<LoginResponse>('/auth/login', {
    tenant_slug: tenantSlug,
    email,
    password,
  })
  return data
}

export async function logout(): Promise<void> {
  await client.post('/auth/logout')
}

export async function me(): Promise<User> {
  const { data } = await client.get<User>('/me')
  return data
}
