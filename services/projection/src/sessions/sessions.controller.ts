// HTTP API for projection sessions.
//
//   POST   /sessions          Create a session, returns { sessionId, controlToken }
//   GET    /sessions/:id      Fetch the current public state (used by displays
//                              joining a session before they open the WebSocket)
//   DELETE /sessions/:id      End a session. Requires the control token via
//                              the X-Control-Token header.
//
// We keep the authentication minimal on purpose: the sessionId itself is the
// access credential for read access (mirrors share-link semantics), and the
// controlToken gates writes. There is no user-level auth — the projection
// service is single-tenant, runs inside the church's network, and is meant
// to be invoked transiently for a single Sunday service.
import {
  Body,
  Controller,
  Delete,
  Get,
  Headers,
  HttpCode,
  Param,
  Post,
  UsePipes,
  ValidationPipe,
} from '@nestjs/common';
import { CreateSessionDto } from './dto/create-session.dto';
import { SessionsService } from './sessions.service';

@Controller('sessions')
@UsePipes(new ValidationPipe({ transform: true, whitelist: true }))
export class SessionsController {
  constructor(private readonly sessions: SessionsService) {}

  @Post()
  @HttpCode(201)
  async create(@Body() dto: CreateSessionDto) {
    const { state, controlToken } = await this.sessions.create(dto);
    return {
      sessionId: state.id,
      controlToken,
      state,
    };
  }

  @Get(':id')
  async show(@Param('id') id: string) {
    return this.sessions.get(id);
  }

  @Delete(':id')
  @HttpCode(204)
  async destroy(
    @Param('id') id: string,
    @Headers('x-control-token') controlToken: string,
  ): Promise<void> {
    await this.sessions.destroy(id, controlToken ?? '');
  }
}
