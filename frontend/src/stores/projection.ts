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
// Control-token continuity:
//   The control token is persisted per-session in localStorage (see the
//   helpers below) so `tryReclaim()` can silently regain controller status
//   after a reload. `takeover()` lets an owner/admin forcibly reclaim
//   control from another browser; the deposed client learns about it via
//   the `control-transferred` socket event, which flips `role` to
//   'display' and sets `takenOver` for the UI to surface a notice.
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
  const wsUrl = window.config?.VITE_PROJECTION_BASE_URL;
  if (wsUrl) {
    // socket.io-client accepts both ws:// and http:// — prefer http:// so
    // the polling transport works during the upgrade dance.
    return wsUrl.replace(/^ws:/, 'http:').replace(/^wss:/, 'https:')
  }
  return 'http://localhost:8080'
}

// ── Control-token persistence ────────────────────────────────────────
//
// Persisted per-session (not globally, unlike the auth token) so a browser
// that reloads mid-service can reclaim controller status instead of
// silently dropping to a read-only display. No expiry is tracked
// client-side — the server (reclaim / socket join) is always re-consulted
// before a stored token is trusted, so a stale entry just fails harmlessly.
const CONTROL_TOKEN_PREFIX = 'sa_proj_control_token:'

function controlTokenKey(sessionId: string): string {
  return `${CONTROL_TOKEN_PREFIX}${sessionId}`
}
function getStoredControlToken(sessionId: string): string | null {
  try {
    return localStorage.getItem(controlTokenKey(sessionId))
  } catch {
    return null
  }
}
function storeControlToken(sessionId: string, token: string): void {
  try {
    localStorage.setItem(controlTokenKey(sessionId), token)
  } catch {
    // Persisting is best-effort — controller mode still works this session.
  }
}
function clearControlToken(sessionId: string): void {
  try {
    localStorage.removeItem(controlTokenKey(sessionId))
  } catch {
    // Nothing to do if storage is unavailable.
  }
}

export const useProjectionStore = defineStore('projection', () => {
  const status = ref<ProjectionStatus>('idle')
  const role = ref<ProjectionRole | null>(null)
  const state = ref<ProjectionSessionState | null>(null)
  const sessionId = ref<string | null>(null)
  /** Held only by the controller — display joins never see this. */
  const controlToken = ref<string | null>(null)
  const lastError = ref<string | null>(null)
  /** True after a `control-transferred` event demoted us from controller. */
  const takenOver = ref(false)

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
    socket.on('control-transferred', () => {
      // Only relevant if we were the one holding control — a display
      // socket receiving this is a no-op (it was already read-only).
      if (role.value === 'controller') {
        role.value = 'display'
        controlToken.value = null
        if (sessionId.value) clearControlToken(sessionId.value)
        takenOver.value = true
      }
    })
  }

  function dismissTakenOver(): void {
    takenOver.value = false
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
          if (res.role === 'controller' && args.controlToken) {
            storeControlToken(args.sessionId, args.controlToken)
          }
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
    if (sessionId.value) clearControlToken(sessionId.value)
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
   * Create a fresh TEMPORARY session from a single song and connect as
   * controller — the song-projection equivalent of createFromPlaylist.
   * Synthesises an ephemeral one-item playlist client-side so we can re-use
   * the same slide pipeline (chord stripping, stanza splitting). The session
   * is created without a playlistId so the projection service has no
   * dangling reference back to a real row.
   *
   * This is the "Temporary" mode of the Go Live dialog when launched from a
   * single song — fire-and-forget, self-cleans on the TEMPORARY TTL. Callers
   * that want retention should use createPersistent + loadSongInto instead
   * (the dialog's "Create persistent session" mode).
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
    clearControlToken(id)
    if (sessionId.value === id) disconnect()
  }

  /** Delete any session by id (owner or admin). */
  async function deleteById(id: string): Promise<void> {
    await projectionApi.destroySession(id)
    clearControlToken(id)
    if (sessionId.value === id) disconnect()
  }

  /**
   * Attempt to regain controller status using a token persisted from an
   * earlier visit. Returns { reclaimed: true } already connected as
   * controller, or { reclaimed: false } if there's nothing to reclaim or
   * the token no longer works — caller should fall back to connect().
   */
  async function tryReclaim(id: string): Promise<{ reclaimed: boolean }> {
    const stored = getStoredControlToken(id)
    if (!stored) return { reclaimed: false }
    try {
      await projectionApi.reclaimSession(id, stored)
    } catch {
      clearControlToken(id)
      return { reclaimed: false }
    }
    const result = await connect({ sessionId: id, controlToken: stored })
    if (result.ok && result.role === 'controller') return { reclaimed: true }
    clearControlToken(id)
    return { reclaimed: false }
  }

  /** Owner/admin forcibly takes control of a LIVE session. */
  async function takeover(id: string): Promise<CreateProjectionSessionResponse> {
    const result = await projectionApi.takeoverSession(id)
    if (result.controlToken) {
      await connect({ sessionId: id, controlToken: result.controlToken })
    }
    return result
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
    takenOver,
    sessions,
    sessionsLoading,
    // getters
    currentSlide,
    nextSlide,
    itemGroups,
    // actions
    connect,
    disconnect,
    dismissTakenOver,
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
    tryReclaim,
    takeover,
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
        item_type: 'song',
        song_id: song.id,
        position: 0,
        target_key: null,
        notes: null,
        scripture: null,
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
