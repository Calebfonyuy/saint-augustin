// Projection-service HTTP client (Phase 4).
//
// The projection service is fronted by the same nginx gateway as the auth
// service, behind the `/api/projection/` prefix (see
// docker/nginx/default.conf). We re-use the existing `projectionClient` axios
// instance so the bearer token attaches automatically — session creation
// is performed by the worship leader, who is authenticated.
//
// Ref: services/projection/src/sessions/sessions.controller.ts
import axios, { type AxiosInstance } from 'axios'
import type {
  CreateProjectionSessionInput,
  CreateProjectionSessionResponse,
  ProjectionSessionState,
} from '@/types'

const PROJECTION_BASE_URL = import.meta.env.VITE_PROJECTION_BASE_URL || 'http://localhost:3000'
const BASE = '/sessions'

const projectionClient: AxiosInstance = axios.create({
  baseURL: PROJECTION_BASE_URL,
  headers: {
    Accept: 'application/json',
    'Content-Type': 'application/json',
  },
  timeout: 15000,
})

export async function createSession(
  input: CreateProjectionSessionInput,
): Promise<CreateProjectionSessionResponse> {
  const { data } = await projectionClient.post<CreateProjectionSessionResponse>(BASE, input)
  return data
}

export async function getSession(id: string): Promise<ProjectionSessionState> {
  const { data } = await projectionClient.get<ProjectionSessionState>(`${BASE}/${id}`)
  return data
}

export async function destroySession(id: string, controlToken: string): Promise<void> {
  await projectionClient.delete(`${BASE}/${id}`, {
    headers: { 'X-Control-Token': controlToken },
  })
}
