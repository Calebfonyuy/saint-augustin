// Songbook store — flat list; cheap to keep in memory (dozens, not thousands).
import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import * as songbooksApi from '@/api/songbooks'
import type { Songbook } from '@/types'

export const useSongbooksStore = defineStore('songbooks', () => {
  const list = ref<Songbook[]>([])
  const loading = ref(false)

  const defaultSongbook = computed(() => list.value.find((sb) => sb.is_default) ?? null)

  async function fetchList(): Promise<void> {
    loading.value = true
    try {
      list.value = await songbooksApi.listSongbooks()
    } finally {
      loading.value = false
    }
  }

  async function create(input: songbooksApi.SongbookInput): Promise<Songbook> {
    const sb = await songbooksApi.createSongbook(input)
    list.value = [...list.value, sb].sort((a, b) => {
      if (a.is_default !== b.is_default) return a.is_default ? -1 : 1
      return a.name.localeCompare(b.name)
    })
    return sb
  }

  async function update(id: string, input: Partial<songbooksApi.SongbookInput>): Promise<Songbook> {
    const sb = await songbooksApi.updateSongbook(id, input)
    list.value = list.value.map((s) => (s.id === id ? sb : s))
    return sb
  }

  async function remove(id: string): Promise<void> {
    await songbooksApi.deleteSongbook(id)
    list.value = list.value.filter((s) => s.id !== id)
  }

  return { list, loading, defaultSongbook, fetchList, create, update, remove }
})
