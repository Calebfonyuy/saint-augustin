// ProjectionGateway — covers the controller-vs-display authorisation gate
// and the broadcast pipeline. Socket and Server are stubbed so the test
// runs purely at the application layer.
import { ConfigService } from '@nestjs/config';
import { ProjectionGateway } from '../projection.gateway';
import { SessionsService } from '../../sessions/sessions.service';
import { FakeRedisService } from '../../redis/__tests__/redis.fake';
import { SlideDto } from '../../sessions/dto/slide.dto';
import type { Server, Socket } from 'socket.io';

function fakeSocket(id = 'sock-1'): Socket {
  const data: Record<string, unknown> = {};
  return {
    id,
    data,
    join: jest.fn(async () => undefined),
  } as unknown as Socket;
}

function fakeServer() {
  const emit = jest.fn();
  const to = jest.fn().mockReturnValue({ emit });
  const rooms = new Map<string, Set<string>>();
  const socketsById = new Map<string, Socket>();
  // NestJS injects the namespace-scoped Server when `namespace` is set on
  // @WebSocketGateway — `sockets`/`adapter` live directly on it (mirrors
  // the real Socket.IO Namespace shape, not the root Server's nested
  // `.sockets.sockets`/`.sockets.adapter`).
  const server = {
    to,
    adapter: { rooms },
    sockets: socketsById,
  } as unknown as Server;
  return { server, to, emit, rooms, socketsById };
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
  const { server, to, emit, rooms, socketsById } = fakeServer();
  gateway.server = server;

  const created = await sessions.create(
    {
      kind: 'TEMPORARY',
      playlistName: 'Sunday',
      slides: [
        slide({ id: 'a', slideIndex: 0 }),
        slide({ id: 'b', slideIndex: 1 }),
      ],
    },
    {
      id: 'u-1',
      email: 'leader@church.local',
      display_name: 'Leader',
      roles: ['musician'],
    },
  );

  return { gateway, sessions, created, to, emit, rooms, socketsById };
}

/** Register a socket as a member of a session's room in the fake server —
 *  mirrors what the real Socket.IO adapter does on `client.join()`, which
 *  the `fakeSocket()`/`fakeServer()` stubs don't wire up automatically. */
function joinRoom(
  rooms: Map<string, Set<string>>,
  socketsById: Map<string, Socket>,
  sessionId: string,
  sock: Socket,
) {
  socketsById.set(sock.id, sock);
  const room = rooms.get(sessionId) ?? new Set<string>();
  room.add(sock.id);
  rooms.set(sessionId, room);
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
      { sessionId: created.state.id, controlToken: created.controlToken! },
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
      { sessionId: created.state.id, controlToken: created.controlToken! },
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
      { sessionId: created.state.id, controlToken: created.controlToken! },
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
      { sessionId: created.state.id, controlToken: created.controlToken! },
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

  describe('handleTakeover', () => {
    it('downgrades a connected controller socket and broadcasts control-transferred', async () => {
      const { gateway, created, rooms, socketsById, to, emit } = await setup();
      const controllerSock = fakeSocket('sock-controller');
      const displaySock = fakeSocket('sock-display');
      await gateway.onJoin(
        { sessionId: created.state.id, controlToken: created.controlToken! },
        controllerSock,
      );
      await gateway.onJoin({ sessionId: created.state.id }, displaySock);
      joinRoom(rooms, socketsById, created.state.id, controllerSock);
      joinRoom(rooms, socketsById, created.state.id, displaySock);

      gateway.handleTakeover(created.state.id, 'Admin');

      expect((controllerSock.data as { role: string }).role).toBe('display');
      expect((displaySock.data as { role: string }).role).toBe('display');
      expect(to).toHaveBeenCalledWith(created.state.id);
      const [event, payload] = emit.mock.calls[emit.mock.calls.length - 1];
      expect(event).toBe('control-transferred');
      expect(payload).toEqual({ byName: 'Admin' });
    });

    it('actually revokes write authority — the deposed socket can no longer mutate', async () => {
      const { gateway, created, rooms, socketsById } = await setup();
      const controllerSock = fakeSocket('sock-controller');
      await gateway.onJoin(
        { sessionId: created.state.id, controlToken: created.controlToken! },
        controllerSock,
      );
      joinRoom(rooms, socketsById, created.state.id, controllerSock);

      gateway.handleTakeover(created.state.id, null);

      const result = await gateway.onNext(controllerSock);
      expect(result.ok).toBe(false);
      if ('error' in result) expect(result.error).toBe('forbidden');
    });

    it('is a no-op when the room has no connected sockets', async () => {
      const { gateway, created } = await setup();
      expect(() => gateway.handleTakeover(created.state.id, null)).not.toThrow();
    });
  });
});
