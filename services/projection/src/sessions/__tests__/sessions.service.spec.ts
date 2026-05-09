// SessionsService — verifies the state machine end-to-end against the fake
// Redis. Covers: create, navigation (next/prev/goto/jump), bounds, blackout,
// fontScale clamping, control-token gating, and explicit destroy.
import { ConfigService } from '@nestjs/config';
import { BadRequestException, NotFoundException } from '@nestjs/common';
import { SessionsService } from '../sessions.service';
import { CreateSessionDto } from '../dto/create-session.dto';
import { SlideDto } from '../dto/slide.dto';
import { FakeRedisService } from '../../redis/__tests__/redis.fake';

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

function createDto(slides: SlideDto[]): CreateSessionDto {
  return {
    playlistName: 'Sunday',
    playlistId: 'pl-1',
    slides,
  };
}

function makeService(): { svc: SessionsService; redis: FakeRedisService } {
  const redis = new FakeRedisService();
  const config = new ConfigService({});
  const svc = new SessionsService(redis as never, config);
  return { svc, redis };
}

describe('SessionsService', () => {
  it('creates a session and returns a control token', async () => {
    const { svc } = makeService();
    const slides = [
      slide({ id: 'a', itemIndex: 0, slideIndex: 0 }),
      slide({ id: 'b', itemIndex: 0, slideIndex: 1 }),
    ];
    const result = await svc.create(createDto(slides));
    expect(result.state.id).toMatch(/^[0-9a-f-]{36}$/);
    expect(result.state.currentIndex).toBe(0);
    expect(result.state.blackout).toBe(false);
    expect(result.state.fontScale).toBe(1);
    expect(result.state.slides).toHaveLength(2);
    expect(result.controlToken).toMatch(/^[A-Za-z0-9_-]+$/);
    expect(result.controlToken.length).toBeGreaterThanOrEqual(20);
  });

  it('round-trips state via get()', async () => {
    const { svc } = makeService();
    const created = await svc.create(createDto([slide()]));
    const fetched = await svc.get(created.state.id);
    expect(fetched.id).toBe(created.state.id);
    expect(fetched.playlistName).toBe('Sunday');
  });

  it('throws NotFound for unknown ids', async () => {
    const { svc } = makeService();
    await expect(svc.get('does-not-exist')).rejects.toBeInstanceOf(NotFoundException);
  });

  it('next / previous bound to the deck length', async () => {
    const { svc } = makeService();
    const { state } = await svc.create(
      createDto([
        slide({ id: 'a', slideIndex: 0 }),
        slide({ id: 'b', slideIndex: 1 }),
      ]),
    );
    let s = await svc.next(state.id);
    expect(s.currentIndex).toBe(1);
    // Already at the last slide — must not overflow.
    s = await svc.next(state.id);
    expect(s.currentIndex).toBe(1);
    s = await svc.previous(state.id);
    expect(s.currentIndex).toBe(0);
    // Already at zero — must not go negative.
    s = await svc.previous(state.id);
    expect(s.currentIndex).toBe(0);
  });

  it('setIndex clamps to valid range', async () => {
    const { svc } = makeService();
    const { state } = await svc.create(
      createDto([slide({ id: 'a' }), slide({ id: 'b', slideIndex: 1 })]),
    );
    expect((await svc.setIndex(state.id, -5)).currentIndex).toBe(0);
    expect((await svc.setIndex(state.id, 999)).currentIndex).toBe(1);
    expect((await svc.setIndex(state.id, 1)).currentIndex).toBe(1);
  });

  it('jumpToItem lands on the first slide of that playlist item', async () => {
    const { svc } = makeService();
    const { state } = await svc.create(
      createDto([
        slide({ id: 'a0', itemIndex: 0, slideIndex: 0 }),
        slide({ id: 'a1', itemIndex: 0, slideIndex: 1 }),
        slide({ id: 'b0', itemIndex: 1, slideIndex: 0 }),
        slide({ id: 'b1', itemIndex: 1, slideIndex: 1 }),
      ]),
    );
    const s = await svc.jumpToItem(state.id, 1);
    expect(s.slides[s.currentIndex].id).toBe('b0');
  });

  it('jumpToItem is a no-op when the itemIndex is unknown', async () => {
    const { svc } = makeService();
    const { state } = await svc.create(createDto([slide()]));
    await svc.next(state.id); // nothing to advance to but still records updatedAt
    const s = await svc.jumpToItem(state.id, 99);
    expect(s.currentIndex).toBe(0);
  });

  it('blackout toggles and persists', async () => {
    const { svc } = makeService();
    const { state } = await svc.create(createDto([slide()]));
    expect((await svc.setBlackout(state.id, true)).blackout).toBe(true);
    expect((await svc.setBlackout(state.id, false)).blackout).toBe(false);
  });

  it('fontScale clamps to [0.5, 3.0] and rejects non-finite values', async () => {
    const { svc } = makeService();
    const { state } = await svc.create(createDto([slide()]));
    expect((await svc.setFontScale(state.id, 0.1)).fontScale).toBe(0.5);
    expect((await svc.setFontScale(state.id, 10)).fontScale).toBe(3.0);
    expect((await svc.setFontScale(state.id, 1.4)).fontScale).toBeCloseTo(1.4);
    await expect(svc.setFontScale(state.id, NaN)).rejects.toBeInstanceOf(
      BadRequestException,
    );
  });

  it('isController accepts the issued token, rejects others', async () => {
    const { svc } = makeService();
    const { state, controlToken } = await svc.create(createDto([slide()]));
    expect(await svc.isController(state.id, controlToken)).toBe(true);
    expect(await svc.isController(state.id, 'wrong')).toBe(false);
    expect(await svc.isController(state.id, undefined)).toBe(false);
    expect(await svc.isController('no-such-session', controlToken)).toBe(false);
  });

  it('destroy refuses an invalid token and removes the session with a valid one', async () => {
    const { svc } = makeService();
    const { state, controlToken } = await svc.create(createDto([slide()]));
    await expect(svc.destroy(state.id, 'bogus')).rejects.toBeInstanceOf(
      BadRequestException,
    );
    await svc.destroy(state.id, controlToken);
    await expect(svc.get(state.id)).rejects.toBeInstanceOf(NotFoundException);
  });
});
