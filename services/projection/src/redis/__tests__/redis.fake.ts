// Minimal in-memory replacement for the slice of ioredis we actually use.
// Sessions use get / set (with EX) / del / expire / persist / multi.exec
// plus the SET commands (sadd / smembers / srem) used to index sessions
// for listing. Keeps the test suite hermetic — no Redis container.
type Entry = { value: string; expiresAt: number | null };

class FakeRedisClient {
  private store = new Map<string, Entry>();
  private sets = new Map<string, Set<string>>();

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

  async mget(...keys: string[]): Promise<(string | null)[]> {
    return Promise.all(keys.map((k) => this.get(k)));
  }

  async set(
    key: string,
    value: string,
    mode?: string,
    ttlSeconds?: number,
  ): Promise<'OK'> {
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
      if (this.sets.delete(k)) n++;
    }
    return n;
  }

  async expire(key: string, ttlSeconds: number): Promise<number> {
    if (!this.gc(key)) return 0;
    this.store.get(key)!.expiresAt = Date.now() + ttlSeconds * 1000;
    return 1;
  }

  async persist(key: string): Promise<number> {
    if (!this.gc(key)) return 0;
    const e = this.store.get(key)!;
    if (e.expiresAt === null) return 0;
    e.expiresAt = null;
    return 1;
  }

  async sadd(key: string, ...members: string[]): Promise<number> {
    let set = this.sets.get(key);
    if (!set) {
      set = new Set<string>();
      this.sets.set(key, set);
    }
    let added = 0;
    for (const m of members) {
      if (!set.has(m)) {
        set.add(m);
        added++;
      }
    }
    return added;
  }

  async srem(key: string, ...members: string[]): Promise<number> {
    const set = this.sets.get(key);
    if (!set) return 0;
    let removed = 0;
    for (const m of members) {
      if (set.delete(m)) removed++;
    }
    if (set.size === 0) this.sets.delete(key);
    return removed;
  }

  async smembers(key: string): Promise<string[]> {
    const set = this.sets.get(key);
    if (!set) return [];
    return Array.from(set);
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
