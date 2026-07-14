// StaugPanel — import preview/commit + full-export trigger. The imports API
// module is mocked; the component only orchestrates it and emits `notify`.
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import StaugPanel from '@/components/admin/StaugPanel.vue'
import * as importsApi from '@/api/imports'

vi.mock('@/api/imports', () => ({
  previewStaug: vi.fn(),
  importStaug: vi.fn(),
  requestFullExport: vi.fn(),
}))

function pickFile(w: ReturnType<typeof mount>) {
  const input = w.find('[data-testid="staug-file"]')
  Object.defineProperty(input.element, 'files', {
    value: [new File(['x'], 'library.zip')],
    configurable: true,
  })
  return input.trigger('change')
}

describe('StaugPanel', () => {
  beforeEach(() => {
    vi.mocked(importsApi.previewStaug).mockReset()
    vi.mocked(importsApi.importStaug).mockReset()
    vi.mocked(importsApi.requestFullExport).mockReset()
  })

  it('previews an archive and shows per-action counts', async () => {
    vi.mocked(importsApi.previewStaug).mockResolvedValue({
      type: 'full',
      songs: [
        { id: 'a', title: 'A', action: 'create' },
        { id: 'b', title: 'B', action: 'skip' },
        { id: 'c', title: 'C', action: 'create' },
      ],
      songbooks: { Book: 3 },
    })

    const w = mount(StaugPanel)
    await pickFile(w)
    await flushPromises()

    expect(importsApi.previewStaug).toHaveBeenCalled()
    const preview = w.find('[data-testid="staug-preview"]')
    expect(preview.exists()).toBe(true)
    expect(preview.text()).toContain('2 to create')
    expect(preview.text()).toContain('1 already present')
  })

  it('commits the import and emits a success notify', async () => {
    vi.mocked(importsApi.previewStaug).mockResolvedValue({
      type: 'full',
      songs: [{ id: 'a', title: 'A', action: 'create' }],
      songbooks: {},
    })
    vi.mocked(importsApi.importStaug).mockResolvedValue({
      created: 1, skipped: 0, conflicted: 0, total: 1, songbooks: ['Book'],
    })

    const w = mount(StaugPanel)
    await pickFile(w)
    await flushPromises()

    await w.find('[data-testid="staug-import"]').trigger('click')
    await flushPromises()

    expect(importsApi.importStaug).toHaveBeenCalled()
    expect(w.emitted('notify')?.[0]?.[0]).toMatchObject({ kind: 'success' })
    expect(w.find('[data-testid="staug-result"]').exists()).toBe(true)
  })

  it('queues a full export and emits a success notify', async () => {
    vi.mocked(importsApi.requestFullExport).mockResolvedValue({ message: 'Export queued.', status: 'queued' })

    const w = mount(StaugPanel)
    await w.find('[data-testid="staug-full-export"]').trigger('click')
    await flushPromises()

    expect(importsApi.requestFullExport).toHaveBeenCalled()
    expect(w.emitted('notify')?.[0]?.[0]).toMatchObject({ kind: 'success' })
  })

  it('emits an error notify when preview fails', async () => {
    vi.mocked(importsApi.previewStaug).mockRejectedValue(new Error('bad archive'))

    const w = mount(StaugPanel)
    await pickFile(w)
    await flushPromises()

    expect(w.emitted('notify')?.[0]?.[0]).toMatchObject({ kind: 'error' })
  })
})
