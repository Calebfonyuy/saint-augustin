// SessionsService — verifies the state machine end-to-end against the fake
// Redis. Covers: create (TEMPORARY + PERSISTENT), navigation
// (next/prev/goto/jump), bounds, blackout, fontScale clamping, lifecycle
// (start/end/destroy/loadSlides), and owner-vs-admin authorisation.
import { ConfigService } from '@nestjs/config';
import {
  BadRequestException,
  ConflictException,
  ForbiddenException,
  NotFoundException,
} from '@nestjs/common';
import { SessionsService } from '../sessions.service';
import { CreateSessionDto } from '../dto/create-session.dto';
import { SlideDto } from '../dto/slide.dto';
import { FakeRedisService } from '../../redis/__tests__/redis.fake';
import { AuthUser } from '../../auth/auth.types';

function slide(over: Partial<SlideDto> = {}): SlideDto {
  return {
    id: 'sl',
    itemIndex: 0,
    slideIndex: 0,
    songTitle: 'Amazing Grace',
    section: 'Verse 1',
    body: 'Amazing grace, how sweet the sound',
    ...over,
  };
}

function temporaryDto(slides: SlideDto[]): CreateSessionDto {
  return {
    kind: 'TEMPORARY',
    playlistName: 'Sunday',
    playlistId: 'pl-1',
    slides,
  };
}

function persistentDto(over: Partial<CreateSessionDto> = {}): CreateSessionDto {
  return {
    kind: 'PERSISTENT',
    name: 'Sunday 9:30',
    playlistName: 'Sunday',
    playlistId: 'pl-1',
    ...over,
  };
}

const OWNER: AuthUser = {
  id: 'user-owner',
  email: 'owner@church.local',
  display_name: 'Owner',
  roles: ['musician'],
};
const STRANGER: AuthUser = {
  id: 'user-stranger',
  email: 'stranger@church.local',
  display_name: 'Stranger',
  roles: ['projectionist'],
};
const ADMIN: AuthUser = {
  id: 'user-admin',
  email: 'admin@church.local',
  display_name: 'Admin',
  roles: ['admin'],
};

function makeService(): { svc: SessionsService; redis: FakeRedisService } {
  const redis = new FakeRedisService();
  const config = new ConfigService({});
  const svc = new SessionsService(redis as never, config);
  return { svc, redis };
}

describe('SessionsService — temporary lifecycle', () => {
  it('creates a TEMPORARY session immediately LIVE with a control token', async () => {
    const { svc } = makeService();
    const slides = [
      slide({ id: 'a', itemIndex: 0, slideIndex: 0 }),
      slide({ id: 'b', itemIndex: 0, slideIndex: 1 }),
    ];
    const result = await svc.create(temporaryDto(slides), OWNER);
    expect(result.state.id).toMatch(/^[0-9a-f-]{36}$/);
    expect(result.state.status).toBe('LIVE');
    expect(result.state.kind).toBe('TEMPORARY');
    expect(result.state.ownerId).toBe(OWNER.id);
    expect(result.state.startedAt).not.toBeNull();
    expect(result.state.currentIndex).toBe(0);
    expect(result.state.slides).toHaveLength(2);
    expect(result.controlToken).toMatch(/^[A-Za-z0-9_-]+$/);
  });

  it('rejects a TEMPORARY session with no slides', async () => {
    const { svc } = makeService();
    await expect(
      svc.create({ kind: 'TEMPORARY', playlistName: 'x', slides: [] }, OWNER),
    ).rejects.toBeInstanceOf(BadRequestException);
  });

  it('round-trips state via get()', async () => {
    const { svc } = makeService();
    const created = await svc.create(temporaryDto([slide()]), OWNER);
    const fetched = await svc.get(created.state.id);
    expect(fetched.id).toBe(created.state.id);
    expect(fetched.playlistName).toBe('Sunday');
  });

  it('throws NotFound for unknown ids', async () => {
    const { svc } = makeService();
    await expect(svc.get('does-not-exist')).rejects.toBeInstanceOf(
      NotFoundException,
    );
  });
});

describe('SessionsService — persistent lifecycle', () => {
  it('creates a PERSISTENT session in NOT_STARTED with no control token', async () => {
    const { svc } = makeService();
    const result = await svc.create(persistentDto(), OWNER);
    expect(result.state.status).toBe('NOT_STARTED');
    expect(result.state.kind).toBe('PERSISTENT');
    expect(result.controlToken).toBeNull();
    expect(result.state.name).toBe('Sunday 9:30');
  });

  it('PERSISTENT with no name falls back to playlistName', async () => {
    const { svc } = makeService();
    const result = await svc.create({ kind: 'PERSISTENT', playlistName: 'x' }, OWNER);
    expect(result.state.name).toBe('x');
  });

  it('omitting kind defaults to PERSISTENT (not TEMPORARY)', async () => {
    const { svc } = makeService();
    const result = await svc.create({ playlistName: 'Ad-hoc' }, OWNER);
    expect(result.state.kind).toBe('PERSISTENT');
    expect(result.state.status).toBe('NOT_STARTED');
    expect(result.controlToken).toBeNull();
  });

  it('rejects scheduledEnd before scheduledStart', async () => {
    const { svc } = makeService();
    await expect(
      svc.create(
        persistentDto({
          scheduledStartAt: '2026-06-01T10:00:00Z',
          scheduledEndAt: '2026-06-01T09:00:00Z',
        }),
        OWNER,
      ),
    ).rejects.toBeInstanceOf(BadRequestException);
  });

  it('refuses to start a session without slides', async () => {
    const { svc } = makeService();
    const created = await svc.create(persistentDto(), OWNER);
    await expect(svc.start(created.state.id, OWNER)).rejects.toBeInstanceOf(
      BadRequestException,
    );
  });

  it('loadSlides → start → live flow works end-to-end', async () => {
    const { svc } = makeService();
    const created = await svc.create(persistentDto(), OWNER);
    await svc.loadSlides(created.state.id, OWNER, {
      playlistName: 'Sunday',
      slides: [slide({ id: 'a' }), slide({ id: 'b', slideIndex: 1 })],
    });
    const { state, controlToken } = await svc.start(created.state.id, OWNER);
    expect(state.status).toBe('LIVE');
    expect(state.startedAt).not.toBeNull();
    expect(controlToken).toMatch(/^[A-Za-z0-9_-]+$/);
    expect(await svc.isController(created.state.id, controlToken)).toBe(true);
  });

  it('end() transitions LIVE → ENDED and clears the control token', async () => {
    const { svc } = makeService();
    const created = await svc.create(persistentDto(), OWNER);
    await svc.loadSlides(created.state.id, OWNER, {
      playlistName: 'Sunday',
      slides: [slide()],
    });
    const { controlToken } = await svc.start(created.state.id, OWNER);
    const ended = await svc.end(created.state.id, OWNER);
    expect(ended.status).toBe('ENDED');
    expect(ended.endedAt).not.toBeNull();
    expect(await svc.isController(created.state.id, controlToken)).toBe(false);
  });

  it('cannot restart an ENDED session', async () => {
    const { svc } = makeService();
    const created = await svc.create(persistentDto(), OWNER);
    await svc.loadSlides(created.state.id, OWNER, {
      playlistName: 'Sunday',
      slides: [slide()],
    });
    await svc.start(created.state.id, OWNER);
    await svc.end(created.state.id, OWNER);
    await expect(svc.start(created.state.id, OWNER)).rejects.toBeInstanceOf(
      ConflictException,
    );
  });

  it('cannot double-start a LIVE session', async () => {
    const { svc } = makeService();
    const created = await svc.create(persistentDto(), OWNER);
    await svc.loadSlides(created.state.id, OWNER, {
      playlistName: 'Sunday',
      slides: [slide()],
    });
    await svc.start(created.state.id, OWNER);
    await expect(svc.start(created.state.id, OWNER)).rejects.toBeInstanceOf(
      ConflictException,
    );
  });
});

describe('SessionsService — navigation (requires LIVE)', () => {
  it('next/previous bound to deck length', async () => {
    const { svc } = makeService();
    const { state } = await svc.create(
      temporaryDto([
        slide({ id: 'a', slideIndex: 0 }),
        slide({ id: 'b', slideIndex: 1 }),
      ]),
      OWNER,
    );
    let s = await svc.next(state.id);
    expect(s.currentIndex).toBe(1);
    s = await svc.next(state.id);
    expect(s.currentIndex).toBe(1);
    s = await svc.previous(state.id);
    expect(s.currentIndex).toBe(0);
    s = await svc.previous(state.id);
    expect(s.currentIndex).toBe(0);
  });

  it('setIndex clamps', async () => {
    const { svc } = makeService();
    const { state } = await svc.create(
      temporaryDto([slide({ id: 'a' }), slide({ id: 'b', slideIndex: 1 })]),
      OWNER,
    );
    expect((await svc.setIndex(state.id, -5)).currentIndex).toBe(0);
    expect((await svc.setIndex(state.id, 999)).currentIndex).toBe(1);
  });

  it('jumpToItem lands on first slide of the playlist item', async () => {
    const { svc } = makeService();
    const { state } = await svc.create(
      temporaryDto([
        slide({ id: 'a0', itemIndex: 0, slideIndex: 0 }),
        slide({ id: 'a1', itemIndex: 0, slideIndex: 1 }),
        slide({ id: 'b0', itemIndex: 1, slideIndex: 0 }),
      ]),
      OWNER,
    );
    const s = await svc.jumpToItem(state.id, 1);
    expect(s.slides[s.currentIndex].id).toBe('b0');
  });

  it('rejects mutations on NOT_STARTED sessions', async () => {
    const { svc } = makeService();
    const created = await svc.create(persistentDto(), OWNER);
    await svc.loadSlides(created.state.id, OWNER, {
      playlistName: 'Sunday',
      slides: [slide()],
    });
    await expect(svc.next(created.state.id)).rejects.toBeInstanceOf(
      ConflictException,
    );
  });

  it('fontScale clamps to [0.5, 3.0] and rejects non-finite values', async () => {
    const { svc } = makeService();
    const { state } = await svc.create(temporaryDto([slide()]), OWNER);
    expect((await svc.setFontScale(state.id, 0.1)).fontScale).toBe(0.5);
    expect((await svc.setFontScale(state.id, 10)).fontScale).toBe(3.0);
    await expect(svc.setFontScale(state.id, NaN)).rejects.toBeInstanceOf(
      BadRequestException,
    );
  });
});

describe('SessionsService — authorisation', () => {
  it('strangers cannot start someone else’s session', async () => {
    const { svc } = makeService();
    const created = await svc.create(persistentDto(), OWNER);
    await svc.loadSlides(created.state.id, OWNER, {
      playlistName: 'Sunday',
      slides: [slide()],
    });
    await expect(svc.start(created.state.id, STRANGER)).rejects.toBeInstanceOf(
      ForbiddenException,
    );
  });

  it('admins can start, end, and delete any session', async () => {
    const { svc } = makeService();
    const created = await svc.create(persistentDto(), OWNER);
    await svc.loadSlides(created.state.id, OWNER, {
      playlistName: 'Sunday',
      slides: [slide()],
    });
    const { state } = await svc.start(created.state.id, ADMIN);
    expect(state.status).toBe('LIVE');
    await svc.end(created.state.id, ADMIN);
    await svc.destroy(created.state.id, ADMIN);
    await expect(svc.get(created.state.id)).rejects.toBeInstanceOf(
      NotFoundException,
    );
  });

  it('strangers cannot delete someone else’s session', async () => {
    const { svc } = makeService();
    const created = await svc.create(temporaryDto([slide()]), OWNER);
    await expect(svc.destroy(created.state.id, STRANGER)).rejects.toBeInstanceOf(
      ForbiddenException,
    );
  });
});

describe('SessionsService — listing', () => {
  it('lists NOT_STARTED + LIVE sessions, hides ENDED', async () => {
    const { svc } = makeService();
    const a = await svc.create(persistentDto({ name: 'A' }), OWNER);
    const b = await svc.create(persistentDto({ name: 'B' }), OWNER);
    await svc.loadSlides(b.state.id, OWNER, {
      playlistName: 'Sunday',
      slides: [slide()],
    });
    await svc.start(b.state.id, OWNER);
    const c = await svc.create(persistentDto({ name: 'C' }), OWNER);
    await svc.loadSlides(c.state.id, OWNER, {
      playlistName: 'Sunday',
      slides: [slide()],
    });
    await svc.start(c.state.id, OWNER);
    await svc.end(c.state.id, OWNER);

    const list = await svc.list(OWNER);
    const ids = list.map((s) => s.id).sort();
    expect(ids).toEqual([a.state.id, b.state.id].sort());
  });

  it('mine=true filters to caller-owned sessions', async () => {
    const { svc } = makeService();
    await svc.create(persistentDto({ name: 'O' }), OWNER);
    await svc.create(persistentDto({ name: 'X' }), STRANGER);
    const onlyOwner = await svc.list(OWNER, { mine: true });
    expect(onlyOwner).toHaveLength(1);
    expect(onlyOwner[0].name).toBe('O');
  });
});

describe('SessionsService — reclaim', () => {
  async function makeLiveSession(svc: SessionsService) {
    const created = await svc.create(persistentDto(), OWNER);
    await svc.loadSlides(created.state.id, OWNER, {
      playlistName: 'Sunday',
      slides: [slide()],
    });
    const { state, controlToken } = await svc.start(created.state.id, OWNER);
    return { id: state.id, controlToken };
  }

  it('succeeds with the correct token on a LIVE session', async () => {
    const { svc } = makeService();
    const { id, controlToken } = await makeLiveSession(svc);
    const state = await svc.reclaim(id, controlToken);
    expect(state.id).toBe(id);
  });

  it('rejects a wrong token', async () => {
    const { svc } = makeService();
    const { id } = await makeLiveSession(svc);
    await expect(svc.reclaim(id, 'not-the-token')).rejects.toBeInstanceOf(
      ForbiddenException,
    );
  });

  it('rejects when the session is not live', async () => {
    const { svc } = makeService();
    const created = await svc.create(persistentDto(), OWNER);
    await expect(svc.reclaim(created.state.id, 'anything')).rejects.toBeInstanceOf(
      ConflictException,
    );
  });
});

describe('SessionsService — takeover', () => {
  async function makeLiveSession(svc: SessionsService) {
    const created = await svc.create(persistentDto(), OWNER);
    await svc.loadSlides(created.state.id, OWNER, {
      playlistName: 'Sunday',
      slides: [slide()],
    });
    const { state, controlToken } = await svc.start(created.state.id, OWNER);
    return { id: state.id, controlToken };
  }

  it('rotates the control token — the old token stops working', async () => {
    const { svc } = makeService();
    const { id, controlToken: oldToken } = await makeLiveSession(svc);
    const { controlToken: newToken } = await svc.takeover(id, ADMIN);
    expect(newToken).not.toBe(oldToken);
    expect(await svc.isController(id, oldToken)).toBe(false);
    expect(await svc.isController(id, newToken)).toBe(true);
  });

  it('rejects a stranger', async () => {
    const { svc } = makeService();
    const { id } = await makeLiveSession(svc);
    await expect(svc.takeover(id, STRANGER)).rejects.toBeInstanceOf(
      ForbiddenException,
    );
  });

  it('allows an admin', async () => {
    const { svc } = makeService();
    const { id } = await makeLiveSession(svc);
    const { state } = await svc.takeover(id, ADMIN);
    expect(state.id).toBe(id);
  });

  it('rejects when the session is not live', async () => {
    const { svc } = makeService();
    const created = await svc.create(persistentDto(), OWNER);
    await expect(svc.takeover(created.state.id, OWNER)).rejects.toBeInstanceOf(
      ConflictException,
    );
  });
});

// Retention TTLs (FR-SE-1/2) — the Stage-3 fix. These assert the *actual*
// Redis expiry applied per kind/status, not just the state-machine result:
// an unscheduled (PERSISTENT) session must persist indefinitely, while a
// TEMPORARY one self-cleans after ~4h. Guards against a regression to the
// original bug where unscheduled sessions were given the 4h TTL and vanished.
describe('SessionsService — retention TTLs', () => {
  const KEY_STATE = (id: string) => `sa:proj:session:${id}`;
  const KEY_TOKEN = (id: string) => `sa:proj:control-token:${id}`;
  const FOUR_HOURS = 4 * 60 * 60; // DEFAULT_TTL
  const THIRTY_DAYS = 30 * 24 * 60 * 60; // ENDED_TTL

  it('TEMPORARY session state + control token expire after ~4h', async () => {
    const { svc, redis } = makeService();
    const { state } = await svc.create(temporaryDto([slide()]), OWNER);
    const client = redis.getClient();
    const stateTtl = await client.ttl(KEY_STATE(state.id));
    const tokenTtl = await client.ttl(KEY_TOKEN(state.id));
    expect(stateTtl).toBeGreaterThan(FOUR_HOURS - 10);
    expect(stateTtl).toBeLessThanOrEqual(FOUR_HOURS);
    expect(tokenTtl).toBeGreaterThan(FOUR_HOURS - 10);
    expect(tokenTtl).toBeLessThanOrEqual(FOUR_HOURS);
  });

  it('PERSISTENT session is stored with NO expiry while NOT_STARTED', async () => {
    const { svc, redis } = makeService();
    const { state } = await svc.create(persistentDto(), OWNER);
    // -1 = key exists but has no TTL (ioredis semantics).
    expect(await redis.getClient().ttl(KEY_STATE(state.id))).toBe(-1);
  });

  it('an unscheduled session (no kind) defaults to PERSISTENT and never expires', async () => {
    const { svc, redis } = makeService();
    const { state } = await svc.create({ playlistName: 'Ad-hoc' }, OWNER);
    expect(state.kind).toBe('PERSISTENT');
    expect(await redis.getClient().ttl(KEY_STATE(state.id))).toBe(-1);
  });

  it('PERSISTENT session keeps NO expiry once LIVE (state + token)', async () => {
    const { svc, redis } = makeService();
    const created = await svc.create(persistentDto(), OWNER);
    await svc.loadSlides(created.state.id, OWNER, {
      playlistName: 'Sunday',
      slides: [slide()],
    });
    await svc.start(created.state.id, OWNER);
    const client = redis.getClient();
    expect(await client.ttl(KEY_STATE(created.state.id))).toBe(-1);
    expect(await client.ttl(KEY_TOKEN(created.state.id))).toBe(-1);
  });

  it('persist() drops a stale TTL left on a PERSISTENT key', async () => {
    const { svc, redis } = makeService();
    const created = await svc.create(persistentDto(), OWNER);
    const client = redis.getClient();
    // Simulate a leftover TTL from a prior life of the key…
    await client.expire(KEY_STATE(created.state.id), FOUR_HOURS);
    expect(await client.ttl(KEY_STATE(created.state.id))).toBeGreaterThan(0);
    // …a mutation re-persists the state, which must clear that TTL.
    await svc.loadSlides(created.state.id, OWNER, {
      playlistName: 'Sunday',
      slides: [slide()],
    });
    expect(await client.ttl(KEY_STATE(created.state.id))).toBe(-1);
  });

  it('ENDED session gets a 30-day TTL so its share link still resolves', async () => {
    const { svc, redis } = makeService();
    const created = await svc.create(persistentDto(), OWNER);
    await svc.loadSlides(created.state.id, OWNER, {
      playlistName: 'Sunday',
      slides: [slide()],
    });
    await svc.start(created.state.id, OWNER);
    await svc.end(created.state.id, OWNER);
    const stateTtl = await redis.getClient().ttl(KEY_STATE(created.state.id));
    expect(stateTtl).toBeGreaterThan(THIRTY_DAYS - 10);
    expect(stateTtl).toBeLessThanOrEqual(THIRTY_DAYS);
  });
});
