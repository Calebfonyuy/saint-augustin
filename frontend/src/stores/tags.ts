// Tag-suggestions store (Stage 4, FR-PL-1).
//
// A tiny cache over GET /tags so every form that offers tag autocomplete
// (the create-playlist modal, the song editor) shares one list and one
// fetch. `ensureLoaded()` fetches once and reuses the result; `refresh()`
// forces a re-fetch after a mutation introduces new tags.
import { defineStore } from 'pinia'
import { ref } from 'vue'
import { listTags } from '@/api/tags'

export const useTagsStore = defineStore('tags', () => {
  const tags = ref<string[]>([])
  const loading = ref(false)
  const loaded = ref(false)

  async function refresh(): Promise<void> {
    loading.value = true
    try {
      tags.value = await listTags()
      loaded.value = true
    } finally {
      loading.value = false
    }
  }

  /** Fetch once; subsequent callers reuse the cached list. */
  async function ensureLoaded(): Promise<void> {
    if (loaded.value || loading.value) return
    await refresh()
  }

  return { tags, loading, loaded, refresh, ensureLoaded }
})
