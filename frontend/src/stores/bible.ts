// Bible store — workspace settings (enabled translations + default) and a
// per-translation book cache for the reference picker. Verse resolution is a
// pass-through to the API (server-cached), not stored here.
import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import * as bibleApi from '@/api/bible'
import type { BibleBook, BibleSettings, ResolvedScripture, ScriptureQuery } from '@/types'

export const useBibleStore = defineStore('bible', () => {
  const settings = ref<BibleSettings | null>(null)
  const loading = ref(false)
  /** book lists keyed by translation id. */
  const booksByTranslation = ref<Record<string, BibleBook[]>>({})

  const enabled = computed(() => settings.value?.enabled_translations ?? [])
  const defaultTranslationId = computed(() => settings.value?.default_translation_id ?? null)
  const isConfigured = computed(() => enabled.value.length > 0 && !!defaultTranslationId.value)

  async function loadSettings(): Promise<BibleSettings> {
    loading.value = true
    try {
      settings.value = await bibleApi.getSettings()
      return settings.value
    } finally {
      loading.value = false
    }
  }

  async function saveSettings(input: BibleSettings): Promise<void> {
    settings.value = await bibleApi.saveSettings(input)
    // Book structure changed server-side; drop the cache.
    booksByTranslation.value = {}
  }

  /** Fetch (and cache) the books of a translation for the picker. */
  async function ensureBooks(translationId: string): Promise<BibleBook[]> {
    const cached = booksByTranslation.value[translationId]
    if (cached) return cached
    const books = await bibleApi.getBooks(translationId)
    booksByTranslation.value = { ...booksByTranslation.value, [translationId]: books }
    return books
  }

  function resolve(query: ScriptureQuery): Promise<ResolvedScripture> {
    return bibleApi.resolveScripture(query)
  }

  return {
    settings,
    loading,
    enabled,
    defaultTranslationId,
    isConfigured,
    loadSettings,
    saveSettings,
    ensureBooks,
    resolve,
  }
})
