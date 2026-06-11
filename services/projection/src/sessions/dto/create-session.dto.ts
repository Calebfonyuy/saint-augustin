// POST /sessions request shape.
//
// Two creation modes:
//   • TEMPORARY  — legacy path. Slides are required, session immediately
//                  enters LIVE state, controlToken returned right away.
//   • PERSISTENT — slides are optional (can be loaded later via the
//                  /sessions/:id/load endpoint). Session enters NOT_STARTED.
//                  A name is required; scheduledStart/End are optional.
//
// The service generates the sessionId and (on start) the controlToken that
// gates write events on the WebSocket gateway.

import {
  ArrayMinSize,
  IsArray,
  IsEnum,
  IsISO8601,
  IsOptional,
  IsString,
  ValidateNested,
  MaxLength,
} from 'class-validator';
import { Type } from 'class-transformer';
import { SlideDto } from './slide.dto';
import { SessionKind } from '../session.types';

export class CreateSessionDto {
  /** TEMPORARY (default) or PERSISTENT. */
  @IsOptional()
  @IsEnum(['TEMPORARY', 'PERSISTENT'])
  kind?: SessionKind;

  /** Human-friendly label. Required for PERSISTENT; defaults to
   *  playlistName for TEMPORARY when omitted. */
  @IsOptional()
  @IsString()
  @MaxLength(120)
  name?: string;

  @IsString()
  playlistName!: string;

  /** Optional opaque playlist id — useful for cross-referencing in logs. */
  @IsOptional()
  @IsString()
  playlistId?: string;

  /** Required for TEMPORARY; optional for PERSISTENT (can be loaded later). */
  @IsOptional()
  @IsArray()
  @ArrayMinSize(1)
  @ValidateNested({ each: true })
  @Type(() => SlideDto)
  slides?: SlideDto[];

  /** ISO 8601 — when the session should automatically be considered to start.
   *  Stored, but the actual transition to LIVE is performed via /start. */
  @IsOptional()
  @IsISO8601()
  scheduledStartAt?: string;

  @IsOptional()
  @IsISO8601()
  scheduledEndAt?: string;
}
