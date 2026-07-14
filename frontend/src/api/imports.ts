// Bulk song import API — currently only the VideoPsalm `.vpagd` flow is
// wired up. Two-stage UX:
//   1. previewVideopsalm(file)  →  list of songs the file contains
//   2. importVideopsalm(file, guids?)  →  commit (optionally restricted to
//      a subset of song GUIDs).
//
// Ref: services/auth/routes/api.php (/api/imports/videopsalm)
import { apiClient } from './client'

export interface VpagdPreviewSong {
  guid: string
  title: string
  songbook: string
  verse_count: number
}

export interface VpagdPreview {
  songs: VpagdPreviewSong[]
  /** Map of songbook name → number of songs in that book within the archive. */
  songbooks: Record<string, number>
}

export interface VpagdImportResult {
  created: number
  skipped: number
  total: number
  /** Songbook display names that were created or reused for this import. */
  songbooks: string[]
}

export async function previewVideopsalm(file: File): Promise<VpagdPreview> {
  const form = new FormData()
  form.append('file', file)
  form.append('dry_run', '1')

  const { data } = await apiClient.post<VpagdPreview>('/imports/videopsalm', form, {
    headers: { 'Content-Type': 'multipart/form-data' },
    // Parsing 380 songs is sub-second on the server but the multipart upload
    // itself can take a moment over a slow link — bump the per-request
    // timeout above the client's 15 s default.
    timeout: 60_000,
  })
  return data
}

export async function importVideopsalm(file: File, guids?: string[]): Promise<VpagdImportResult> {
  const form = new FormData()
  form.append('file', file)
  if (guids && guids.length > 0) {
    for (const g of guids) form.append('guids[]', g)
  }

  const { data } = await apiClient.post<VpagdImportResult>('/imports/videopsalm', form, {
    headers: { 'Content-Type': 'multipart/form-data' },
    timeout: 120_000,
  })
  return data
}

// ── STAUG archive import / export (Stage 6) ────────────────────────────
// Ref: services/auth/routes/api.php (/api/imports/staug, /api/exports/full)

export type StaugAction = 'create' | 'skip' | 'conflict'

export interface StaugPreviewSong {
  id: string | null
  title: string
  /** What committing would do: create a new row, skip an exact match, or
   *  create a new record because the id exists with a different title. */
  action: StaugAction
}

export interface StaugImportPreview {
  type: string
  songs: StaugPreviewSong[]
  /** Map of songbook name → number of songs in the archive under it. */
  songbooks: Record<string, number>
}

export interface StaugImportResult {
  created: number
  skipped: number
  conflicted: number
  total: number
  songbooks: string[]
}

export async function previewStaug(file: File): Promise<StaugImportPreview> {
  const form = new FormData()
  form.append('file', file)
  form.append('dry_run', '1')

  const { data } = await apiClient.post<StaugImportPreview>('/imports/staug', form, {
    headers: { 'Content-Type': 'multipart/form-data' },
    timeout: 60_000,
  })
  return data
}

export async function importStaug(file: File): Promise<StaugImportResult> {
  const form = new FormData()
  form.append('file', file)

  const { data } = await apiClient.post<StaugImportResult>('/imports/staug', form, {
    headers: { 'Content-Type': 'multipart/form-data' },
    timeout: 120_000,
  })
  return data
}

/** Queue a full-library export. The archive is built server-side and a
 *  download link is emailed to the requesting admin. */
export async function requestFullExport(): Promise<{ message: string; status: string }> {
  const { data } = await apiClient.post<{ message: string; status: string }>('/exports/full')
  return data
}
