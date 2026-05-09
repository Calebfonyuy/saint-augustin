// Song sheet (file attachment) API — Phase 2 / FR5.
// Ref: services/auth/routes/api.php (/api/songs/{id}/sheets, /api/sheets/{id})
import { apiClient } from './client'
import type { SongSheet } from '@/types'

interface SongSheetListResponse {
  data: SongSheet[]
}

export async function listSongSheets(songId: string): Promise<SongSheet[]> {
  const { data } = await apiClient.get<SongSheetListResponse>(`/songs/${songId}/sheets`)
  return data.data
}

export async function uploadSongSheet(songId: string, file: File): Promise<SongSheet> {
  const form = new FormData()
  form.append('file', file)

  const { data } = await apiClient.post<SongSheet>(`/songs/${songId}/sheets`, form, {
    headers: { 'Content-Type': 'multipart/form-data' },
  })
  return data
}

/** Refresh a sheet's presigned URL (the previous one expires after a few minutes). */
export async function getSongSheet(id: string): Promise<SongSheet> {
  const { data } = await apiClient.get<SongSheet>(`/sheets/${id}`)
  return data
}

export async function deleteSongSheet(id: string): Promise<void> {
  await apiClient.delete(`/sheets/${id}`)
}
