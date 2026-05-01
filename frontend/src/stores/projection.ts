// Projection store — owns the live SessionState and the socket lifecycle.
//
// Two roles share this store:
//   • controller (worship leader's tablet): creates the session, drives
//     navigation, holds the controlToken, sees both current + next slide.
//   • display    (projector / second screen): connects in display mode,
//     listens for state events, never mutates.
//
// Reconnection is intentionally simple: the server snapshots the full
// SessionState on every change, so on every (re)connect we just emit
// `join` and apply whatever state comes back. There is no replay log —
// the projector only needs to know what's on screen *now*.
//
// Ref: services/projection/src/projection/projection.gateway.ts
import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { io, type Socket } from 'socket.io-client'
import * as projectionApi from '@/api/projection'
import { buildSlidesForPlaylist } from '@/lib/projection'
import type {
  CreateProjectionSessionResponse,
  Playlist,
  ProjectionSessionState,
} from '@/types'

export type ProjectionRole = 'controller' | 'display'
export type ProjectionStatus = 'idle' | 'connecting' | 'connected' | 'error'

interface JoinResultOk {
  ok: true
  role: ProjectionRole
  state: ProjectionSessionState
}
interface JoinResultErr {
  ok: false
  error: string
}
type JoinResult = JoinResultOk | JoinResultErr

interface MutationResult {
  ok: boolean
  error?: string
}

/** Resolve the gateway socket URL. Falls back to localhost in dev. */
function resolveSocketUrl(): string {
  const wsUrl = import.meta.env.VITE_WS_URL
  if (wsUrl) {
    // socket.io-client accepts both ws:// and http:// — prefer http:// so
    // the polling transport works during the upgrade dance.
    return wsUrl.replace(/^ws:/, 'http:').replace(/^wss:/, 'https:')
  }
  return 'http://localhost:8080'
}

export const useProjectionStore = defineStore('projection', () => {
  const status = ref<ProjectionStatus>('idle')
  const role = ref<ProjectionRole | null>(null)
  const state = ref<ProjectionSessionState | null>(null)
  const sessionId = ref<string | null>(null)
  /** Held only by the controller — display joins never see this. */
  const controlToken = ref<string | null>(null)
  const lastError = ref<string | null>(null)

  let socket: Socket | null = null

  const currentSlide = computed(() =>
    state.value ? state.value.slides[state.value.currentIndex] ?? null : null,
  )
  const nextSlide = computed(() => {
    const s = state.value
    if (!s) return null
    return s.slides[s.currentIndex + 1] ?? null
  })

  /** Group slides by their playlist item — the controller's jump menu. */
  const itemGroups = computed(() => {
    const slides = state.value?.slides ?? []
    const groups = new Map<number, { itemIndex: number; songTitle: string; firstIndex: number }>()
    slides.forEach((s, i) => {
      if (!groups.has(s.itemIndex)) {
        groups.set(s.itemIndex, {
          itemIndex: s.itemIndex,
          songTitle: s.songTitle,
          firstIndex: i,
        })
      }
    })
    return Array.from(groups.values()).sort((a, b) => a.itemIndex - b.itemIndex)
  })

  // ── Connection lifecycle ──────────────────────────────────────────

  function teardown(): void {
    if (socket) {
      socket.removeAllListeners()
      socket.disconnect()
      socket = null
    }
  }

  function attachListeners(): void {
    if (!socket) return
    socket.on('state', (payload: ProjectionSessionState) => {
      state.value = payload
    })
    socket.on('disconnect', () => {
      // Mark as connecting until the io reconnect loop succeeds. The
      // server holds the canonical state in Redis, so a successful
      // re-join will refresh `state.value`.
      status.value = 'connecting'
    })
    socket.on('connect_error', (err: Error) => {
      lastError.value = err.message
      status.value = 'error'
    })
  }

  /**
   * Connect (or reconnect) and authenticate against the given session.
   * If `token` is provided AND matches, the server promotes us to
   * controller. Otherwise we connect as a display.
   */
  async function connect(args: {
    sessionId: string
    controlToken?: string
  }): Promise<JoinResult> {
    teardown()
    status.value = 'connecting'
    lastError.value = null
    sessionId.value = args.sessionId
    controlToken.value = args.controlToken ?? null

    socket = io(`${resolveSocketUrl()}/ws/projection`, {
      transports: ['websocket', 'polling'],
      reconnection: true,
      reconnectionAttempts: Infinity,
      reconnectionDelay: 500,
      reconnectionDelayMax: 4000,
    })
    attachListeners()

    return new Promise<JoinResult>((resolve) => {
      const finalize = (res: JoinResult): void => {
        if (res.ok) {
          status.value = 'connected'
          role.value = res.role
          state.value = res.state
        } else {
          status.value = 'error'
          lastError.value = res.error
        }
        resolve(res)
      }

      socket!.on('connect', () => {
        socket!.emit(
          'join',
          { sessionId: args.sessionId, controlToken: args.controlToken },
          (response: JoinResult) => finalize(response),
        )
      })

      socket!.once('connect_error', (err: Error) => {
        finalize({ ok: false, error: err.message })
      })
    })
  }

  function disconnect(): void {
    teardown()
    status.value = 'idle'
    role.value = null
    state.value = null
    sessionId.value = null
    controlToken.value = null
  }

  // ── Controller actions (no-op when role !== 'controller') ─────────

  async function emitMutation(event: string, args: unknown = {}): Promise<MutationResult> {
    if (!socket || role.value !== 'controller') {
      return { ok: false, error: 'forbidden' }
    }
    return new Promise<MutationResult>((resolve) => {
      socket!.emit(event, args, (resp: MutationResult) => resolve(resp ?? { ok: false }))
    })
  }

  async function next(): Promise<MutationResult> {
    return emitMutation('next')
  }
  async function previous(): Promise<MutationResult> {
    return emitMutation('previous')
  }
  async function goto(index: number): Promise<MutationResult> {
    return emitMutation('goto', { index })
  }
  async function jumpToItem(itemIndex: number): Promise<MutationResult> {
    return emitMutation('jump-to-song', { itemIndex })
  }
  async function setBlackout(on: boolean): Promise<MutationResult> {
    return emitMutation('blackout', { on })
  }
  async function setFontScale(scale: number): Promise<MutationResult> {
    return emitMutation('font-scale', { scale })
  }

  // ── Session creation (controller-only entry point) ────────────────

  /**
   * Create a fresh session from a hydrated playlist and connect as
   * controller. Returns the createdResponse so callers can stash the
   * controlToken / sessionId for routing or sharing the display URL.
   */
  async function createFromPlaylist(playlist: Playlist): Promise<CreateProjectionSessionResponse> {
    const slides = buildSlidesForPlaylist(playlist)
    const created = await projectionApi.createSession({
      playlistName: playlist.name,
      playlistId: playlist.id,
      slides,
    })
    await connect({ sessionId: created.sessionId, controlToken: created.controlToken })
    return created
  }

  async function destroy(): Promise<void> {
    if (!sessionId.value || !controlToken.value) return
    await projectionApi.destroySession(sessionId.value, controlToken.value)
    disconnect()
  }

  return {
    // state
    status,
    role,
    state,
    sessionId,
    controlToken,
    lastError,
    // getters
    currentSlide,
    nextSlide,
    itemGroups,
    // actions
    connect,
    disconnect,
    next,
    previous,
    goto,
    jumpToItem,
    setBlackout,
    setFontScale,
    createFromPlaylist,
    destroy,
  }
})
