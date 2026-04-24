// Song CRUD + search.
// Ref: services/auth/routes/api.php (/api/songs)
import { apiClient } from './client'
import type { Paginated, Song, SongInput, SongListQuery } from '@/types'

export async function listSongs(query: SongListQuery = {}): Promise<Paginated<Song>> {
  const { data } = await apiClient.get<Paginated<Song>>('/songs', { params: query })
  return data
}

export async function getSong(id: string): Promise<Song> {
  const { data } = await apiClient.get<Song>(`/songs/${id}`)
  return data
}

export async function createSong(input: SongInput): Promise<Song> {
  const { data } = await apiClient.post<Song>('/songs', input)
  return data
}

export async function updateSong(id: string, input: Partial<SongInput>): Promise<Song> {
  const { data } = await apiClient.put<Song>(`/songs/${id}`, input)
  return data
}

export async function deleteSong(id: string): Promise<void> {
  await apiClient.delete(`/songs/${id}`)
}

export async function restoreSong(id: string): Promise<Song> {
  const { data } = await apiClient.post<Song>(`/songs/${id}/restore`)
  return data
}
