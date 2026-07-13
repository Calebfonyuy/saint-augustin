// SessionsService — owns projection session lifecycle.
//
// Storage layout in Redis:
//   sa:proj:session:<sessionId>           JSON-encoded SessionState
//   sa:proj:control-token:<sessionId>     the controller's auth token (LIVE only)
//   sa:proj:sessions:active               SET of NOT_STARTED + LIVE session ids
//   sa:proj:sessions:by-owner:<userId>    SET of session ids owned by that user
//
// Why two keys instead of one struct for state + token:
//   The state object is broadcast to every connected display every time we
//   send a `state` event. Keeping the controlToken in a sibling key means it
//   never leaks into a serialized broadcast even if a future bug tries to
//   include the whole stored value.
//
// TTLs:
//   • TEMPORARY sessions get the configured SESSION_TTL (default 4h),
//     refreshed on every mutation — long enough to span a service.
//   • PERSISTENT NOT_STARTED / LIVE sessions have no TTL: they survive
//     restarts and can be scheduled days in advance.
//   • PERSISTENT ENDED sessions get a 30-day TTL so share links continue to
//     report "ended" instead of 404 right after a service.
//
// Authorisation rules (enforced in controllers, helpers exposed here):
//   • Create:                 any authenticated user.
//   • List:                   any authenticated user (returns NOT_STARTED +
//                             LIVE sessions for everyone — they are public
//                             join points).
//   • Get state:              public (sessionId is the share token).
//   • Start / End / Delete:   owner of the session, or an admin.
//   • Load slides:            owner of the session, or an admin.
//   • Reclaim:                anyone holding a still-valid control token
//                             (token possession is the proof of authority).
//   • Takeover:               owner of the session, or an admin. Rotates
//                             the control token and revokes any connected
//                             controller sockets (see ProjectionGateway).

import {
  BadRequestException,
  ConflictException,
  ForbiddenException,
  Injectable,
  Logger,
  NotFoundException,
} from '@nestjs/common';
import { ConfigService } from '@nestjs/config';
import { randomBytes, randomUUID } from 'crypto';
import { AuthUser, isAdmin } from '../auth/auth.types';
import { CreateSessionDto } from './dto/create-session.dto';
import { LoadSlidesDto } from './dto/load-slides.dto';
import {
  SessionKind,
  SessionState,
  SessionSummary,
  toSummary,
} from './session.types';
import { RedisService } from '../redis/redis.service';

const DEFAULT_TTL = 4 * 60 * 60; // 4h for TEMPORARY
const ENDED_TTL = 30 * 24 * 60 * 60; // 30d for PERSISTENT ENDED

const KEY_STATE = (id: string) => `sa:proj:session:${id}`;
const KEY_TOKEN = (id: string) => `sa:proj:control-token:${id}`;
const KEY_ACTIVE_SET = 'sa:proj:sessions:active';
const KEY_OWNER_SET = (userId: string) => `sa:proj:sessions:by-owner:${userId}`;

export interface CreatedSession {
  state: SessionState;
  /** Only set when the session enters LIVE immediately (TEMPORARY). */
  controlToken: string | null;
}

@Injectable()
export class SessionsService {
  private readonly logger = new Logger(SessionsService.name);
  private readonly ttl: number;

  constructor(
    private readonly redis: RedisService,
    config: ConfigService,
  ) {
    this.ttl = Number(config.get<number>('SESSION_TTL_SECONDS', DEFAULT_TTL));
  }

  // ── Lifecycle ────────────────────────────────────────────────────────

  async create(
    input: CreateSessionDto,
    user: AuthUser | null,
  ): Promise<CreatedSession> {
    const kind: SessionKind = input.kind ?? 'PERSISTENT';
    const id = randomUUID();
    const now = new Date().toISOString();

    if (kind === 'TEMPORARY' && (!input.slides || input.slides.length === 0)) {
      throw new BadRequestException(
        'TEMPORARY sessions require slides at creation time',
      );
    }
    if (
      input.scheduledStartAt &&
      input.scheduledEndAt &&
      new Date(input.scheduledEndAt) <= new Date(input.scheduledStartAt)
    ) {
      throw new BadRequestException(
        'scheduledEndAt must be after scheduledStartAt',
      );
    }

    const slides = input.slides ?? [];
    const isLive = kind === 'TEMPORARY';
    const state: SessionState = {
      id,
      name: input.name?.trim() || input.playlistName,
      status: isLive ? 'LIVE' : 'NOT_STARTED',
      kind,
      ownerId: user?.id ?? null,
      ownerName: user?.display_name ?? null,
      scheduledStartAt: input.scheduledStartAt ?? null,
      scheduledEndAt: input.scheduledEndAt ?? null,
      startedAt: isLive ? now : null,
      endedAt: null,
      playlistId: input.playlistId ?? null,
      playlistName: input.playlistName,
      slides,
      currentIndex: 0,
      blackout: false,
      fontScale: 1.0,
      createdAt: now,
      updatedAt: now,
    };

    const controlToken = isLive ? randomBytes(24).toString('base64url') : null;
    await this.persist(state, controlToken);
    await this.indexInActiveSet(state);
    if (state.ownerId) await this.indexInOwnerSet(state);

    this.logger.log(
      `Created ${kind} session ${id} (${slides.length} slide${
        slides.length === 1 ? '' : 's'
      }) by ${user?.email ?? 'anonymous'}`,
    );
    return { state, controlToken };
  }

  async get(id: string): Promise<SessionState> {
    const raw = await this.redis.getClient().get(KEY_STATE(id));
    if (!raw) throw new NotFoundException(`Session ${id} not found`);
    return JSON.parse(raw) as SessionState;
  }

  /**
   * List NOT_STARTED + LIVE sessions. With `mine=true`, only sessions owned
   * by the user. Everyone (incl. non-admins) can see all active sessions —
   * they're public join points; ownership only matters for mutations.
   */
  async list(
    user: AuthUser,
    opts: { mine?: boolean } = {},
  ): Promise<SessionSummary[]> {
    const client = this.redis.getClient();
    const setKey = opts.mine ? KEY_OWNER_SET(user.id) : KEY_ACTIVE_SET;
    const ids = await client.smembers(setKey);
    if (ids.length === 0) return [];

    const keys = ids.map((id) => KEY_STATE(id));
    const raws = await client.mget(...keys);

    const summaries: SessionSummary[] = [];
    const stale: string[] = [];
    for (let i = 0; i < ids.length; i++) {
      const raw = raws[i];
      if (!raw) {
        stale.push(ids[i]);
        continue;
      }
      const state = JSON.parse(raw) as SessionState;
      if (opts.mine || state.status === 'NOT_STARTED' || state.status === 'LIVE') {
        summaries.push(toSummary(state));
      }
    }

    // Best-effort cleanup of stale set entries (state expired/gone but the
    // index still references it). Not awaited so listing stays snappy.
    if (stale.length > 0) {
      void client.srem(setKey, ...stale).catch(() => undefined);
      if (setKey === KEY_ACTIVE_SET) {
        void client.srem(KEY_ACTIVE_SET, ...stale).catch(() => undefined);
      }
    }

    summaries.sort((a, b) => b.updatedAt.localeCompare(a.updatedAt));
    return summaries;
  }

  /**
   * NOT_STARTED → LIVE. Returns the new control token. Owner or admin only.
   * For PERSISTENT sessions, slides must already be loaded (either at
   * create() or via loadSlides()).
   */
  async start(
    id: string,
    user: AuthUser,
  ): Promise<{ state: SessionState; controlToken: string }> {
    const state = await this.get(id);
    this.assertOwnerOrAdmin(state, user);
    if (state.status === 'LIVE') {
      throw new ConflictException('Session is already live');
    }
    if (state.status === 'ENDED') {
      throw new ConflictException('Cannot restart an ended session');
    }
    if (!state.slides || state.slides.length === 0) {
      throw new BadRequestException(
        'Cannot start a session without slides — load slides first',
      );
    }
    state.status = 'LIVE';
    state.startedAt = new Date().toISOString();
    state.updatedAt = state.startedAt;
    state.currentIndex = 0;
    state.blackout = false;

    const controlToken = randomBytes(24).toString('base64url');
    await this.persist(state, controlToken);
    await this.indexInActiveSet(state);

    this.logger.log(`Started session ${id} by ${user.email}`);
    return { state, controlToken };
  }

  /** LIVE → ENDED. Owner or admin only. */
  async end(id: string, user: AuthUser): Promise<SessionState> {
    const state = await this.get(id);
    this.assertOwnerOrAdmin(state, user);
    if (state.status === 'ENDED') {
      // Idempotent — return the current state.
      return state;
    }
    state.status = 'ENDED';
    state.endedAt = new Date().toISOString();
    state.updatedAt = state.endedAt;

    await this.redis.getClient().del(KEY_TOKEN(id));
    await this.persist(state, null);
    await this.removeFromActiveSet(state.id);

    this.logger.log(`Ended session ${id} by ${user.email}`);
    return state;
  }

  /**
   * Re-confirm control using a previously-issued token, without rotating
   * it. Used when a browser reloads/reconnects and wants to know whether
   * the control token it persisted client-side is still good.
   */
  async reclaim(id: string, token: string): Promise<SessionState> {
    const state = await this.get(id);
    if (state.status !== 'LIVE') {
      throw new ConflictException('Session is not live');
    }
    if (!(await this.isController(id, token))) {
      throw new ForbiddenException('Invalid or expired control token');
    }
    return state;
  }

  /**
   * Owner or admin forcibly takes control of a LIVE session, rotating the
   * control token. The caller must separately notify/revoke connected
   * sockets — see ProjectionGateway.handleTakeover.
   */
  async takeover(
    id: string,
    user: AuthUser,
  ): Promise<{ state: SessionState; controlToken: string }> {
    const state = await this.get(id);
    this.assertOwnerOrAdmin(state, user);
    if (state.status !== 'LIVE') {
      throw new ConflictException('Session is not live');
    }
    const controlToken = randomBytes(24).toString('base64url');
    await this.persist(state, controlToken);
    this.logger.log(`Session ${id} control taken over by ${user.email}`);
    return { state, controlToken };
  }

  /** Delete the session entirely. Owner or admin only. */
  async destroy(id: string, user: AuthUser): Promise<void> {
    const state = await this.get(id);
    this.assertOwnerOrAdmin(state, user);

    const client = this.redis.getClient();
    await client.del(KEY_STATE(id), KEY_TOKEN(id));
    await this.removeFromActiveSet(id);
    if (state.ownerId) {
      await client.srem(KEY_OWNER_SET(state.ownerId), id).catch(() => undefined);
    }
    this.logger.log(`Destroyed session ${id} by ${user.email}`);
  }

  /**
   * Replace (or attach) the slide deck on an existing session. Owner or admin.
   * Resets currentIndex to 0 since the prior position has no meaning in the
   * new deck.
   */
  async loadSlides(
    id: string,
    user: AuthUser,
    input: LoadSlidesDto,
  ): Promise<SessionState> {
    const state = await this.get(id);
    this.assertOwnerOrAdmin(state, user);
    if (state.status === 'ENDED') {
      throw new ConflictException('Cannot load slides on an ended session');
    }
    state.slides = input.slides;
    state.playlistName = input.playlistName;
    state.playlistId = input.playlistId ?? null;
    state.currentIndex = 0;
    state.blackout = false;
    state.updatedAt = new Date().toISOString();
    await this.persist(state, null);
    return state;
  }

  // ── Mutators (called from the WebSocket gateway) ─────────────────────

  async setIndex(id: string, index: number): Promise<SessionState> {
    return this.mutateLive(id, (s) => {
      const max = s.slides.length - 1;
      s.currentIndex = Math.max(0, Math.min(max, index));
    });
  }

  async next(id: string): Promise<SessionState> {
    return this.mutateLive(id, (s) => {
      if (s.currentIndex < s.slides.length - 1) s.currentIndex++;
    });
  }

  async previous(id: string): Promise<SessionState> {
    return this.mutateLive(id, (s) => {
      if (s.currentIndex > 0) s.currentIndex--;
    });
  }

  /** Jump to the first slide of the playlist item at `itemIndex`. */
  async jumpToItem(id: string, itemIndex: number): Promise<SessionState> {
    return this.mutateLive(id, (s) => {
      const target = s.slides.findIndex(
        (sl) => sl.itemIndex === itemIndex && sl.slideIndex === 0,
      );
      if (target >= 0) s.currentIndex = target;
    });
  }

  async setBlackout(id: string, on: boolean): Promise<SessionState> {
    return this.mutateLive(id, (s) => {
      s.blackout = on;
    });
  }

  async setFontScale(id: string, scale: number): Promise<SessionState> {
    if (!Number.isFinite(scale) || scale <= 0) {
      throw new BadRequestException('fontScale must be a positive number');
    }
    const clamped = Math.max(0.5, Math.min(3.0, scale));
    return this.mutateLive(id, (s) => {
      s.fontScale = clamped;
    });
  }

  // ── Authorization helpers ────────────────────────────────────────────

  /** Compare a candidate token to the stored one for the session. */
  async isController(
    id: string,
    controlToken: string | null | undefined,
  ): Promise<boolean> {
    if (!controlToken) return false;
    const stored = await this.redis.getClient().get(KEY_TOKEN(id));
    if (!stored) return false;
    return safeEqual(stored, controlToken);
  }

  /** Owner or admin only — throws ForbiddenException otherwise. */
  assertOwnerOrAdmin(state: SessionState, user: AuthUser): void {
    if (isAdmin(user)) return;
    if (state.ownerId && state.ownerId === user.id) return;
    throw new ForbiddenException('Only the owner or an admin can do that');
  }

  // ── Internals ────────────────────────────────────────────────────────

  /** Mutation that requires LIVE state — guards every WebSocket write event. */
  private async mutateLive(
    id: string,
    fn: (s: SessionState) => void,
  ): Promise<SessionState> {
    const state = await this.get(id);
    if (state.status !== 'LIVE') {
      throw new ConflictException('Session is not live');
    }
    fn(state);
    state.updatedAt = new Date().toISOString();
    await this.persist(state, null);
    return state;
  }

  /**
   * Persist state and optionally the control token. Both keys share a TTL so
   * they expire together when applicable; if `controlToken` is null we just
   * refresh the existing token's expiry (or no-op for persistent storage).
   *
   * PERSISTENT sessions are stored without a TTL while NOT_STARTED or LIVE,
   * and with a 30-day TTL once ENDED so the share link can still resolve
   * to an "ended" page rather than 404 immediately.
   */
  private async persist(
    state: SessionState,
    controlToken: string | null,
  ): Promise<void> {
    const client = this.redis.getClient();
    const json = JSON.stringify(state);
    const ttl = this.ttlFor(state);
    if (ttl === null) {
      await client.set(KEY_STATE(state.id), json);
      // Drop any leftover TTL from a prior life of this key.
      await client.persist(KEY_STATE(state.id)).catch(() => undefined);
    } else {
      await client.set(KEY_STATE(state.id), json, 'EX', ttl);
    }

    if (controlToken !== null) {
      if (ttl === null) {
        await client.set(KEY_TOKEN(state.id), controlToken);
      } else {
        await client.set(KEY_TOKEN(state.id), controlToken, 'EX', ttl);
      }
    } else if (ttl !== null) {
      await client.expire(KEY_TOKEN(state.id), ttl).catch(() => undefined);
    }
  }

  private ttlFor(state: SessionState): number | null {
    if (state.kind === 'TEMPORARY') return this.ttl;
    if (state.status === 'ENDED') return ENDED_TTL;
    return null; // persistent + (NOT_STARTED | LIVE) → no expiry
  }

  private async indexInActiveSet(state: SessionState): Promise<void> {
    if (state.status === 'NOT_STARTED' || state.status === 'LIVE') {
      await this.redis
        .getClient()
        .sadd(KEY_ACTIVE_SET, state.id)
        .catch(() => undefined);
    } else {
      await this.removeFromActiveSet(state.id);
    }
  }

  private async indexInOwnerSet(state: SessionState): Promise<void> {
    if (!state.ownerId) return;
    await this.redis
      .getClient()
      .sadd(KEY_OWNER_SET(state.ownerId), state.id)
      .catch(() => undefined);
  }

  private async removeFromActiveSet(id: string): Promise<void> {
    await this.redis
      .getClient()
      .srem(KEY_ACTIVE_SET, id)
      .catch(() => undefined);
  }
}

/** Constant-time string compare. ioredis returns plain strings; we want to
 *  avoid leaking timing info on token comparisons even though sessions are
 *  short-lived. */
function safeEqual(a: string, b: string): boolean {
  if (a.length !== b.length) return false;
  let diff = 0;
  for (let i = 0; i < a.length; i++) diff |= a.charCodeAt(i) ^ b.charCodeAt(i);
  return diff === 0;
}
