// Internal session state — the shape stored in Redis and broadcast to clients.
//
// Two kinds of session:
//   • TEMPORARY  – the legacy "live for one service" session. Created with
//                  slides and immediately enters LIVE state. Stored with a
//                  TTL so it self-cleans if the controller exits.
//   • PERSISTENT – a planned session. Created in NOT_STARTED state, with an
//                  optional schedule (scheduledStartAt / scheduledEndAt) and
//                  an optional name. Lives forever (no TTL) until explicitly
//                  ended or deleted. ENDED sessions get a 30-day TTL so the
//                  share link returns ENDED for a while before 404'ing.
//
// Lifecycle:
//   NOT_STARTED  ──start()──▶  LIVE  ──end()──▶  ENDED
//
// Slides may be (re)loaded at start time for a PERSISTENT session
// ("project to existing live session" flow) so a leader doesn't have to
// know the slides up-front when scheduling.

import { SlideDto } from './dto/slide.dto';

export type SessionStatus = 'NOT_STARTED' | 'LIVE' | 'ENDED';
export type SessionKind = 'TEMPORARY' | 'PERSISTENT';

export interface SessionState {
  id: string;
  /** Human-friendly label. For TEMPORARY sessions this defaults to the
   *  playlistName; PERSISTENT sessions get an explicit user-supplied name. */
  name: string;
  status: SessionStatus;
  kind: SessionKind;

  /** Creator of the session. null only for legacy/pre-auth records. */
  ownerId: string | null;
  ownerName: string | null;

  /** ISO 8601. null means "no schedule, live forever / start manually". */
  scheduledStartAt: string | null;
  scheduledEndAt: string | null;

  /** Actual transitions. Filled in when start()/end() runs. */
  startedAt: string | null;
  endedAt: string | null;

  /** Optional opaque playlist id — useful for cross-referencing in logs. */
  playlistId: string | null;
  playlistName: string;
  slides: SlideDto[];

  currentIndex: number;
  blackout: boolean;
  /** Multiplier on the display's base font size; 1.0 is the configured default. */
  fontScale: number;

  createdAt: string;
  updatedAt: string;
}

/** Public projection of SessionState — same minus the controlToken (which
 *  is stored separately under its own Redis key, never exposed to displays). */
export type PublicSessionState = SessionState;

/** Compact shape used by GET /sessions (list view). Strips slides so the
 *  payload stays small even when a session has many songs. */
export interface SessionSummary {
  id: string;
  name: string;
  status: SessionStatus;
  kind: SessionKind;
  ownerId: string | null;
  ownerName: string | null;
  scheduledStartAt: string | null;
  scheduledEndAt: string | null;
  startedAt: string | null;
  endedAt: string | null;
  playlistName: string;
  slideCount: number;
  createdAt: string;
  updatedAt: string;
}

export function toSummary(state: SessionState): SessionSummary {
  return {
    id: state.id,
    name: state.name,
    status: state.status,
    kind: state.kind,
    ownerId: state.ownerId,
    ownerName: state.ownerName,
    scheduledStartAt: state.scheduledStartAt,
    scheduledEndAt: state.scheduledEndAt,
    startedAt: state.startedAt,
    endedAt: state.endedAt,
    playlistName: state.playlistName,
    slideCount: state.slides.length,
    createdAt: state.createdAt,
    updatedAt: state.updatedAt,
  };
}
