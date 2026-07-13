// POST /sessions/:id/reclaim request shape — re-confirms a previously
// issued control token still authorizes writes, without rotating it
// (unlike /takeover). Used when a browser reloads or reconnects and wants
// to know if the token it persisted client-side is still good.

import { IsNotEmpty, IsString } from 'class-validator';

export class ReclaimSessionDto {
  @IsString()
  @IsNotEmpty()
  token!: string;
}
