# Projection Service

NestJS 10 on Node.js 20. Manages real-time projection sessions over Socket.IO. Each session holds the slide state for one live service; a controller client drives it and one or more display clients follow it.

## Prerequisites

- Node.js 20
- npm 10+
- Redis 7 running and accessible

For local development the root `docker-compose.yml` starts Redis and the service container. The instructions below assume you want to run the service **outside** Docker.

---

## Environment Setup

```bash
cd services/projection
cp .env.example .env   # if an .env.example exists, otherwise create .env
```

Required environment variables:

| Variable | Purpose | Default |
|---|---|---|
| `PORT` | HTTP + WebSocket port | `3000` |
| `REDIS_HOST` | Redis hostname | `localhost` |
| `REDIS_PORT` | Redis port | `6379` |
| `REDIS_PASSWORD` | Redis password (if any) | *(empty)* |
| `ALLOWED_ORIGINS` | CORS allowed origins (comma-separated) | `http://localhost:5173` |

Install dependencies:

```bash
npm install
```

---

## Starting the Service

### Development (with file-watch rebuild)

```bash
npm run start:dev
```

NestJS CLI watches `src/` and restarts on every save. TypeScript is compiled incrementally via `ts-jest` under the hood.

### Standard start (no watch)

```bash
npm run start
```

### Production build

```bash
npm run build          # compiles TypeScript to dist/
npm run start:prod     # runs the compiled output
```

### Via Docker

```bash
# From project root
docker compose up sa-projection
```

The Dockerfile's development stage mounts the source directory as a volume so edits on the host are picked up without rebuilding the image.

---

## Debugging

### Built-in Node.js inspector (VS Code / Chrome DevTools)

```bash
npm run start:debug
```

This starts NestJS with `--debug --watch`. The Node.js inspector listens on `0.0.0.0:9229`. Connect from:

- **VS Code**: add a launch configuration of type `node` with `"request": "attach"` and `"port": 9229`
- **Chrome DevTools**: open `chrome://inspect` → Remote Target → Configure → add `localhost:9229`

Full VS Code launch config example (`.vscode/launch.json`):

```json
{
  "version": "0.2.0",
  "configurations": [
    {
      "type": "node",
      "request": "attach",
      "name": "Attach to Projection Service",
      "port": 9229,
      "restart": true,
      "sourceMaps": true,
      "outFiles": ["${workspaceFolder}/dist/**/*.js"]
    }
  ]
}
```

### Logging

NestJS uses its own `Logger`. To increase verbosity, set `LOG_LEVEL=debug` in `.env` or pass it inline:

```bash
LOG_LEVEL=debug npm run start:dev
```

To log all Socket.IO events, set the `DEBUG` environment variable:

```bash
DEBUG=socket.io* npm run start:dev
```

This prints every socket connect, disconnect, emit, and room join to stderr.

### Inspecting Redis session state

The service stores session state under the key pattern `sa:proj:session:{sessionId}` and control tokens under `sa:proj:control-token:{sessionId}`. Both expire after 4 hours.

```bash
# Connect to Redis
redis-cli -h 127.0.0.1 -p 6379

# List all projection keys
KEYS sa:proj:*

# Read a session's state
GET sa:proj:session:<sessionId>

# Check the control token
GET sa:proj:control-token:<sessionId>

# Check TTL remaining
TTL sa:proj:session:<sessionId>
```

---

## Running Tests

Tests use **Jest** with `ts-jest` for TypeScript compilation.

### Run the full suite

```bash
npm test
```

### Run in watch mode (re-runs on file save)

```bash
npm run test:watch
```

### Run a single test file

```bash
npx jest src/sessions/sessions.service.spec.ts
```

### Run tests matching a description

```bash
npx jest --testNamePattern "should advance to the next slide"
```

### Run with coverage report

```bash
npm run test:cov
```

Coverage output is written to `coverage/`. Open `coverage/lcov-report/index.html` in a browser for the annotated source view.

---

## Investigating Failing Tests

### Verbose output

```bash
npm test -- --verbose
```

Prints each `it()` description and its pass/fail status instead of just the summary dot.

### Stop on first failure

```bash
npm test -- --bail
```

### Print to console inside a test

Use `console.log` freely — Jest captures but does not suppress it when the test fails. If you need to see output even on passing tests, run with `--verbose`.

### Inspecting the FakeRedis state

The test suite uses `FakeRedisService` (an in-memory stub that satisfies the `IRedisService` interface) instead of a real Redis connection. This means tests are hermetic — no external process required.

If a test is reading back unexpected values, add a direct call to `fakeRedis.get(key)` in the test body to dump the current in-memory state:

```typescript
// Inside a test
const raw = await fakeRedis.get(`sa:proj:session:${sessionId}`)
console.log(JSON.parse(raw!))
```

`FakeRedisService` is defined at `src/redis/redis.fake.ts`.

### WebSocket event tests

Gateway tests use `@nestjs/testing` `Test.createTestingModule()` with the real gateway class. Events are dispatched by calling gateway methods directly rather than via a live socket, so there is no network involved:

```typescript
// Call the gateway method directly
const result = await gateway.handleNext(socket, { sessionId, controlToken })
expect(result).toEqual({ event: 'session:state', data: expect.objectContaining({ currentIndex: 1 }) })
```

If you suspect a broadcast is not reaching the right room, add a spy on `server.to(roomId).emit` and assert its arguments.

---

## Code Quality

```bash
# Lint (report only)
npm run lint

# Lint with auto-fix
npm run lint:fix
```

The ESLint configuration is in `.eslintrc.json` using `@typescript-eslint` rules. TypeScript strict mode is enabled in `tsconfig.json` — `noImplicitAny` and `strictNullChecks` will catch untyped parameters and null-unsafe access at compile time:

```bash
# Type-check without building
npx tsc --noEmit
```
