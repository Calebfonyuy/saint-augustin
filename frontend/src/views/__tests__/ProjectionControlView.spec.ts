// ProjectionControlView integration tests.
//
// The projection store's socket.io dependency is bypassed by using
// createTestingPinia with stubActions:true — all store actions (including
// connect / next / previous) become vi.fn() stubs. We manually assign the
// store's reactive state to simulate the "already connected as controller"
// scenario that's the normal entry point (Go Live → router.push here).
//
// Coverage: layout correctness, keyboard shortcut dispatch, toolbar buttons,
// jump-to-song, view-only mode restrictions.
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createTestingPinia } from '@pinia/testing'
import { setActivePinia } from 'pinia'
import ProjectionControlView from '@/views/ProjectionControlView.vue'
import { useProjectionStore } from '@/stores/projection'
import type { ProjectionSessionState } from '@/types'

// ── Fixtures ────────────────────────────────────────────────────────────────

function makeState(over: Partial<ProjectionSessionState> = {}): ProjectionSessionState {
  return {
    id: 'sess-1',
    playlistId: 'pl-1',
    playlistName: 'Sunday Service',
    slides: [
      { id: 's0', itemIndex: 0, slideIndex: 0, songTitle: 'Amazing Grace', section: 'Verse 1', body: 'Amazing grace' },
      { id: 's1', itemIndex: 0, slideIndex: 1, songTitle: 'Amazing Grace', section: 'Chorus 1', body: 'How sweet the sound' },
      { id: 's2', itemIndex: 1, slideIndex: 0, songTitle: 'How Great Thou Art', section: null, body: 'O Lord my God' },
    ],
    currentIndex: 0,
    blackout: false,
    fontScale: 1,
    createdAt: '2026-05-01T00:00:00Z',
    updatedAt: '2026-05-01T00:00:00Z',
    ...over,
  }
}

function makeRouter() {
  return createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/projection/control/:id', component: ProjectionControlView, name: 'projection-control' },
      { path: '/projection/display/:id', component: { template: '<div />' }, name: 'projection-display' },
      { path: '/playlists', component: { template: '<div />' }, name: 'playlists' },
    ],
  })
}

async function mountView(stateOverrides: Partial<ProjectionSessionState> = {}, role: 'controller' | 'display' | null = 'controller') {
  const router = makeRouter()
  await router.push('/projection/control/sess-1')
  await router.isReady()

  const pinia = createTestingPinia({ stubActions: true, createSpy: vi.fn })
  setActivePinia(pinia)

  // Pre-populate store to simulate "already connected" state.
  const projection = useProjectionStore()
  projection.role = role
  projection.status = 'connected'
  projection.sessionId = 'sess-1'
  projection.controlToken = 'ctrl-tok'
  projection.state = makeState(stateOverrides)

  // connect() is stubbed; return a valid JoinResult so `result.ok` never throws.
  // This also handles the display-role path where alreadyConnected is false.
  vi.mocked(projection.connect).mockResolvedValue({
    ok: true,
    role: role ?? 'display',
    state: makeState(stateOverrides),
  })

  const w = mount(ProjectionControlView, {
    global: {
      plugins: [router, pinia],
      stubs: {
        AppShell: { template: '<div><slot /></div>' },
        SlideRenderer: { template: '<div class="slide-renderer-stub" />' },
        Icon: { template: '<span />' },
        Toast: { template: '<div />' },
      },
    },
  })
  await flushPromises()
  return { w, projection }
}

// ── Tests ────────────────────────────────────────────────────────────────────

describe('ProjectionControlView', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  // ── Layout ──────────────────────────────────────────────────────────────────

  it('shows the playlist name from session state', async () => {
    const { w } = await mountView()
    expect(w.find('[data-testid="ctrl-playlist-name"]').text()).toBe('Sunday Service')
  })

  it('renders a jump button for each unique song in the service order', async () => {
    const { w } = await mountView()
    const buttons = w.findAll('[data-testid="jump-item"]')
    // Slides contain 2 unique songs (itemIndex 0 and 1).
    expect(buttons).toHaveLength(2)
    expect(buttons[0].text()).toContain('Amazing Grace')
    expect(buttons[1].text()).toContain('How Great Thou Art')
  })

  it('highlights the jump button for the currently active song', async () => {
    const { w } = await mountView({ currentIndex: 0 })
    const buttons = w.findAll('[data-testid="jump-item"]')
    // First song active — should carry the accent class.
    expect(buttons[0].classes().join(' ')).toContain('bg-accent-soft')
    expect(buttons[1].classes().join(' ')).not.toContain('bg-accent-soft')
  })

  it('highlights the second song when currentIndex points into it', async () => {
    const { w } = await mountView({ currentIndex: 2 })
    const buttons = w.findAll('[data-testid="jump-item"]')
    expect(buttons[0].classes().join(' ')).not.toContain('bg-accent-soft')
    expect(buttons[1].classes().join(' ')).toContain('bg-accent-soft')
  })

  it('shows the slide position counter', async () => {
    const { w } = await mountView({ currentIndex: 1 })
    // currentIndex 1 → "2 / 3"
    expect(w.find('[data-testid="ctrl-slide-count"]').text()).toContain('2')
    expect(w.find('[data-testid="ctrl-slide-count"]').text()).toContain('3')
  })

  it('shows the font scale as a percentage', async () => {
    const { w } = await mountView({ fontScale: 1.5 })
    expect(w.find('[data-testid="ctrl-font-pct"]').text()).toBe('150%')
  })

  it('shows the display URL in the readonly input', async () => {
    const { w } = await mountView()
    const input = w.find('[data-testid="projection-display-url"]')
    expect(input.attributes('value')).toContain('/projection/display/sess-1')
  })

  it('shows End Session button when controller', async () => {
    const { w } = await mountView()
    expect(w.find('[data-testid="projection-end"]').exists()).toBe(true)
  })

  // ── View-only mode ──────────────────────────────────────────────────────────

  it('shows the view-only banner when joined as display', async () => {
    const { w } = await mountView({}, 'display')
    expect(w.find('[data-testid="ctrl-viewonly-banner"]').exists()).toBe(true)
  })

  it('hides the view-only banner when joined as controller', async () => {
    const { w } = await mountView()
    expect(w.find('[data-testid="ctrl-viewonly-banner"]').exists()).toBe(false)
  })

  it('hides the End Session button in view-only mode', async () => {
    const { w } = await mountView({}, 'display')
    expect(w.find('[data-testid="projection-end"]').exists()).toBe(false)
  })

  it('disables the toolbar buttons in view-only mode', async () => {
    const { w } = await mountView({}, 'display')
    expect((w.find('[data-testid="ctrl-next"]').element as HTMLButtonElement).disabled).toBe(true)
    expect((w.find('[data-testid="ctrl-prev"]').element as HTMLButtonElement).disabled).toBe(true)
    expect((w.find('[data-testid="ctrl-blackout"]').element as HTMLButtonElement).disabled).toBe(true)
  })

  it('disables the jump buttons in view-only mode', async () => {
    const { w } = await mountView({}, 'display')
    for (const btn of w.findAll('[data-testid="jump-item"]')) {
      expect((btn.element as HTMLButtonElement).disabled).toBe(true)
    }
  })

  // ── Toolbar button clicks ───────────────────────────────────────────────────

  it('Prev button calls projection.previous()', async () => {
    const { w, projection } = await mountView()
    await w.find('[data-testid="ctrl-prev"]').trigger('click')
    expect(projection.previous).toHaveBeenCalledOnce()
  })

  it('Next button calls projection.next()', async () => {
    const { w, projection } = await mountView()
    await w.find('[data-testid="ctrl-next"]').trigger('click')
    expect(projection.next).toHaveBeenCalledOnce()
  })

  it('Blackout button calls setBlackout(true) when blackout is off', async () => {
    const { w, projection } = await mountView({ blackout: false })
    await w.find('[data-testid="ctrl-blackout"]').trigger('click')
    expect(projection.setBlackout).toHaveBeenCalledWith(true)
  })

  it('Blackout button calls setBlackout(false) when blackout is on', async () => {
    const { w, projection } = await mountView({ blackout: true })
    await w.find('[data-testid="ctrl-blackout"]').trigger('click')
    expect(projection.setBlackout).toHaveBeenCalledWith(false)
  })

  it('shows "Blackout ON" label when blackout is active', async () => {
    const { w } = await mountView({ blackout: true })
    expect(w.find('[data-testid="ctrl-blackout"]').text()).toBe('Blackout ON')
  })

  it('Font down button calls setFontScale with a lower value', async () => {
    const { w, projection } = await mountView({ fontScale: 1.0 })
    await w.find('[data-testid="ctrl-font-down"]').trigger('click')
    expect(projection.setFontScale).toHaveBeenCalledWith(expect.closeTo(0.9, 5))
  })

  it('Font up button calls setFontScale with a higher value', async () => {
    const { w, projection } = await mountView({ fontScale: 1.0 })
    await w.find('[data-testid="ctrl-font-up"]').trigger('click')
    expect(projection.setFontScale).toHaveBeenCalledWith(expect.closeTo(1.1, 5))
  })

  it('Font down clamps at 0.5', async () => {
    const { w, projection } = await mountView({ fontScale: 0.5 })
    await w.find('[data-testid="ctrl-font-down"]').trigger('click')
    expect(projection.setFontScale).toHaveBeenCalledWith(0.5)
  })

  it('Font up clamps at 3', async () => {
    const { w, projection } = await mountView({ fontScale: 3 })
    await w.find('[data-testid="ctrl-font-up"]').trigger('click')
    expect(projection.setFontScale).toHaveBeenCalledWith(3)
  })

  it('Jump button calls jumpToItem with the correct index', async () => {
    const { w, projection } = await mountView()
    const buttons = w.findAll('[data-testid="jump-item"]')
    await buttons[1].trigger('click')
    expect(projection.jumpToItem).toHaveBeenCalledWith(1)
  })

  // ── Keyboard shortcuts ──────────────────────────────────────────────────────
  // The handler is added to `window`, so dispatch events there directly —
  // w.trigger() fires on the component root which may not bubble to window
  // in a detached JSDOM tree.

  function key(k: string): void {
    window.dispatchEvent(new KeyboardEvent('keydown', { key: k, bubbles: true }))
  }

  it('ArrowRight key calls projection.next()', async () => {
    const { projection } = await mountView()
    key('ArrowRight')
    expect(projection.next).toHaveBeenCalledOnce()
  })

  it('Space key calls projection.next()', async () => {
    const { projection } = await mountView()
    key(' ')
    expect(projection.next).toHaveBeenCalledOnce()
  })

  it('PageDown key calls projection.next()', async () => {
    const { projection } = await mountView()
    key('PageDown')
    expect(projection.next).toHaveBeenCalledOnce()
  })

  it('ArrowLeft key calls projection.previous()', async () => {
    const { projection } = await mountView()
    key('ArrowLeft')
    expect(projection.previous).toHaveBeenCalledOnce()
  })

  it('PageUp key calls projection.previous()', async () => {
    const { projection } = await mountView()
    key('PageUp')
    expect(projection.previous).toHaveBeenCalledOnce()
  })

  it('B key calls setBlackout toggling the current blackout state', async () => {
    const { projection } = await mountView({ blackout: false })
    key('B')
    expect(projection.setBlackout).toHaveBeenCalledWith(true)
  })

  it('+ key calls setFontScale with an increased value', async () => {
    const { projection } = await mountView({ fontScale: 1.0 })
    key('+')
    expect(projection.setFontScale).toHaveBeenCalledWith(expect.closeTo(1.1, 5))
  })

  it('- key calls setFontScale with a decreased value', async () => {
    const { projection } = await mountView({ fontScale: 1.0 })
    key('-')
    expect(projection.setFontScale).toHaveBeenCalledWith(expect.closeTo(0.9, 5))
  })

  it('keyboard shortcuts are no-ops when joined as display', async () => {
    const { projection } = await mountView({}, 'display')
    key('ArrowRight')
    expect(projection.next).not.toHaveBeenCalled()
  })

  // ── End session ─────────────────────────────────────────────────────────────

  it('End Session calls destroy() and navigates away after confirmation', async () => {
    vi.spyOn(window, 'confirm').mockReturnValue(true)
    const { w, projection } = await mountView()
    await w.find('[data-testid="projection-end"]').trigger('click')
    await flushPromises()
    expect(projection.destroy).toHaveBeenCalledOnce()
  })

  it('End Session does nothing when the user cancels the confirmation', async () => {
    vi.spyOn(window, 'confirm').mockReturnValue(false)
    const { w, projection } = await mountView()
    await w.find('[data-testid="projection-end"]').trigger('click')
    await flushPromises()
    expect(projection.destroy).not.toHaveBeenCalled()
  })
})
