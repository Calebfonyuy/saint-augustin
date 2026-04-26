// SessionsService — owns projection session lifecycle.
//
// Storage layout in Redis:
//   sa:proj:session:<sessionId>       JSON-encoded SessionState  (TTL refreshed on every write)
//   sa:proj:control-token:<sessionId> the controller's auth token  (same TTL)
//
// Why two keys instead of one struct:
//   The state object is broadcast to every connected display every time we
//   send a `state` event. Keeping the controlToken in a sibling key means it
//   never leaks into a serialized broadcast even if a future bug tries to
//   include the whole stored value.
//
// Every mutation refreshes both TTLs. A live session that isn't being
// touched will time out after SESSION_TTL_SECONDS — long enough to span a
// full Sunday morning service.
import { BadRequestException, Injectable, Logger, NotFoundException } from '@nestjs/common';
import { ConfigService } from '@nestjs/config';
import { randomBytes, randomUUID } from 'crypto';
import { CreateSessionDto } from './dto/create-session.dto';
import { SessionState } from './session.types';
import { RedisService } from '../redis/redis.service';

const DEFAULT_TTL = 4 * 60 * 60; // 4h
const KEY_STATE = (id: string) => `sa:proj:session:${id}`;
const KEY_TOKEN = (id: string) => `sa:proj:control-token:${id}`;

export interface CreatedSession {
  state: SessionState;
  controlToken: string;
}

@Injectable()
export class SessionsService {
  private readonly logger = new Logger(SessionsService.name);
  private readonly ttl: number;

  constructor(
    private readonly redis: RedisService,
    config: ConfigService,
  ) {
    this.ttl = config.get<number>('SESSION_TTL_SECONDS', DEFAULT_TTL);
  }

  // ── Lifecycle ────────────────────────────────────────────────────────

  async create(input: CreateSessionDto): Promise<CreatedSession> {
    const id = randomUUID();
    const now = new Date().toISOString();
    const state: SessionState = {
      id,
      playlistId: input.playlistId ?? null,
      playlistName: input.playlistName,
      slides: input.slides,
      currentIndex: 0,
      blackout: false,
      fontScale: 1.0,
      createdAt: now,
      updatedAt: now,
    };
    const controlToken = randomBytes(24).toString('base64url');
    await this.persist(state, controlToken);
    this.logger.log(
      `Created session ${id} (${input.slides.length} slide${
        input.slides.length === 1 ? '' : 's'
      })`,
    );
    return { state, controlToken };
  }

  async get(id: string): Promise<SessionState> {
    const raw = await this.redis.getClient().get(KEY_STATE(id));
    if (!raw) throw new NotFoundException(`Session ${id} not found`);
    return JSON.parse(raw) as SessionState;
  }

  async destroy(id: string, controlToken: string): Promise<void> {
    await this.assertControl(id, controlToken);
    await this.redis.getClient().del(KEY_STATE(id), KEY_TOKEN(id));
    this.logger.log(`Destroyed session ${id}`);
  }

  // ── Mutators (called from the WebSocket gateway) ─────────────────────

  async setIndex(id: string, index: number): Promise<SessionState> {
    return this.mutate(id, (s) => {
      const max = s.slides.length - 1;
      s.currentIndex = Math.max(0, Math.min(max, index));
    });
  }

  async next(id: string): Promise<SessionState> {
    return this.mutate(id, (s) => {
      if (s.currentIndex < s.slides.length - 1) s.currentIndex++;
    });
  }

  async previous(id: string): Promise<SessionState> {
    return this.mutate(id, (s) => {
      if (s.currentIndex > 0) s.currentIndex--;
    });
  }

  /** Jump to the first slide of the playlist item at `itemIndex`. */
  async jumpToItem(id: string, itemIndex: number): Promise<SessionState> {
    return this.mutate(id, (s) => {
      const target = s.slides.findIndex(
        (sl) => sl.itemIndex === itemIndex && sl.slideIndex === 0,
      );
      if (target >= 0) s.currentIndex = target;
    });
  }

  async setBlackout(id: string, on: boolean): Promise<SessionState> {
    return this.mutate(id, (s) => {
      s.blackout = on;
    });
  }

  async setFontScale(id: string, scale: number): Promise<SessionState> {
    if (!Number.isFinite(scale) || scale <= 0) {
      throw new BadRequestException('fontScale must be a positive number');
    }
    const clamped = Math.max(0.5, Math.min(3.0, scale));
    return this.mutate(id, (s) => {
      s.fontScale = clamped;
    });
  }

  // ── Authorization helpers ────────────────────────────────────────────

  /** Compare a candidate token to the stored one for the session. */
  async isController(id: string, controlToken: string | null | undefined): Promise<boolean> {
    if (!controlToken) return false;
    const stored = await this.redis.getClient().get(KEY_TOKEN(id));
    if (!stored) return false;
    return safeEqual(stored, controlToken);
  }

  /** Throws ForbiddenException-equivalent (BadRequestException by default
   *  to avoid revealing whether the session exists) if token is invalid. */
  async assertControl(id: string, controlToken: string | null | undefined): Promise<void> {
    if (!(await this.isController(id, controlToken))) {
      throw new BadRequestException('Invalid control token');
    }
  }

  // ── Internals ────────────────────────────────────────────────────────

  private async mutate(
    id: string,
    fn: (s: SessionState) => void,
  ): Promise<SessionState> {
    const state = await this.get(id);
    fn(state);
    state.updatedAt = new Date().toISOString();
    await this.persist(state, null);
    return state;
  }

  /**
   * Persist state and optionally the control token. Both keys share a TTL so
   * they expire together; if `controlToken` is null we just refresh the
   * existing token's expiry to match the new state expiry.
   */
  private async persist(state: SessionState, controlToken: string | null): Promise<void> {
    const client = this.redis.getClient();
    const pipeline = client.multi();
    pipeline.set(KEY_STATE(state.id), JSON.stringify(state), 'EX', this.ttl);
    if (controlToken !== null) {
      pipeline.set(KEY_TOKEN(state.id), controlToken, 'EX', this.ttl);
    } else {
      pipeline.expire(KEY_TOKEN(state.id), this.ttl);
    }
    await pipeline.exec();
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
