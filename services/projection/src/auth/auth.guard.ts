// AuthGuard — requires a valid Bearer token on the incoming HTTP request.
// On success it sets `request.user` so controllers can read the resolved
// AuthUser via the `@CurrentUser()` parameter decorator.
//
// 401 is returned for missing or invalid tokens (so the frontend's axios
// interceptor can bounce the user back to /login the same way it does
// against the auth service).

import {
  CanActivate,
  ExecutionContext,
  Injectable,
  UnauthorizedException,
} from '@nestjs/common';
import { Request } from 'express';
import { AuthService } from './auth.service';
import { AuthUser } from './auth.types';

interface AuthedRequest extends Request {
  user?: AuthUser;
}

@Injectable()
export class AuthGuard implements CanActivate {
  constructor(private readonly auth: AuthService) {}

  async canActivate(ctx: ExecutionContext): Promise<boolean> {
    const req = ctx.switchToHttp().getRequest<AuthedRequest>();
    const token = this.auth.extractBearer(req.headers.authorization);
    const user = await this.auth.verifyToken(token);
    if (!user) throw new UnauthorizedException('Invalid or missing token');
    req.user = user;
    return true;
  }
}
