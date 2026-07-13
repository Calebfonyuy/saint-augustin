// CreatePlaylistModal tests — tag chip entry + create flow.
// The playlists store's `create` is stubbed via createTestingPinia; the tags
// store's `ensureLoaded` is a no-op here since we pre-seed suggestions.
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createTestingPinia } from '@pinia/testing'
import { setActivePinia } from 'pinia'
import CreatePlaylistModal from '@/components/CreatePlaylistModal.vue'
import { usePlaylistsStore } from '@/stores/playlists'
import { useTagsStore } from '@/stores/tags'
import type { Playlist } from '@/types'

function fullPlaylist(id: string, name: string, tags: string[] = []): Playlist {
  return {
    id,
    name,
    event_date: null,
    tags,
    created_by: 'user-1',
    item_count: 0,
    created_at: '',
    updated_at: '',
    duplicated_from_id: null,
    items: [],
  }
}

function mountModal() {
  const pinia = createTestingPinia({ stubActions: false, createSpy: vi.fn })
  setActivePinia(pinia)
  const tags = useTagsStore()
  vi.spyOn(tags, 'ensureLoaded').mockResolvedValue()
  vi.spyOn(tags, 'refresh').mockResolvedValue()
  tags.tags = ['advent', 'communion']
  const playlists = usePlaylistsStore()

  const w = mount(CreatePlaylistModal, {
    props: { open: true },
    global: { plugins: [pinia] },
  })
  return { w, playlists, tags }
}

describe('CreatePlaylistModal', () => {
  beforeEach(() => {
    vi.restoreAllMocks()
  })

  it('adds tags as chips and submits them with the new playlist', async () => {
    const { w, playlists } = mountModal()
    const createSpy = vi
      .spyOn(playlists, 'create')
      .mockResolvedValue(fullPlaylist('new-id', 'Sunday', ['advent']))

    await w.find('[data-testid="create-playlist-name"]').setValue('Sunday')

    const tagInput = w.find('[data-testid="create-playlist-tag-input"]')
    await tagInput.setValue('advent')
    await tagInput.trigger('keydown.enter')

    expect(w.findAll('[data-testid="create-playlist-tag-chip"]')).toHaveLength(1)

    await w.find('form').trigger('submit')
    await flushPromises()

    expect(createSpy).toHaveBeenCalledWith({
      name: 'Sunday',
      event_date: null,
      tags: ['advent'],
    })
    expect(w.emitted('created')?.[0]?.[0]).toMatchObject({ id: 'new-id' })
  })

  it('folds a half-typed tag into the payload on submit', async () => {
    const { w, playlists } = mountModal()
    const createSpy = vi
      .spyOn(playlists, 'create')
      .mockResolvedValue(fullPlaylist('new-id', 'Sunday', ['lent']))

    await w.find('[data-testid="create-playlist-name"]').setValue('Sunday')
    // Type a tag but do NOT press Enter — it should still be captured.
    await w.find('[data-testid="create-playlist-tag-input"]').setValue('lent')

    await w.find('form').trigger('submit')
    await flushPromises()

    expect(createSpy).toHaveBeenCalledWith({
      name: 'Sunday',
      event_date: null,
      tags: ['lent'],
    })
  })

  it('shows an error and does not create when the name is blank', async () => {
    const { w, playlists } = mountModal()
    const createSpy = vi.spyOn(playlists, 'create')

    await w.find('form').trigger('submit')
    await flushPromises()

    // onSubmit guards against a blank name, so create is never called and an
    // inline error is shown.
    expect(createSpy).not.toHaveBeenCalled()
    expect(w.find('[data-testid="create-playlist-error"]').exists()).toBe(true)
  })

  it('does not add duplicate tag chips', async () => {
    const { w } = mountModal()
    const tagInput = w.find('[data-testid="create-playlist-tag-input"]')

    await tagInput.setValue('advent')
    await tagInput.trigger('keydown.enter')
    await tagInput.setValue('advent')
    await tagInput.trigger('keydown.enter')

    expect(w.findAll('[data-testid="create-playlist-tag-chip"]')).toHaveLength(1)
  })
})
