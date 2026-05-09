// Projection WebSocket gateway.
//
// One Socket.IO room per session. Clients join with `{ sessionId, controlToken? }`.
// The token, if present and correct, promotes a client to controller role and
// unlocks the write events (next/previous/jump/blackout/font-scale).
// Displays that join without a token are read-only — they receive `state`
// once on join and `slide-changed` on every controller mutation.
//
// All server→client events carry the full SessionState. That makes
// reconnection trivial: a client that drops and reconnects just re-joins
// and receives the current state in its first message — no replay needed.
import {
  ConnectedSocket,
  MessageBody,
  OnGatewayConnection,
  OnGatewayDisconnect,
  OnGatewayInit,
  SubscribeMessage,
  WebSocketGateway,
  WebSocketServer,
} from '@nestjs/websockets';
import { Logger } from '@nestjs/common';
import { Server, Socket } from 'socket.io';
import { SessionsService } from '../sessions/sessions.service';
import { SessionState } from '../sessions/session.types';

interface JoinPayload {
  sessionId: string;
  controlToken?: string;
}

interface GotoPayload {
  index: number;
}

interface JumpPayload {
  itemIndex: number;
}

interface BlackoutPayload {
  on: boolean;
}

interface FontScalePayload {
  scale: number;
}

/** Per-socket bookkeeping kept in `socket.data`. */
interface SocketState {
  sessionId?: string;
  role?: 'controller' | 'display';
}

@WebSocketGateway({
  cors: { origin: '*' },
  namespace: '/ws/projection',
})
export class ProjectionGateway
  implements OnGatewayInit, OnGatewayConnection, OnGatewayDisconnect
{
  @WebSocketServer()
  server!: Server;

  private readonly logger = new Logger(ProjectionGateway.name);

  constructor(private readonly sessions: SessionsService) {}

  afterInit() {
    this.logger.log('Projection WebSocket Gateway initialized');
  }

  handleConnection(client: Socket) {
    this.logger.debug(`Client connected: ${client.id}`);
  }

  handleDisconnect(client: Socket) {
    const data = client.data as SocketState;
    this.logger.debug(
      `Client disconnected: ${client.id}${
        data?.sessionId ? ` (session=${data.sessionId} role=${data.role})` : ''
      }`,
    );
  }

  // ── Subscription ─────────────────────────────────────────────────────

  @SubscribeMessage('join')
  async onJoin(
    @MessageBody() body: JoinPayload,
    @ConnectedSocket() client: Socket,
  ): Promise<{ ok: true; role: 'controller' | 'display'; state: SessionState } | { ok: false; error: string }> {
    if (!body?.sessionId) return { ok: false, error: 'sessionId required' };

    let state: SessionState;
    try {
      state = await this.sessions.get(body.sessionId);
    } catch {
      return { ok: false, error: 'session not found' };
    }

    const isController = await this.sessions.isController(
      body.sessionId,
      body.controlToken,
    );
    const role: 'controller' | 'display' = isController ? 'controller' : 'display';

    await client.join(body.sessionId);
    client.data = { sessionId: body.sessionId, role } satisfies SocketState;

    this.logger.log(
      `Client ${client.id} joined session ${body.sessionId} as ${role}`,
    );

    return { ok: true, role, state };
  }

  // ── Controller events (write) ────────────────────────────────────────

  @SubscribeMessage('next')
  async onNext(@ConnectedSocket() client: Socket) {
    return this.handleControllerMutation(client, (id) => this.sessions.next(id));
  }

  @SubscribeMessage('previous')
  async onPrevious(@ConnectedSocket() client: Socket) {
    return this.handleControllerMutation(client, (id) =>
      this.sessions.previous(id),
    );
  }

  @SubscribeMessage('goto')
  async onGoto(
    @MessageBody() body: GotoPayload,
    @ConnectedSocket() client: Socket,
  ) {
    return this.handleControllerMutation(client, (id) =>
      this.sessions.setIndex(id, body?.index ?? 0),
    );
  }

  @SubscribeMessage('jump-to-song')
  async onJump(
    @MessageBody() body: JumpPayload,
    @ConnectedSocket() client: Socket,
  ) {
    return this.handleControllerMutation(client, (id) =>
      this.sessions.jumpToItem(id, body?.itemIndex ?? 0),
    );
  }

  @SubscribeMessage('blackout')
  async onBlackout(
    @MessageBody() body: BlackoutPayload,
    @ConnectedSocket() client: Socket,
  ) {
    return this.handleControllerMutation(client, (id) =>
      this.sessions.setBlackout(id, !!body?.on),
    );
  }

  @SubscribeMessage('font-scale')
  async onFontScale(
    @MessageBody() body: FontScalePayload,
    @ConnectedSocket() client: Socket,
  ) {
    return this.handleControllerMutation(client, (id) =>
      this.sessions.setFontScale(id, body?.scale ?? 1),
    );
  }

  /**
   * Common pipeline: assert the socket is a controller in some session,
   * apply the mutation, then broadcast the new state to the whole room.
   */
  private async handleControllerMutation(
    client: Socket,
    mutate: (sessionId: string) => Promise<SessionState>,
  ): Promise<{ ok: true } | { ok: false; error: string }> {
    const data = client.data as SocketState;
    if (!data?.sessionId) return { ok: false, error: 'not joined' };
    if (data.role !== 'controller') return { ok: false, error: 'forbidden' };
    try {
      const state = await mutate(data.sessionId);
      this.server.to(data.sessionId).emit('state', state);
      return { ok: true };
    } catch (err) {
      this.logger.warn(`Mutation failed: ${(err as Error).message}`);
      return { ok: false, error: (err as Error).message };
    }
  }
}
