// Songbook CRUD.
// Ref: services/auth/routes/api.php (/api/songbooks)
import { apiClient } from './client'
import type { Songbook } from '@/types'

export async function listSongbooks(): Promise<Songbook[]> {
  const { data } = await apiClient.get<Songbook[]>('/songbooks')
  return data
}

export async function getSongbook(id: string): Promise<Songbook> {
  const { data } = await apiClient.get<Songbook>(`/songbooks/${id}`)
  return data
}

export interface SongbookInput {
  name: string
  description?: string | null
}

export async function createSongbook(input: SongbookInput): Promise<Songbook> {
  const { data } = await apiClient.post<Songbook>('/songbooks', input)
  return data
}

export async function updateSongbook(id: string, input: Partial<SongbookInput>): Promise<Songbook> {
  const { data } = await apiClient.put<Songbook>(`/songbooks/${id}`, input)
  return data
}

export async function deleteSongbook(id: string): Promise<void> {
  await apiClient.delete(`/songbooks/${id}`)
}
