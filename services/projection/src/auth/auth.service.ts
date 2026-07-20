// AuthService — verifies an inbound Bearer token by calling the Auth Service's
// /api/auth/me introspection endpoint, then caches the (token → user) mapping
// in Redis briefly so we don't hammer the auth service on every WebSocket
// event or REST call.
//
// We intentionally use Node's built-in fetch here rather than pulling in a
// new HTTP client dependency: the call shape is one POSTless GET with a
// Bearer header, and we already validate the response structure manually.
//
// Cache window is short (default 60s) because role changes should propagate
// quickly — if an admin removes a user mid-service, the next event after the
// TTL expires re-validates against auth.

import { Injectable, Logger } from '@nestjs/common';
import { ConfigService } from '@nestjs/config';
import { createHash } from 'crypto';
import { RedisService } from '../redis/redis.service';
import { AuthUser } from './auth.types';

const DEFAULT_CACHE_TTL = 60;

@Injectable()
export class AuthService {
  private readonly logger = new Logger(AuthService.name);
  private readonly meUrl: string;
  private readonly cacheTtl: number;

  constructor(
    private readonly redis: RedisService,
    config: ConfigService,
  ) {
    const baseUrl = config
      .get<string>('AUTH_SERVICE_URL', 'http://auth-service:8000')
      .replace(/\/+$/, '');
    this.meUrl = `${baseUrl}/api/auth/me`;
    this.cacheTtl = Number(
      config.get<number>('AUTH_TOKEN_CACHE_TTL', DEFAULT_CACHE_TTL),
    );
  }

  /**
   * Validate a Bearer token and return the user. Returns null if the token
   * is missing/invalid or the auth service is unreachable.
   */
  async verifyToken(rawToken: string | undefined | null): Promise<AuthUser | null> {
    if (!rawToken) return null;
    const token = rawToken.trim();
    if (!token) return null;

    const cacheKey = this.cacheKey(token);
    const client = this.redis.getClient();

    try {
      const cached = await client.get(cacheKey);
      if (cached) {
        return JSON.parse(cached) as AuthUser;
      }
    } catch (err) {
      this.logger.warn(`Auth cache lookup failed for ${this.meUrl}: ${(err as Error).message}`);
    }

    let user: AuthUser | null = null;
    try {
      const res = await fetch(this.meUrl, {
        method: 'GET',
        headers: {
          Accept: 'application/json',
          Authorization: `Bearer ${token}`,
        },
      });
      if (!res.ok) return null;
      const body = (await res.json()) as { user?: AuthUser };
      if (!body?.user?.id) return null;
      user = body.user;
    } catch (err) {
      console.log(err);
      this.logger.error(`Auth service ${this.meUrl} unreachable: ${(err as Error).message}`);
      return null;
    }

    try {
      await client.set(cacheKey, JSON.stringify(user), 'EX', this.cacheTtl);
    } catch (err) {
      this.logger.warn(`Auth cache write failed: ${(err as Error).message}`);
    }
    return user;
  }

  /** Pull the Bearer token out of an Authorization header. */
  extractBearer(authorization: string | undefined | null): string | null {
    if (!authorization) return null;
    const match = /^Bearer\s+(.+)$/i.exec(authorization.trim());
    return match ? match[1].trim() : null;
  }

  // Hash so we don't write raw tokens into Redis under any circumstance.
  private cacheKey(token: string): string {
    const digest = createHash('sha256').update(token).digest('hex');
    return `sa:proj:auth-token:${digest}`;
  }
}
