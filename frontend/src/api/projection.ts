// Projection-service HTTP client.
//
// The projection service is fronted by the same nginx gateway as the auth
// service, behind the `/api/projection/` prefix (see
// docker/nginx/default.conf). We attach the same Bearer token as the auth
// API client because the projection service introspects it against
// /api/auth/me to identify the calling user.
//
// Ref: services/projection/src/sessions/sessions.controller.ts
import axios, { type AxiosInstance } from 'axios'
import { getStoredToken } from './client'
import type {
  CreateProjectionSessionInput,
  CreateProjectionSessionResponse,
  LoadProjectionSlidesInput,
  ProjectionSessionState,
  ProjectionSessionSummary,
} from '@/types'

const PROJECTION_BASE_URL =
  window.config?.VITE_PROJECTION_BASE_URL || 'http://localhost:3000'
const BASE = '/sessions'

const projectionClient: AxiosInstance = axios.create({
  baseURL: PROJECTION_BASE_URL,
  headers: {
    Accept: 'application/json',
    'Content-Type': 'application/json',
  },
  timeout: 15000,
})

projectionClient.interceptors.request.use((config) => {
  const token = getStoredToken()
  if (token) {
    config.headers.Authorization = `Bearer ${token}`
  }
  return config
})

export async function createSession(
  input: CreateProjectionSessionInput,
): Promise<CreateProjectionSessionResponse> {
  const { data } = await projectionClient.post<CreateProjectionSessionResponse>(BASE, input)
  return data
}

export async function listSessions(
  opts: { mine?: boolean } = {},
): Promise<ProjectionSessionSummary[]> {
  const { data } = await projectionClient.get<{ sessions: ProjectionSessionSummary[] }>(BASE, {
    params: opts.mine ? { mine: '1' } : undefined,
  })
  return data.sessions
}

export async function getSession(id: string): Promise<ProjectionSessionState> {
  const { data } = await projectionClient.get<ProjectionSessionState>(`${BASE}/${id}`)
  return data
}

export async function startSession(
  id: string,
): Promise<CreateProjectionSessionResponse> {
  const { data } = await projectionClient.post<CreateProjectionSessionResponse>(
    `${BASE}/${id}/start`,
  )
  return data
}

export async function endSession(id: string): Promise<ProjectionSessionState> {
  const { data } = await projectionClient.post<{ state: ProjectionSessionState }>(
    `${BASE}/${id}/end`,
  )
  return data.state
}

export async function loadSlides(
  id: string,
  input: LoadProjectionSlidesInput,
): Promise<ProjectionSessionState> {
  const { data } = await projectionClient.post<{ state: ProjectionSessionState }>(
    `${BASE}/${id}/load`,
    input,
  )
  return data.state
}

export async function destroySession(id: string): Promise<void> {
  await projectionClient.delete(`${BASE}/${id}`)
}

/** Re-confirm a previously-issued control token still works, without rotating it. */
export async function reclaimSession(
  id: string,
  token: string,
): Promise<CreateProjectionSessionResponse> {
  const { data } = await projectionClient.post<CreateProjectionSessionResponse>(
    `${BASE}/${id}/reclaim`,
    { token },
  )
  return data
}

/** Force-rotate the control token and revoke any connected controller sockets. */
export async function takeoverSession(id: string): Promise<CreateProjectionSessionResponse> {
  const { data } = await projectionClient.post<CreateProjectionSessionResponse>(
    `${BASE}/${id}/takeover`,
  )
  return data
}
