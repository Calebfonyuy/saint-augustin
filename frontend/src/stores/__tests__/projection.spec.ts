// Projection store tests. socket.io-client is mocked so we can drive the
// connect / state-event / mutation lifecycle deterministically.
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'

type Listener = (...args: unknown[]) => void

class FakeSocket {
  listeners = new Map<string, Listener[]>()
  emitted: Array<{ event: string; args: unknown[]; ack?: Listener }> = []
  disconnected = false

  on(event: string, fn: Listener): this {
    const arr = this.listeners.get(event) ?? []
    arr.push(fn)
    this.listeners.set(event, arr)
    return this
  }
  once(event: string, fn: Listener): this {
    const wrapper: Listener = (...args) => {
      fn(...args)
      const arr = this.listeners.get(event) ?? []
      this.listeners.set(
        event,
        arr.filter((f) => f !== wrapper),
      )
    }
    return this.on(event, wrapper)
  }
  off(): this {
    return this
  }
  removeAllListeners(): this {
    this.listeners.clear()
    return this
  }
  emit(event: string, ...args: unknown[]): this {
    let ack: Listener | undefined
    if (typeof args[args.length - 1] === 'function') {
      ack = args.pop() as Listener
    }
    this.emitted.push({ event, args, ack })
    return this
  }
  disconnect(): this {
    this.disconnected = true
    return this
  }

  // Test helpers ---------------------------------------------------
  fire(event: string, ...args: unknown[]): void {
    const arr = this.listeners.get(event) ?? []
    for (const fn of [...arr]) fn(...args)
  }
  /** Resolve the most recent emit's ack callback with the given response. */
  ackLast(response: unknown): void {
    const last = this.emitted[this.emitted.length - 1]
    if (last?.ack) last.ack(response)
  }
}

const lastSocket = { current: null as FakeSocket | null }

vi.mock('socket.io-client', () => ({
  io: vi.fn(() => {
    lastSocket.current = new FakeSocket()
    return lastSocket.current
  }),
}))

vi.mock('@/api/projection', () => ({
  createSession: vi.fn(),
  getSession: vi.fn(),
  destroySession: vi.fn(),
  listSessions: vi.fn(),
  startSession: vi.fn(),
  endSession: vi.fn(),
  loadSlides: vi.fn(),
  reclaimSession: vi.fn(),
  takeoverSession: vi.fn(),
}))

import * as projectionApi from '@/api/projection'
import { useProjectionStore } from '@/stores/projection'

function makeState(overrides: Partial<ReturnType<typeof baseState>> = {}) {
  return { ...baseState(), ...overrides }
}

function baseState() {
  return {
    id: 'sess-1',
    name: 'Sunday',
    status: 'LIVE' as const,
    kind: 'TEMPORARY' as const,
    ownerId: null,
    ownerName: null,
    scheduledStartAt: null,
    scheduledEndAt: null,
    startedAt: 'now',
    endedAt: null,
    playlistId: 'pl-1',
    playlistName: 'Sunday',
    slides: [
      { id: 's1', itemIndex: 0, slideIndex: 0, songTitle: 'A', section: null, body: 'a body' },
      { id: 's2', itemIndex: 0, slideIndex: 1, songTitle: 'A', section: null, body: 'b body' },
      { id: 's3', itemIndex: 1, slideIndex: 0, songTitle: 'B', section: null, body: 'c body' },
    ],
    currentIndex: 0,
    blackout: false,
    fontScale: 1,
    createdAt: 'now',
    updatedAt: 'now',
  }
}

describe('useProjectionStore', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    lastSocket.current = null
    vi.clearAllMocks()
    localStorage.clear()
  })

  it('connect resolves with role=display and stores state on join ok', async () => {
    const store = useProjectionStore()
    const promise = store.connect({ sessionId: 'sess-1' })
    // Simulate the socket connecting.
    const sock = lastSocket.current!
    sock.fire('connect')
    expect(sock.emitted[0].event).toBe('join')
    sock.ackLast({ ok: true, role: 'display', state: makeState() })
    const result = await promise
    if (!result.ok) throw new Error('expected ok')
    expect(result.role).toBe('display')
    expect(store.role).toBe('display')
    expect(store.status).toBe('connected')
    expect(store.state?.id).toBe('sess-1')
  })

  it('connect promotes to controller when join responds with role=controller', async () => {
    const store = useProjectionStore()
    const promise = store.connect({ sessionId: 'sess-1', controlToken: 'tok' })
    const sock = lastSocket.current!
    sock.fire('connect')
    sock.ackLast({ ok: true, role: 'controller', state: makeState() })
    const result = await promise
    if (!result.ok) throw new Error('expected ok')
    expect(result.role).toBe('controller')
    expect(store.controlToken).toBe('tok')
  })

  it('connect surfaces a join error', async () => {
    const store = useProjectionStore()
    const promise = store.connect({ sessionId: 'unknown' })
    const sock = lastSocket.current!
    sock.fire('connect')
    sock.ackLast({ ok: false, error: 'unknown_session' })
    const result = await promise
    expect(result.ok).toBe(false)
    expect(store.status).toBe('error')
    expect(store.lastError).toBe('unknown_session')
  })

  it('refuses controller mutations when joined as display', async () => {
    const store = useProjectionStore()
    const promise = store.connect({ sessionId: 'sess-1' })
    const sock = lastSocket.current!
    sock.fire('connect')
    sock.ackLast({ ok: true, role: 'display', state: makeState() })
    await promise
    const r = await store.next()
    expect(r.ok).toBe(false)
    // Should not have emitted a "next" event.
    expect(sock.emitted.find((e) => e.event === 'next')).toBeUndefined()
  })

  it('controller next() emits and resolves with the gateway ack', async () => {
    const store = useProjectionStore()
    const promise = store.connect({ sessionId: 'sess-1', controlToken: 'tok' })
    const sock = lastSocket.current!
    sock.fire('connect')
    sock.ackLast({ ok: true, role: 'controller', state: makeState() })
    await promise
    const next = store.next()
    sock.ackLast({ ok: true })
    const r = await next
    expect(r.ok).toBe(true)
    expect(sock.emitted.find((e) => e.event === 'next')).toBeDefined()
  })

  it('updates state when the server pushes a "state" event', async () => {
    const store = useProjectionStore()
    const promise = store.connect({ sessionId: 'sess-1' })
    const sock = lastSocket.current!
    sock.fire('connect')
    sock.ackLast({ ok: true, role: 'display', state: makeState() })
    await promise
    sock.fire('state', makeState({ currentIndex: 2, blackout: true }))
    expect(store.state?.currentIndex).toBe(2)
    expect(store.state?.blackout).toBe(true)
    expect(store.currentSlide?.id).toBe('s3')
  })

  it('itemGroups groups slides by itemIndex preserving title and first index', async () => {
    const store = useProjectionStore()
    const promise = store.connect({ sessionId: 'sess-1' })
    const sock = lastSocket.current!
    sock.fire('connect')
    sock.ackLast({ ok: true, role: 'display', state: makeState() })
    await promise
    expect(store.itemGroups).toEqual([
      { itemIndex: 0, songTitle: 'A', firstIndex: 0 },
      { itemIndex: 1, songTitle: 'B', firstIndex: 2 },
    ])
  })

  it('createFromPlaylist posts the built slides and connects as controller', async () => {
    vi.mocked(projectionApi.createSession).mockResolvedValueOnce({
      sessionId: 'sess-99',
      controlToken: 'tok-99',
      state: makeState({ id: 'sess-99' }),
    })
    const store = useProjectionStore()
    const playlist = {
      id: 'pl-1',
      name: 'Sunday',
      event_date: null,
      tags: [],
      created_by: null,
      duplicated_from_id: null,
      items: [
        {
          id: 'item-1',
          song_id: 'song-1',
          position: 0,
          target_key: null,
          notes: null,
          song: {
            id: 'song-1',
            title: 'Amazing Grace',
            author: null,
            original_key: null,
            tempo: null,
            time_signature: null,
            lyrics: 'a\n\nb',
          },
        },
      ],
      item_count: 1,
      created_at: '',
      updated_at: '',
    }
    const promise = store.createFromPlaylist(playlist)
    // Drain the connect handshake. createFromPlaylist awaits the slide
    // build (microtask), then the api call (microtask), then opens the
    // socket — flush enough microtasks for all three to settle.
    await Promise.resolve()
    await Promise.resolve()
    await Promise.resolve()
    const sock = lastSocket.current!
    sock.fire('connect')
    sock.ackLast({ ok: true, role: 'controller', state: makeState({ id: 'sess-99' }) })
    const created = await promise
    expect(created.sessionId).toBe('sess-99')
    expect(projectionApi.createSession).toHaveBeenCalledWith(
      expect.objectContaining({ playlistId: 'pl-1', playlistName: 'Sunday' }),
    )
    const call = vi.mocked(projectionApi.createSession).mock.calls[0][0]
    expect(call.slides?.length ?? 0).toBeGreaterThan(0)
    expect(store.role).toBe('controller')
  })

  it('disconnect clears state and stops the socket', async () => {
    const store = useProjectionStore()
    const promise = store.connect({ sessionId: 'sess-1' })
    const sock = lastSocket.current!
    sock.fire('connect')
    sock.ackLast({ ok: true, role: 'display', state: makeState() })
    await promise
    store.disconnect()
    expect(sock.disconnected).toBe(true)
    expect(store.status).toBe('idle')
    expect(store.state).toBeNull()
    expect(store.role).toBeNull()
  })

  describe('control-token persistence', () => {
    it('connecting as controller persists the token to localStorage', async () => {
      const store = useProjectionStore()
      const promise = store.connect({ sessionId: 'sess-1', controlToken: 'tok' })
      const sock = lastSocket.current!
      sock.fire('connect')
      sock.ackLast({ ok: true, role: 'controller', state: makeState() })
      await promise
      expect(localStorage.getItem('sa_proj_control_token:sess-1')).toBe('tok')
    })

    it('connecting as display does not persist a token', async () => {
      const store = useProjectionStore()
      const promise = store.connect({ sessionId: 'sess-1' })
      const sock = lastSocket.current!
      sock.fire('connect')
      sock.ackLast({ ok: true, role: 'display', state: makeState() })
      await promise
      expect(localStorage.getItem('sa_proj_control_token:sess-1')).toBeNull()
    })

    it('disconnect clears the persisted token', async () => {
      const store = useProjectionStore()
      const promise = store.connect({ sessionId: 'sess-1', controlToken: 'tok' })
      const sock = lastSocket.current!
      sock.fire('connect')
      sock.ackLast({ ok: true, role: 'controller', state: makeState() })
      await promise
      store.disconnect()
      expect(localStorage.getItem('sa_proj_control_token:sess-1')).toBeNull()
    })

    it('endById clears the persisted token for that session', async () => {
      localStorage.setItem('sa_proj_control_token:sess-1', 'tok')
      vi.mocked(projectionApi.endSession).mockResolvedValueOnce(makeState())
      const store = useProjectionStore()
      await store.endById('sess-1')
      expect(localStorage.getItem('sa_proj_control_token:sess-1')).toBeNull()
    })

    it('deleteById clears the persisted token for that session', async () => {
      localStorage.setItem('sa_proj_control_token:sess-1', 'tok')
      vi.mocked(projectionApi.destroySession).mockResolvedValueOnce(undefined)
      const store = useProjectionStore()
      await store.deleteById('sess-1')
      expect(localStorage.getItem('sa_proj_control_token:sess-1')).toBeNull()
    })
  })

  describe('tryReclaim', () => {
    it('returns reclaimed=false with no stored token, without calling the API', async () => {
      const store = useProjectionStore()
      const result = await store.tryReclaim('sess-1')
      expect(result).toEqual({ reclaimed: false })
      expect(projectionApi.reclaimSession).not.toHaveBeenCalled()
    })

    it('reclaims and connects as controller when the stored token is valid', async () => {
      localStorage.setItem('sa_proj_control_token:sess-1', 'tok')
      vi.mocked(projectionApi.reclaimSession).mockResolvedValueOnce({
        sessionId: 'sess-1',
        controlToken: 'tok',
        state: makeState(),
      })
      const store = useProjectionStore()
      const promise = store.tryReclaim('sess-1')
      // Drain the reclaimSession call + connect() handshake.
      await Promise.resolve()
      await Promise.resolve()
      const sock = lastSocket.current!
      sock.fire('connect')
      sock.ackLast({ ok: true, role: 'controller', state: makeState() })
      const result = await promise
      expect(result).toEqual({ reclaimed: true })
      expect(store.role).toBe('controller')
    })

    it('clears storage and returns reclaimed=false when the API rejects the token', async () => {
      localStorage.setItem('sa_proj_control_token:sess-1', 'stale')
      vi.mocked(projectionApi.reclaimSession).mockRejectedValueOnce(new Error('forbidden'))
      const store = useProjectionStore()
      const result = await store.tryReclaim('sess-1')
      expect(result).toEqual({ reclaimed: false })
      expect(localStorage.getItem('sa_proj_control_token:sess-1')).toBeNull()
    })
  })

  describe('control-transferred', () => {
    it('demotes a controller to display, clears storage, and sets takenOver', async () => {
      const store = useProjectionStore()
      const promise = store.connect({ sessionId: 'sess-1', controlToken: 'tok' })
      const sock = lastSocket.current!
      sock.fire('connect')
      sock.ackLast({ ok: true, role: 'controller', state: makeState() })
      await promise
      expect(store.role).toBe('controller')

      sock.fire('control-transferred', { byName: 'Admin' })

      expect(store.role).toBe('display')
      expect(store.controlToken).toBeNull()
      expect(store.takenOver).toBe(true)
      expect(localStorage.getItem('sa_proj_control_token:sess-1')).toBeNull()
    })

    it('is a no-op for a socket that was already a display', async () => {
      const store = useProjectionStore()
      const promise = store.connect({ sessionId: 'sess-1' })
      const sock = lastSocket.current!
      sock.fire('connect')
      sock.ackLast({ ok: true, role: 'display', state: makeState() })
      await promise

      sock.fire('control-transferred', { byName: 'Admin' })

      expect(store.role).toBe('display')
      expect(store.takenOver).toBe(false)
    })

    it('dismissTakenOver resets the flag', async () => {
      const store = useProjectionStore()
      const promise = store.connect({ sessionId: 'sess-1', controlToken: 'tok' })
      const sock = lastSocket.current!
      sock.fire('connect')
      sock.ackLast({ ok: true, role: 'controller', state: makeState() })
      await promise
      sock.fire('control-transferred', {})
      expect(store.takenOver).toBe(true)
      store.dismissTakenOver()
      expect(store.takenOver).toBe(false)
    })
  })

  describe('takeover', () => {
    it('calls the API and connects with the rotated token', async () => {
      vi.mocked(projectionApi.takeoverSession).mockResolvedValueOnce({
        sessionId: 'sess-1',
        controlToken: 'new-tok',
        state: makeState(),
      })
      const store = useProjectionStore()
      const promise = store.takeover('sess-1')
      await Promise.resolve()
      const sock = lastSocket.current!
      sock.fire('connect')
      sock.ackLast({ ok: true, role: 'controller', state: makeState() })
      await promise
      expect(projectionApi.takeoverSession).toHaveBeenCalledWith('sess-1')
      expect(store.role).toBe('controller')
      expect(store.controlToken).toBe('new-tok')
    })
  })
})
