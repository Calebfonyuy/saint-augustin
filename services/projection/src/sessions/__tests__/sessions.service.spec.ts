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

  it('rejects PERSISTENT without a name', async () => {
    const { svc } = makeService();
    await expect(
      svc.create({ kind: 'PERSISTENT', playlistName: 'x' }, OWNER),
    ).rejects.toBeInstanceOf(BadRequestException);
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
