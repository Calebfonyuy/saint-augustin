// =============================================================================
// SaintAugustin Projection Gateway – WebSocket Echo Server (Phase 0 Spike)
//
// This is the NestJS learning spike: a minimal WebSocket gateway that echoes
// messages and manages rooms. It will be replaced with production projection
// logic in Phase 4.
//
// Concepts exercised:
//   - @WebSocketGateway decorator and lifecycle hooks
//   - @SubscribeMessage for event-based handlers
//   - @ConnectedSocket and @MessageBody parameter decorators
//   - Room management via Socket.IO adapter
//   - Broadcasting to room members
//
// Ref: https://docs.nestjs.com/websockets/gateways
// Ref: https://socket.io/docs/v4/rooms/
// =============================================================================

import {
  WebSocketGateway,
  WebSocketServer,
  SubscribeMessage,
  MessageBody,
  ConnectedSocket,
  OnGatewayInit,
  OnGatewayConnection,
  OnGatewayDisconnect,
} from '@nestjs/websockets';
import { Logger } from '@nestjs/common';
import { Server, Socket } from 'socket.io';

@WebSocketGateway({
  cors: { origin: '*' },
  namespace: '/ws/projection',
})
export class ProjectionGateway
  implements OnGatewayInit, OnGatewayConnection, OnGatewayDisconnect
{
  @WebSocketServer()
  server: Server;

  private readonly logger = new Logger(ProjectionGateway.name);

  afterInit() {
    this.logger.log('Projection WebSocket Gateway initialized');
  }

  handleConnection(client: Socket) {
    this.logger.log(`Client connected: ${client.id}`);
  }

  handleDisconnect(client: Socket) {
    this.logger.log(`Client disconnected: ${client.id}`);
  }

  // --- Learning spike: echo server ---

  @SubscribeMessage('echo')
  handleEcho(
    @MessageBody() data: { message: string },
    @ConnectedSocket() client: Socket,
  ) {
    this.logger.debug(`Echo from ${client.id}: ${data.message}`);
    return { event: 'echo', data: { message: data.message, from: client.id } };
  }

  // --- Room management (foundation for projection sessions) ---

  @SubscribeMessage('join-room')
  handleJoinRoom(
    @MessageBody() data: { room: string },
    @ConnectedSocket() client: Socket,
  ) {
    client.join(data.room);
    this.logger.log(`Client ${client.id} joined room: ${data.room}`);

    // Notify other room members
    client.to(data.room).emit('room-member-joined', {
      clientId: client.id,
      room: data.room,
    });

    return {
      event: 'joined-room',
      data: { room: data.room, clientId: client.id },
    };
  }

  @SubscribeMessage('broadcast-to-room')
  handleBroadcast(
    @MessageBody() data: { room: string; payload: unknown },
    @ConnectedSocket() client: Socket,
  ) {
    this.logger.debug(
      `Broadcast to room ${data.room} from ${client.id}`,
    );

    // Send to everyone in the room INCLUDING the sender
    this.server.to(data.room).emit('room-broadcast', {
      from: client.id,
      payload: data.payload,
    });

    return { event: 'broadcast-sent', data: { room: data.room } };
  }
}
