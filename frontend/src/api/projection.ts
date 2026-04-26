// Projection-service HTTP client (Phase 4).
//
// The projection service is fronted by the same nginx gateway as the auth
// service, behind the `/api/projection/` prefix (see
// docker/nginx/default.conf). We re-use the existing `apiClient` axios
// instance so the bearer token attaches automatically — session creation
// is performed by the worship leader, who is authenticated.
//
// Ref: services/projection/src/sessions/sessions.controller.ts
import { apiClient } from './client'
import type {
  CreateProjectionSessionInput,
  CreateProjectionSessionResponse,
  ProjectionSessionState,
} from '@/types'

const BASE = '/projection/sessions'

export async function createSession(
  input: CreateProjectionSessionInput,
): Promise<CreateProjectionSessionResponse> {
  const { data } = await apiClient.post<CreateProjectionSessionResponse>(BASE, input)
  return data
}

export async function getSession(id: string): Promise<ProjectionSessionState> {
  const { data } = await apiClient.get<ProjectionSessionState>(`${BASE}/${id}`)
  return data
}

export async function destroySession(id: string, controlToken: string): Promise<void> {
  await apiClient.delete(`${BASE}/${id}`, {
    headers: { 'X-Control-Token': controlToken },
  })
}
