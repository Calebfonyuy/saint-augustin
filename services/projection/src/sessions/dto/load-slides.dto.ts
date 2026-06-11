// POST /sessions/:id/load request shape — used to (re)attach a playlist's
// slides to an existing session. Available to the session owner or an admin.
//
// Two flows hit this endpoint:
//   1. A PERSISTENT NOT_STARTED session is being prepared just before service.
//   2. A leader wants to switch playlists mid-service: load new slides into
//      an already-LIVE session and continue projecting.

import {
  ArrayMinSize,
  IsArray,
  IsOptional,
  IsString,
  ValidateNested,
} from 'class-validator';
import { Type } from 'class-transformer';
import { SlideDto } from './slide.dto';

export class LoadSlidesDto {
  @IsString()
  playlistName!: string;

  @IsOptional()
  @IsString()
  playlistId?: string;

  @IsArray()
  @ArrayMinSize(1)
  @ValidateNested({ each: true })
  @Type(() => SlideDto)
  slides!: SlideDto[];
}
