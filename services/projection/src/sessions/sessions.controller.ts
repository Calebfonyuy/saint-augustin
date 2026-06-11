// HTTP API for projection sessions.
//
//   POST   /sessions               Create a session. Auth required. Body:
//                                   { kind?: 'TEMPORARY'|'PERSISTENT',
//                                     name?, playlistName, playlistId?, slides?,
//                                     scheduledStartAt?, scheduledEndAt? }
//                                  Returns { sessionId, controlToken?, state }.
//                                  controlToken is only present when the
//                                  created session is LIVE immediately
//                                  (TEMPORARY).
//   GET    /sessions               List NOT_STARTED + LIVE sessions. ?mine=1
//                                  returns only those owned by the caller.
//                                  Auth required.
//   GET    /sessions/:id           Fetch the current public state. Public —
//                                  the sessionId itself is the access token.
//   POST   /sessions/:id/start     Start a NOT_STARTED session. Owner or
//                                  admin only. Returns { state, controlToken }.
//   POST   /sessions/:id/end       End a LIVE session. Owner or admin only.
//   POST   /sessions/:id/load      Attach / replace the slide deck. Owner or
//                                  admin only.
//   DELETE /sessions/:id           Delete the session. Owner or admin only.

import {
  Body,
  Controller,
  Delete,
  Get,
  HttpCode,
  Param,
  Post,
  Query,
  UseGuards,
} from '@nestjs/common';
import { AuthGuard } from '../auth/auth.guard';
import { AuthUser } from '../auth/auth.types';
import { CurrentUser } from '../auth/current-user.decorator';
import { CreateSessionDto } from './dto/create-session.dto';
import { LoadSlidesDto } from './dto/load-slides.dto';
import { SessionsService } from './sessions.service';

@Controller('sessions')
export class SessionsController {
  constructor(private readonly sessions: SessionsService) {}

  @Post()
  @HttpCode(201)
  @UseGuards(AuthGuard)
  async create(
    @Body() dto: CreateSessionDto,
    @CurrentUser() user: AuthUser,
  ) {
    const { state, controlToken } = await this.sessions.create(dto, user);
    return {
      sessionId: state.id,
      controlToken,
      state,
    };
  }

  @Get()
  @UseGuards(AuthGuard)
  async index(
    @CurrentUser() user: AuthUser,
    @Query('mine') mine?: string,
  ) {
    const sessions = await this.sessions.list(user, {
      mine: mine === '1' || mine === 'true',
    });
    return { sessions };
  }

  @Get(':id')
  async show(@Param('id') id: string) {
    return this.sessions.get(id);
  }

  @Post(':id/start')
  @UseGuards(AuthGuard)
  async start(@Param('id') id: string, @CurrentUser() user: AuthUser) {
    const { state, controlToken } = await this.sessions.start(id, user);
    return { sessionId: state.id, controlToken, state };
  }

  @Post(':id/end')
  @UseGuards(AuthGuard)
  async end(@Param('id') id: string, @CurrentUser() user: AuthUser) {
    const state = await this.sessions.end(id, user);
    return { state };
  }

  @Post(':id/load')
  @UseGuards(AuthGuard)
  async load(
    @Param('id') id: string,
    @Body() dto: LoadSlidesDto,
    @CurrentUser() user: AuthUser,
  ) {
    const state = await this.sessions.loadSlides(id, user, dto);
    return { state };
  }

  @Delete(':id')
  @HttpCode(204)
  @UseGuards(AuthGuard)
  async destroy(
    @Param('id') id: string,
    @CurrentUser() user: AuthUser,
  ): Promise<void> {
    await this.sessions.destroy(id, user);
  }
}
