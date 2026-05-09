// Song sheets store — keeps the sheet list for the currently-edited song.
// Scoped to one song at a time (the editor sets songId before fetchList()).
import { defineStore } from 'pinia'
import { ref } from 'vue'
import * as sheetsApi from '@/api/songSheets'
import type { SongSheet } from '@/types'

export const useSongSheetsStore = defineStore('songSheets', () => {
  const list = ref<SongSheet[]>([])
  const loading = ref(false)
  const uploading = ref(false)

  async function fetchList(songId: string): Promise<void> {
    loading.value = true
    try {
      list.value = await sheetsApi.listSongSheets(songId)
    } finally {
      loading.value = false
    }
  }

  async function upload(songId: string, file: File): Promise<SongSheet> {
    uploading.value = true
    try {
      const sheet = await sheetsApi.uploadSongSheet(songId, file)
      list.value = [sheet, ...list.value]
      return sheet
    } finally {
      uploading.value = false
    }
  }

  async function remove(id: string): Promise<void> {
    await sheetsApi.deleteSongSheet(id)
    list.value = list.value.filter((s) => s.id !== id)
  }

  /** Re-fetch a single sheet to refresh its presigned URL. */
  async function refresh(id: string): Promise<SongSheet> {
    const sheet = await sheetsApi.getSongSheet(id)
    list.value = list.value.map((s) => (s.id === id ? sheet : s))
    return sheet
  }

  function reset(): void {
    list.value = []
  }

  return { list, loading, uploading, fetchList, upload, remove, refresh, reset }
})
