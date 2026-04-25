// Shared domain types mirroring the Auth Service API responses.
// Ref: services/auth/app/Http/Controllers/*.php

export type Role = 'admin' | 'musician' | 'projectionist'

export interface User {
  id: string
  email: string
  display_name: string
  roles: Role[]
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
