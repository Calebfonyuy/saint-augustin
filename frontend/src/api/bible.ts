// Bible module API (Stage 7, FR-BI).
// Ref: services/auth/routes/api.php (/api/bible/*)
import { apiClient } from './client'
import type {
  BibleBook,
  BibleSettings,
  BibleTranslation,
  ResolvedScripture,
  ScriptureQuery,
} from '@/types'

/** Admin: the translations HelloAO offers in the configured languages. */
export async function availableTranslations(): Promise<BibleTranslation[]> {
  const { data } = await apiClient.get<{ translations: BibleTranslation[] }>(
    '/bible/translations/available',
  )
  return data.translations
}

export async function getSettings(): Promise<BibleSettings> {
  const { data } = await apiClient.get<BibleSettings>('/bible/settings')
  return data
}

/** Admin: persist enabled translations + default (repopulates the book cache). */
export async function saveSettings(input: BibleSettings): Promise<BibleSettings> {
  const { data } = await apiClient.put<BibleSettings>('/bible/settings', input)
  return data
}

export async function getBooks(translation?: string): Promise<BibleBook[]> {
  const { data } = await apiClient.get<{ books: BibleBook[] }>('/bible/books', {
    params: translation ? { translation } : {},
  })
  return data.books
}

/** Resolve a reference to its verses. Warms the server-side chapter cache. */
export async function resolveScripture(query: ScriptureQuery): Promise<ResolvedScripture> {
  const { data } = await apiClient.get<ResolvedScripture>('/bible/resolve', { params: query })
  return data
}
