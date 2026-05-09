// Share-link CRUD + public share resolution (Phase 3).
// Ref: services/auth/app/Http/Controllers/ShareLinkController.php
import axios from 'axios'
import { apiClient, API_BASE_URL } from './client'
import type { ShareLink, ShareMode, SharedPlaylistResponse } from '@/types'

export async function listShareLinks(playlistId: string): Promise<ShareLink[]> {
  const { data } = await apiClient.get<{ data: ShareLink[] }>(`/playlists/${playlistId}/share`)
  return data.data
}

export async function createShareLink(
  playlistId: string,
  mode: ShareMode,
  expiresAt?: string | null,
): Promise<ShareLink> {
  const { data } = await apiClient.post<ShareLink>(`/playlists/${playlistId}/share`, {
    mode,
    expires_at: expiresAt ?? null,
  })
  return data
}

export async function revokeShareLink(shareLinkId: string): Promise<void> {
  await apiClient.delete(`/share-links/${shareLinkId}`)
}

/**
 * Public token resolver. Uses a bare axios client so we don't attach the
 * bearer token from the authenticated session — the share endpoint must
 * succeed for unauthenticated visitors, and sending an Authorization
 * header from a logged-in user simply isn't relevant to the lookup.
 */
export async function resolveSharedPlaylist(token: string): Promise<SharedPlaylistResponse> {
  const { data } = await axios.get<SharedPlaylistResponse>(
    `${API_BASE_URL}/share/${encodeURIComponent(token)}`,
  )
  return data
}
