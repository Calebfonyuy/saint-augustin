// ProjectionGateway — covers the controller-vs-display authorisation gate
// and the broadcast pipeline. Socket and Server are stubbed so the test
// runs purely at the application layer.
import { ConfigService } from '@nestjs/config';
import { ProjectionGateway } from '../projection.gateway';
import { SessionsService } from '../../sessions/sessions.service';
import { FakeRedisService } from '../../redis/__tests__/redis.fake';
import { SlideDto } from '../../sessions/dto/slide.dto';
import type { Server, Socket } from 'socket.io';

function fakeSocket(): Socket {
  const data: Record<string, unknown> = {};
  return {
    id: 'sock-1',
    data,
    join: jest.fn(async () => undefined),
  } as unknown as Socket;
}

function fakeServer() {
  const emit = jest.fn();
  const to = jest.fn().mockReturnValue({ emit });
  return { server: { to } as unknown as Server, to, emit };
}

function slide(over: Partial<SlideDto> = {}): SlideDto {
  return {
    id: 's',
    itemIndex: 0,
    slideIndex: 0,
    songTitle: 'X',
    section: null,
    body: 'lyrics',
    ...over,
  };
}

async function setup() {
  const redis = new FakeRedisService();
  const config = new ConfigService({});
  const sessions = new SessionsService(redis as never, config);
  const gateway = new ProjectionGateway(sessions);
  const { server, to, emit } = fakeServer();
  gateway.server = server;

  const created = await sessions.create({
    playlistName: 'Sunday',
    slides: [
      slide({ id: 'a', slideIndex: 0 }),
      slide({ id: 'b', slideIndex: 1 }),
    ],
  });

  return { gateway, sessions, created, to, emit };
}

describe('ProjectionGateway', () => {
  it('rejects join without sessionId', async () => {
    const { gateway } = await setup();
    const sock = fakeSocket();
    const result = await gateway.onJoin({ sessionId: '' } as never, sock);
    expect(result.ok).toBe(false);
  });

  it('rejects join for unknown session', async () => {
    const { gateway } = await setup();
    const sock = fakeSocket();
    const result = await gateway.onJoin({ sessionId: 'unknown' }, sock);
    expect(result.ok).toBe(false);
  });

  it('joins a display when no token is supplied', async () => {
    const { gateway, created } = await setup();
    const sock = fakeSocket();
    const result = await gateway.onJoin({ sessionId: created.state.id }, sock);
    if (!result.ok) throw new Error('expected ok=true');
    expect(result.role).toBe('display');
    expect(result.state.id).toBe(created.state.id);
    expect((sock.data as { role: string }).role).toBe('display');
    expect(sock.join).toHaveBeenCalledWith(created.state.id);
  });

  it('promotes to controller when the token matches', async () => {
    const { gateway, created } = await setup();
    const sock = fakeSocket();
    const result = await gateway.onJoin(
      { sessionId: created.state.id, controlToken: created.controlToken },
      sock,
    );
    if (!result.ok) throw new Error('expected ok=true');
    expect(result.role).toBe('controller');
  });

  it('display cannot mutate state', async () => {
    const { gateway, created } = await setup();
    const sock = fakeSocket();
    await gateway.onJoin({ sessionId: created.state.id }, sock);
    const result = await gateway.onNext(sock);
    expect(result.ok).toBe(false);
    if ('error' in result) expect(result.error).toBe('forbidden');
  });

  it('controller next() advances the session and broadcasts state', async () => {
    const { gateway, created, to, emit } = await setup();
    const sock = fakeSocket();
    await gateway.onJoin(
      { sessionId: created.state.id, controlToken: created.controlToken },
      sock,
    );
    const result = await gateway.onNext(sock);
    expect(result.ok).toBe(true);
    expect(to).toHaveBeenCalledWith(created.state.id);
    expect(emit).toHaveBeenCalledTimes(1);
    const [event, payload] = emit.mock.calls[0];
    expect(event).toBe('state');
    expect(payload.currentIndex).toBe(1);
  });

  it('blackout toggles independently of slide index', async () => {
    const { gateway, created, emit } = await setup();
    const sock = fakeSocket();
    await gateway.onJoin(
      { sessionId: created.state.id, controlToken: created.controlToken },
      sock,
    );
    await gateway.onBlackout({ on: true }, sock);
    const last = emit.mock.calls[emit.mock.calls.length - 1];
    expect(last[1].blackout).toBe(true);
    expect(last[1].currentIndex).toBe(0);
  });

  it('reconnection scenario: a fresh display join gets the live current state', async () => {
    const { gateway, created } = await setup();
    const ctrl = fakeSocket();
    await gateway.onJoin(
      { sessionId: created.state.id, controlToken: created.controlToken },
      ctrl,
    );
    await gateway.onNext(ctrl);
    await gateway.onBlackout({ on: true }, ctrl);

    const display = fakeSocket();
    const result = await gateway.onJoin({ sessionId: created.state.id }, display);
    if (!result.ok) throw new Error('expected ok=true');
    expect(result.state.currentIndex).toBe(1);
    expect(result.state.blackout).toBe(true);
  });
});
