// Minimal in-memory replacement for the slice of ioredis we actually use.
// Sessions use only get / set (with EX) / del / expire / multi.exec, so we
// implement just those. Keeps the test suite hermetic — no Redis container.
type Entry = { value: string; expiresAt: number | null };

class FakeRedisClient {
  private store = new Map<string, Entry>();

  private gc(key: string): boolean {
    const e = this.store.get(key);
    if (!e) return false;
    if (e.expiresAt !== null && e.expiresAt < Date.now()) {
      this.store.delete(key);
      return false;
    }
    return true;
  }

  async get(key: string): Promise<string | null> {
    if (!this.gc(key)) return null;
    return this.store.get(key)!.value;
  }

  async set(key: string, value: string, mode?: string, ttlSeconds?: number): Promise<'OK'> {
    const expiresAt =
      mode === 'EX' && typeof ttlSeconds === 'number'
        ? Date.now() + ttlSeconds * 1000
        : null;
    this.store.set(key, { value, expiresAt });
    return 'OK';
  }

  async del(...keys: string[]): Promise<number> {
    let n = 0;
    for (const k of keys) {
      if (this.store.delete(k)) n++;
    }
    return n;
  }

  async expire(key: string, ttlSeconds: number): Promise<number> {
    if (!this.gc(key)) return 0;
    this.store.get(key)!.expiresAt = Date.now() + ttlSeconds * 1000;
    return 1;
  }

  /** Pipeline / transaction stub — runs queued commands sequentially. */
  multi(): FakePipeline {
    return new FakePipeline(this);
  }
}

class FakePipeline {
  private queue: Array<() => Promise<unknown>> = [];

  constructor(private readonly client: FakeRedisClient) {}

  set(key: string, value: string, mode: string, ttlSeconds: number): this {
    this.queue.push(() => this.client.set(key, value, mode, ttlSeconds));
    return this;
  }

  expire(key: string, ttlSeconds: number): this {
    this.queue.push(() => this.client.expire(key, ttlSeconds));
    return this;
  }

  async exec(): Promise<unknown[]> {
    const out: unknown[] = [];
    for (const fn of this.queue) out.push(await fn());
    return out;
  }
}

export class FakeRedisService {
  private client = new FakeRedisClient();
  getClient(): FakeRedisClient {
    return this.client;
  }
}
