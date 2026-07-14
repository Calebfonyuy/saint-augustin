// Playlist + playlist item CRUD (Phase 3).
// Ref: services/auth/routes/api.php (/api/playlists)
import { apiClient } from './client'
import type {
  Paginated,
  Playlist,
  PlaylistInput,
  PlaylistItem,
  PlaylistListQuery,
  PlaylistSummary,
} from '@/types'

// ── Playlists ────────────────────────────────────────────────────────

export async function listPlaylists(
  query: PlaylistListQuery = {},
): Promise<Paginated<PlaylistSummary>> {
  const { data } = await apiClient.get<Paginated<PlaylistSummary>>('/playlists', {
    params: { ...query, mine: query.mine ? 1 : undefined },
  })
  return data
}

export async function getPlaylist(id: string): Promise<Playlist> {
  const { data } = await apiClient.get<Playlist>(`/playlists/${id}`)
  return data
}

export async function createPlaylist(input: PlaylistInput): Promise<Playlist> {
  const { data } = await apiClient.post<Playlist>('/playlists', input)
  return data
}

export async function updatePlaylist(id: string, input: Partial<PlaylistInput>): Promise<Playlist> {
  const { data } = await apiClient.put<Playlist>(`/playlists/${id}`, input)
  return data
}

export async function deletePlaylist(id: string): Promise<void> {
  await apiClient.delete(`/playlists/${id}`)
}

export async function duplicatePlaylist(id: string, name?: string): Promise<Playlist> {
  const { data } = await apiClient.post<Playlist>(`/playlists/${id}/duplicate`, { name })
  return data
}

/** Returns the absolute URL to GET the export — opening it kicks off the
 * authenticated download. The browser will inherit the bearer header from
 * the apiClient on fetch-based downloads, so we expose a helper that
 * uses the apiClient for blob retrieval and saves via an anchor. */
export async function downloadPlaylistExport(
  id: string,
  format: 'pdf' | 'txt' | 'staug',
): Promise<{ blob: Blob; filename: string }> {
  const response = await apiClient.get<Blob>(`/playlists/${id}/export`, {
    params: { format },
    responseType: 'blob',
  })

  const dispo = (response.headers['content-disposition'] as string | undefined) ?? ''
  const match = /filename="?([^"]+)"?/.exec(dispo)
  const ext = format === 'staug' ? 'staug.zip' : format
  const filename = match?.[1] ?? `playlist.${ext}`

  return { blob: response.data, filename }
}

// ── Playlist items ───────────────────────────────────────────────────

export interface AddItemInput {
  song_id: string
  position?: number
  target_key?: string | null
  notes?: string | null
}

/** Result of adding a song. `created` is false when the song was already in
 *  the playlist and the server returned the existing item as a no-op (200). */
export interface AddItemResult {
  item: PlaylistItem
  created: boolean
}

export async function addPlaylistItem(
  playlistId: string,
  input: AddItemInput,
): Promise<AddItemResult> {
  const res = await apiClient.post<PlaylistItem>(`/playlists/${playlistId}/items`, input)
  return { item: res.data, created: res.status === 201 }
}

export async function updatePlaylistItem(
  playlistId: string,
  itemId: string,
  input: { target_key?: string | null; notes?: string | null },
): Promise<PlaylistItem> {
  const { data } = await apiClient.put<PlaylistItem>(
    `/playlists/${playlistId}/items/${itemId}`,
    input,
  )
  return data
}

export async function removePlaylistItem(playlistId: string, itemId: string): Promise<void> {
  await apiClient.delete(`/playlists/${playlistId}/items/${itemId}`)
}

export async function reorderPlaylistItems(
  playlistId: string,
  itemIds: string[],
): Promise<{ data: PlaylistItem[] }> {
  const { data } = await apiClient.put<{ data: PlaylistItem[] }>(
    `/playlists/${playlistId}/items/reorder`,
    { item_ids: itemIds },
  )
  return data
}
