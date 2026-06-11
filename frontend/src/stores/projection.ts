// Projection store — owns the live SessionState and the socket lifecycle.
//
// Two roles share this store:
//   • controller (worship leader's tablet): creates / starts the session,
//     drives navigation, holds the controlToken, sees both current + next
//     slide.
//   • display    (projector / second screen): connects in display mode,
//     listens for state events, never mutates.
//
// Reconnection is intentionally simple: the server snapshots the full
// SessionState on every change, so on every (re)connect we just emit
// `join` and apply whatever state comes back. There is no replay log —
// the projector only needs to know what's on screen *now*.
//
// Persistent sessions:
//   Sessions can be created in NOT_STARTED state and started later. The
//   store exposes `createPersistent`, `startPersistent` and `endById` to
//   manage their lifecycle without the socket dance, plus the existing
//   `createFromPlaylist` / `createFromSong` for the legacy temporary flow.
//
// Ref: services/projection/src/projection/projection.gateway.ts
import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { io, type Socket } from 'socket.io-client'
import * as projectionApi from '@/api/projection'
import { buildSlidesForPlaylist } from '@/lib/projection'
import type {
  CreateProjectionSessionInput,
  CreateProjectionSessionResponse,
  Playlist,
  ProjectionSessionState,
  ProjectionSessionSummary,
  Song,
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
  const wsUrl = import.meta.env.VITE_PROJECTION_BASE_URL
  if (wsUrl) {
    // socket.io-client accepts both ws:// and http:// — prefer http:// so
    // the polling transport works during the upgrade dance.
    return wsUrl.replace(/^ws:/, 'http:').replace(/^wss:/, 'https:')
  }
  console.log('WS URL NOT CONFIGURED, FALLING BACK TO LOCALHOST')
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

  /** Cached summaries for the SessionsListView. */
  const sessions = ref<ProjectionSessionSummary[]>([])
  const sessionsLoading = ref(false)

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

      if (socket !== null) {
        socket.on('connect', () => {
          socket!.emit(
            'join',
            { sessionId: args.sessionId, controlToken: args.controlToken },
            (response: JoinResult) => finalize(response),
          )
        })

        socket.once('connect_error', (err: Error) => {
          finalize({ ok: false, error: err.message })
        })
      }
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
      if (socket) {
        socket.emit(event, args, (resp: MutationResult) => resolve(resp ?? { ok: false }))
      }
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

  // ── Session creation (controller-only entry points) ───────────────

  /**
   * Create a fresh TEMPORARY session from a hydrated playlist and connect
   * as controller. Returns the response so callers can stash the
   * controlToken / sessionId for routing or sharing the display URL.
   */
  async function createFromPlaylist(playlist: Playlist): Promise<CreateProjectionSessionResponse> {
    const slides = await buildSlidesForPlaylist(playlist)
    const created = await projectionApi.createSession({
      kind: 'TEMPORARY',
      playlistName: playlist.name,
      playlistId: playlist.id,
      slides,
    })
    if (created.controlToken) {
      await connect({ sessionId: created.sessionId, controlToken: created.controlToken })
    }
    return created
  }

  /**
   * Project a single song without first creating a persisted playlist.
   * Synthesises an ephemeral one-item playlist client-side so we can re-use
   * the same slide pipeline (chord stripping, stanza splitting). The session
   * is created without a playlistId so the projection service has no
   * dangling reference back to a real row.
   */
  async function createFromSong(song: Song): Promise<CreateProjectionSessionResponse> {
    const ephemeral = ephemeralPlaylistFromSong(song)
    const slides = await buildSlidesForPlaylist(ephemeral)
    const created = await projectionApi.createSession({
      kind: 'TEMPORARY',
      playlistName: song.title,
      slides,
    })
    if (created.controlToken) {
      await connect({ sessionId: created.sessionId, controlToken: created.controlToken })
    }
    return created
  }

  /**
   * Create a PERSISTENT session (NOT_STARTED). Does NOT connect — the
   * session has no controlToken until it's started.
   */
  async function createPersistent(
    input: Omit<CreateProjectionSessionInput, 'kind'> & { name: string },
  ): Promise<CreateProjectionSessionResponse> {
    return projectionApi.createSession({ ...input, kind: 'PERSISTENT' })
  }

  /**
   * Start an existing PERSISTENT session: NOT_STARTED → LIVE. Returns the
   * new control token and connects this client as controller.
   */
  async function startPersistent(
    id: string,
  ): Promise<CreateProjectionSessionResponse> {
    const started = await projectionApi.startSession(id)
    if (started.controlToken) {
      await connect({ sessionId: started.sessionId, controlToken: started.controlToken })
    }
    return started
  }

  /**
   * Load (or replace) the slide deck on a session the user owns or admins.
   * Used by:
   *   • "Project to existing live session" — the leader picks a LIVE session
   *     created by someone else (admin) or themselves and pushes a new deck.
   *   • Persistent session prep — load slides into a NOT_STARTED session
   *     before starting it.
   */
  async function loadPlaylistInto(
    id: string,
    playlist: Playlist,
  ): Promise<ProjectionSessionState> {
    const slides = await buildSlidesForPlaylist(playlist)
    return projectionApi.loadSlides(id, {
      playlistName: playlist.name,
      playlistId: playlist.id,
      slides,
    })
  }

  async function loadSongInto(
    id: string,
    song: Song,
  ): Promise<ProjectionSessionState> {
    const ephemeral = ephemeralPlaylistFromSong(song)
    const slides = await buildSlidesForPlaylist(ephemeral)
    return projectionApi.loadSlides(id, {
      playlistName: song.title,
      slides,
    })
  }

  /** End the in-memory session if one is active. */
  async function destroy(): Promise<void> {
    if (!sessionId.value) return
    if (controlToken.value) {
      try {
        await projectionApi.endSession(sessionId.value)
      } catch {
        // best-effort — the user clicked End and we want the UI to drop the session
      }
    }
    disconnect()
  }

  /** End any session by id (owner or admin). */
  async function endById(id: string): Promise<void> {
    await projectionApi.endSession(id)
    if (sessionId.value === id) disconnect()
  }

  /** Delete any session by id (owner or admin). */
  async function deleteById(id: string): Promise<void> {
    await projectionApi.destroySession(id)
    if (sessionId.value === id) disconnect()
  }

  // ── Listing ───────────────────────────────────────────────────────

  async function fetchSessions(opts: { mine?: boolean } = {}): Promise<void> {
    sessionsLoading.value = true
    try {
      sessions.value = await projectionApi.listSessions(opts)
    } finally {
      sessionsLoading.value = false
    }
  }

  return {
    // state
    status,
    role,
    state,
    sessionId,
    controlToken,
    lastError,
    sessions,
    sessionsLoading,
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
    createFromSong,
    createPersistent,
    startPersistent,
    loadPlaylistInto,
    loadSongInto,
    destroy,
    endById,
    deleteById,
    fetchSessions,
  }
})

/** Build an in-memory single-item Playlist around a Song so we can re-use
 *  the playlist→slides pipeline for one-off song projection. */
function ephemeralPlaylistFromSong(song: Song): Playlist {
  return {
    id: '',
    name: song.title,
    event_date: null,
    tags: [],
    created_by: null,
    duplicated_from_id: null,
    item_count: 1,
    items: [
      {
        id: 'ephemeral-item',
        song_id: song.id,
        position: 0,
        target_key: null,
        notes: null,
        song: {
          id: song.id,
          title: song.title,
          author: song.author,
          original_key: song.original_key,
          tempo: song.tempo,
          time_signature: song.time_signature,
          lyrics: song.lyrics,
        },
      },
    ],
    created_at: '',
    updated_at: '',
  }
}
