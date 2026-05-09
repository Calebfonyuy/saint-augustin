// Internal session state — the shape stored in Redis and broadcast to clients.
// Slides are immutable after session creation; runtime state is the index,
// blackout flag, and font scale only. End-of-life is signalled by the
// session being deleted from Redis (key TTL or explicit DELETE).
import { SlideDto } from './dto/slide.dto';

export interface SessionState {
  id: string;
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
