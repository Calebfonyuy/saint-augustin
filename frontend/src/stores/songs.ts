// Song library store — list + current selection + mutations.
// Keeps pagination meta alongside the list so views don't need to re-plumb it.
import { defineStore } from 'pinia'
import { ref } from 'vue'
import * as songsApi from '@/api/songs'
import type { Paginated, Song, SongInput, SongListQuery } from '@/types'

export const useSongsStore = defineStore('songs', () => {
  const list = ref<Song[]>([])
  const meta = ref<Paginated<Song>['meta'] | null>(null)
  const loading = ref(false)
  const current = ref<Song | null>(null)

  async function fetchList(query: SongListQuery = {}): Promise<void> {
    loading.value = true
    try {
      const res = await songsApi.listSongs(query)
      list.value = res.data
      meta.value = res.meta
    } finally {
      loading.value = false
    }
  }

  async function fetchOne(id: string): Promise<Song> {
    const song = await songsApi.getSong(id)
    current.value = song
    return song
  }

  async function create(input: SongInput): Promise<Song> {
    const song = await songsApi.createSong(input)
    list.value = [song, ...list.value]
    current.value = song
    return song
  }

  async function update(id: string, input: Partial<SongInput>): Promise<Song> {
    const song = await songsApi.updateSong(id, input)
    list.value = list.value.map((s) => (s.id === id ? song : s))
    if (current.value?.id === id) current.value = song
    return song
  }

  async function remove(id: string): Promise<void> {
    await songsApi.deleteSong(id)
    list.value = list.value.filter((s) => s.id !== id)
    if (current.value?.id === id) current.value = null
  }

  return { list, meta, loading, current, fetchList, fetchOne, create, update, remove }
})
