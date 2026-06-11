// Wraps a single ioredis client behind a Nest provider so other services can
// inject `RedisService` instead of constructing their own clients. The client
// is created lazily and reused for the life of the process.
//
// Tests replace this provider with a fake (see redis.service.fake.ts in
// __tests__) so the suite never needs a real Redis container.
import {
  Injectable,
  Logger,
  OnModuleDestroy,
  OnModuleInit,
} from '@nestjs/common';
import { ConfigService } from '@nestjs/config';
import { channel } from 'diagnostics_channel';
import Redis from 'ioredis';

@Injectable()
export class RedisService implements OnModuleInit, OnModuleDestroy {
  private readonly logger = new Logger(RedisService.name);
  private client: Redis | null = null;

  constructor(private readonly config: ConfigService) {}

  onModuleInit(): void {
    const host = this.config.get<string>('REDIS_HOST', 'redis');
    const port = this.config.get<number>('REDIS_PORT', 6379);
    this.client = new Redis({ host, port, lazyConnect: false });
    this.client.on('error', (err) => {
      this.logger.error(`Redis error: ${err.message}`);
      this.logger.error(`Redis client status: ${this.client?.status}`)
    });
    this.client.on('connect', () => {
      this.logger.log(`Redis client connected to ${host}:${port}`);
    });

    this.client.on('connecting', () => {
      this.logger.log(`Redis client reconnecting to ${host}:${port}`);
    });

    this.client.on('close', () => {
      this.logger.log(`Redis client disconnected from ${host}:${port}`);
    });
    this.client.on('ready', ()=>{
      this.logger.log(`Redis client ready`);
    });
  }

  async onModuleDestroy(): Promise<void> {
    if (this.client) {
      await this.client.quit();
      this.client = null;
    }
  }

  /** Get the underlying ioredis client. Throws if module hasn't initialized. */
  getClient(): Redis {
    if (!this.client) {
      throw new Error('Redis client not initialized');
    }
    return this.client;
  }
}
