// Shared domain types mirroring the Auth Service API responses.
// Ref: services/auth/app/Http/Controllers/*.php

export type Role = 'admin' | 'musician' | 'projectionist'

export interface User {
  id: string
  email: string
  display_name: string
  roles: Role[]
}

/**
 * Full user record returned by the admin user-management endpoints
 * (GET /api/users, PUT /api/users/:id). Carries timestamps that the
 * Admin → Users screen renders as "Joined" / "Last updated".
 */
export interface UserDetail extends User {
  created_at: string
  updated_at: string
}

export interface TokenResponse {
  token_type: 'Bearer'
  access_token: string
  user: User
}

export interface Songbook {
  id: string
  name: string
  description: string | null
  is_default: boolean
  created_by: string | null
  songs_count: number
  created_at: string
  updated_at: string
}

export interface Song {
  id: string
  title: string
  author: string | null
  lyrics: string
  original_key: string | null
  tempo: number | null
  time_signature: string | null
  songbook_id: string
  tags: string[]
  preview_url: string | null
  ccli_number: string | null
  created_by: string | null
  version: number
  created_at: string
  updated_at: string
  deleted_at: string | null
}

export interface Paginated<T> {
  data: T[]
  meta: {
    current_page: number
    per_page: number
    total: number
    last_page: number
  }
}

export interface SongListQuery {
  q?: string
  songbook?: string
  key?: string
  tag?: string
  trashed?: 'with' | 'only'
  per_page?: number
  page?: number
}

export type SongInput = Omit<
  Song,
  'id' | 'created_by' | 'version' | 'created_at' | 'updated_at' | 'deleted_at'
>

/** Phase 2 — file attachments on a song (PDF lead sheets, choir-part scans). */
export interface SongSheet {
  id: string
  song_id: string
  original_filename: string
  file_type: 'pdf' | 'image'
  mime_type: string
  size_bytes: number
  uploaded_by: string | null
  /** Short-lived presigned download URL — re-fetch via GET /sheets/{id} once expired. */
  url: string
  url_expires_at: string | null
  created_at: string
  updated_at: string
}

export interface Invitation {
  id: string
  email: string
  roles: Role[]
  invited_by: string | null
  expires_at: string
  accepted: boolean
}

/** Standard Laravel validation error shape (422). */
export interface ValidationError {
  message: string
  errors: Record<string, string[]>
}

/* ────────────────────────────────────────────────────────────────────────
 * Phase 3 — Playlists, items, share links
 * Ref: services/auth/app/Http/Controllers/PlaylistController.php
 *      services/auth/app/Http/Controllers/PlaylistItemController.php
 *      services/auth/app/Http/Controllers/ShareLinkController.php
 * ──────────────────────────────────────────────────────────────────────── */

export interface PlaylistSummary {
  id: string
  name: string
  event_date: string | null
  tags: string[]
  created_by: string | null
  item_count: number
  created_at: string
  updated_at: string
}

/** Slim song shape returned inside playlist items. */
export interface PlaylistItemSong {
  id: string
  title: string
  author: string | null
  original_key: string | null
  tempo: number | null
  time_signature: string | null
  /** True when the underlying song was soft-deleted. */
  deleted?: boolean
  /** Only present on share-link payloads (musician mode). */
  lyrics?: string
  preview_url?: string | null
}

export interface PlaylistItem {
  id: string
  song_id: string
  position: number
  target_key: string | null
  notes: string | null
  song: PlaylistItemSong | null
}

export interface Playlist extends PlaylistSummary {
  duplicated_from_id: string | null
  items: PlaylistItem[]
}

export interface PlaylistInput {
  name: string
  event_date?: string | null
  tags?: string[]
}

export interface PlaylistListQuery {
  q?: string
  tag?: string
  mine?: boolean
  per_page?: number
  page?: number
}

export type ShareMode = 'musician' | 'projection'

export interface ShareLink {
  id: string
  playlist_id: string
  token: string
  mode: ShareMode
  expires_at: string | null
  revoked_at: string | null
  created_at: string
}

/** Public payload returned by GET /api/share/:token. */
export interface SharedPlaylistResponse {
  mode: ShareMode
  playlist: {
    id: string
    name: string
    event_date: string | null
    tags: string[]
    items: PlaylistItem[]
  }
}

/* ────────────────────────────────────────────────────────────────────────
 * Phase 4 — Projection sessions.
 * Mirror of the NestJS projection service contract. Slide is exported
 * from `@/lib/projection`; the rest are HTTP / WebSocket payload shapes.
 * Ref: services/projection/src/sessions/session.types.ts
 * ──────────────────────────────────────────────────────────────────────── */

export interface ProjectionSlide {
  id: string
  itemIndex: number
  slideIndex: number
  songTitle: string
  section: string | null
  body: string
}

export type ProjectionSessionStatus = 'NOT_STARTED' | 'LIVE' | 'ENDED'
export type ProjectionSessionKind = 'TEMPORARY' | 'PERSISTENT'

export interface ProjectionSessionState {
  id: string
  name: string
  status: ProjectionSessionStatus
  kind: ProjectionSessionKind
  ownerId: string | null
  ownerName: string | null
  scheduledStartAt: string | null
  scheduledEndAt: string | null
  startedAt: string | null
  endedAt: string | null
  playlistId: string | null
  playlistName: string
  slides: ProjectionSlide[]
  currentIndex: number
  blackout: boolean
  fontScale: number
  createdAt: string
  updatedAt: string
}

/** Compact list-view shape returned by GET /sessions. */
export interface ProjectionSessionSummary {
  id: string
  name: string
  status: ProjectionSessionStatus
  kind: ProjectionSessionKind
  ownerId: string | null
  ownerName: string | null
  scheduledStartAt: string | null
  scheduledEndAt: string | null
  startedAt: string | null
  endedAt: string | null
  playlistName: string
  slideCount: number
  createdAt: string
  updatedAt: string
}

export interface CreateProjectionSessionInput {
  /** Defaults to TEMPORARY when omitted. */
  kind?: ProjectionSessionKind
  /** Required for PERSISTENT; defaults to playlistName for TEMPORARY. */
  name?: string
  playlistName: string
  playlistId?: string
  /** Required for TEMPORARY; optional for PERSISTENT (can be loaded later). */
  slides?: ProjectionSlide[]
  scheduledStartAt?: string
  scheduledEndAt?: string
}

export interface CreateProjectionSessionResponse {
  sessionId: string
  /** Only present when the created session is LIVE (TEMPORARY). */
  controlToken: string | null
  state: ProjectionSessionState
}

export interface LoadProjectionSlidesInput {
  playlistName: string
  playlistId?: string
  slides: ProjectionSlide[]
}
