// Tag suggestions (Stage 4, FR-PL-1).
// Ref: services/auth/app/Http/Controllers/TagController.php (GET /api/tags)
import { apiClient } from './client'

/** Distinct, sorted union of every tag used on songs and playlists. */
export async function listTags(): Promise<string[]> {
  const { data } = await apiClient.get<{ data: string[] }>('/tags')
  return data.data
}
