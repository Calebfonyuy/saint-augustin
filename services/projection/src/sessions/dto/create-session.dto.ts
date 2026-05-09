// POST /sessions request shape.
// The controller (frontend) supplies the slide deck and a human-friendly
// playlist name. The service generates the sessionId and the controlToken
// that gates write events on the WebSocket gateway.
import {
  ArrayMinSize,
  IsArray,
  IsOptional,
  IsString,
  ValidateNested,
} from 'class-validator';
import { Type } from 'class-transformer';
import { SlideDto } from './slide.dto';

export class CreateSessionDto {
  @IsString()
  playlistName!: string;

  /** Optional opaque playlist id — useful for cross-referencing in logs. */
  @IsOptional()
  @IsString()
  playlistId?: string;

  @IsArray()
  @ArrayMinSize(1)
  @ValidateNested({ each: true })
  @Type(() => SlideDto)
  slides!: SlideDto[];
}
