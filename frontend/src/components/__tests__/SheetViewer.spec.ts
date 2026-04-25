import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import SheetViewer from '@/components/SheetViewer.vue'
import type { SongSheet } from '@/types'

function pdf(): SongSheet {
  return {
    id: 's1',
    song_id: 'song-1',
    original_filename: 'choir.pdf',
    file_type: 'pdf',
    mime_type: 'application/pdf',
    size_bytes: 1000,
    uploaded_by: null,
    url: 'https://example.test/choir.pdf',
    url_expires_at: null,
    created_at: '',
    updated_at: '',
  }
}

function image(): SongSheet {
  return { ...pdf(), id: 's2', file_type: 'image', mime_type: 'image/png', original_filename: 'scan.png', url: 'https://example.test/scan.png' }
}

describe('SheetViewer', () => {
  it('renders a PDF as an iframe with the toolbar suppressed', () => {
    const w = mount(SheetViewer, { props: { sheet: pdf() } })
    const iframe = w.find('[data-testid="sheet-pdf"]')
    expect(iframe.exists()).toBe(true)
    expect(iframe.attributes('src')).toBe('https://example.test/choir.pdf#toolbar=0&navpanes=0')
  })

  it('renders an image as <img>', () => {
    const w = mount(SheetViewer, { props: { sheet: image() } })
    expect(w.find('[data-testid="sheet-image"]').exists()).toBe(true)
    expect(w.find('[data-testid="sheet-pdf"]').exists()).toBe(false)
  })
})
