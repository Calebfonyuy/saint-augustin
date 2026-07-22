# Frontend

Vue 3 + Vite + TypeScript. Single-page application served by Nginx in production. All API calls are proxied through the Nginx gateway; the frontend never talks directly to individual services.

## Prerequisites

- Node.js 20
- npm 10+
- The backend services (or at minimum the auth and projection services) running so the app has something to talk to

For local development the root `docker-compose.yml` starts everything. The instructions below assume you want to run the frontend **outside** Docker.

---

## Environment Setup

```bash
cd frontend
cp .env.example .env   # if an .env.example exists, otherwise create .env
```

Required environment variables:

| Variable | Purpose | Example |
|---|---|---|
| `API_BASE_URL` | Base URL for REST API calls | `http://localhost:8080/api` |
| `PROJECTION_WS_URL` | Socket.IO server URL for projection | `http://localhost:8080` |

`API_BASE_URL` should point at the Nginx gateway (port 8080), not at individual services. The gateway routes `/api/auth/*`, `/api/songs`, etc. to the right service internally.

Install dependencies:

```bash
npm install
```

---

## Starting the Dev Server

```bash
npm run dev
```

Vite starts on `http://localhost:5173` with HMR (hot module replacement). Changes to `.vue`, `.ts`, and `.css` files are reflected in the browser within milliseconds without a full page reload.

### Via Docker

```bash
# From project root
docker compose up sa-frontend
```

The Dockerfile's development stage mounts the source directory as a volume. The dev server runs inside the container and is accessible at `http://localhost:5173`.

---

## Building for Production

```bash
npm run build
```

This runs `vue-tsc -b` (type checking) then `vite build`. The output is written to `dist/`. In the Docker production stage, Nginx serves `dist/` with an SPA fallback (`try_files $uri /index.html`) so that Vue Router's history-mode URLs resolve correctly.

Preview the production build locally:

```bash
npm run preview
```

---

## Debugging

### Browser DevTools

The Vue Devtools browser extension (Chrome/Firefox) is the primary debugging tool. It provides:

- Component tree inspection with live reactive state
- Pinia store inspector (read and write store state from the panel)
- Router history and current route params
- Timeline of component events and state mutations

### Vite source maps

Source maps are enabled in development mode by default. Errors in the browser console and the Vue Devtools point to the original TypeScript / `.vue` source lines, not the compiled output.

### Inspecting Socket.IO events (projection)

Open the browser console and run:

```javascript
// After a projection session connects, the socket is stored on the store
window.__pinia.state.value.projection
```

Or add a temporary `console.log` inside `src/stores/projection.ts` to log every incoming socket event.

To see all Socket.IO traffic at the network level, open the Network tab, filter by `WS`, and inspect the WebSocket frames.

### Axios request/response logging

In `src/lib/api/client.ts`, the Axios instance has interceptors. Add a request interceptor temporarily:

```typescript
apiClient.interceptors.request.use(config => {
  console.log('[API]', config.method?.toUpperCase(), config.url, config.data)
  return config
})
```

### TypeScript errors

```bash
npm run type-check
```

Runs `vue-tsc --build --force`. Surfaces type errors in `.vue` templates and `.ts` files without producing any output files.

---

## Running Tests

Tests use **Vitest** with `@vue/test-utils` and run in a **jsdom** environment (simulated browser DOM).

### Run the full suite (single pass)

```bash
npm test
# expands to: vitest run
```

### Run in watch mode (re-runs on file save)

```bash
npm run test:watch
# expands to: vitest
```

### Run a single test file

```bash
npx vitest run src/components/__tests__/SlideRenderer.spec.ts
```

### Run tests matching a description

```bash
npx vitest run --reporter=verbose -t "renders each body line"
```

### Run with coverage report

```bash
npx vitest run --coverage
```

Coverage output is written to `coverage/`. Open `coverage/index.html` in a browser for the annotated source view. The `@vitest/coverage-v8` provider is used (no separate install needed).

---

## Investigating Failing Tests

### Verbose output

```bash
npx vitest run --reporter=verbose
```

Prints each `it()` description and its outcome instead of just a summary.

### Stop on first failure

```bash
npx vitest run --bail 1
```

### Print component HTML in a failing test

Add to the test body to dump what Vue Test Utils actually rendered:

```typescript
console.log(w.html())
```

This is the most effective first step when an assertion on a selector fails — it shows the actual rendered markup so you can see whether the element exists at all, whether the class name is different, etc.

### Inspect reactive store state

```typescript
import { useProjectionStore } from '@/stores/projection'
const store = useProjectionStore()
console.log(store.$state)
```

### JSDOM colour normalisation

JSDOM normalises CSS hex colours to `rgb()` format when reading back from `element.style.backgroundColor`. Always assert with the `rgb()` form:

```typescript
// Wrong — JSDOM won't store '#1a1a2e'
expect(el.style.backgroundColor).toBe('#1a1a2e')

// Correct
expect(el.style.backgroundColor).toBe('rgb(26, 26, 46)')
```

### Non-breaking spaces

`wrapper.text()` trims whitespace including `\u00a0` (non-breaking space). To assert that a line contains `&nbsp;`, read `element.textContent` directly:

```typescript
expect(lines[1].element.textContent).toBe('\u00a0')
```

### Keyboard events on `window`

The projection controller and display views attach `keydown` handlers to `window`, not to the component root. `wrapper.trigger('keydown', ...)` fires on the component root and will not reach a `window.addEventListener` handler in a detached JSDOM tree. Dispatch directly on the global:

```typescript
window.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowRight', bubbles: true }))
```

### Pinia actions not being called

Tests that use `createTestingPinia({ stubActions: true })` replace all store actions with `vi.fn()` stubs that return `undefined` by default. If the component checks the return value (e.g. `if (!result.ok)`), the stub must be configured first:

```typescript
vi.mocked(projection.connect).mockResolvedValue({ ok: true, role: 'controller', state: makeState() })
```

Do this **before** mounting the component, since `onMounted` may call the action immediately.

### Async state updates

After triggering a click or setting store state, reactive DOM updates are batched. Await `nextTick()` or `flushPromises()` before asserting:

```typescript
await wrapper.find('button').trigger('click')
await flushPromises()
expect(wrapper.find('.result').text()).toBe('done')
```

---

## Code Quality

```bash
# Lint (report only)
npm run lint

# Lint with auto-fix
npm run lint:fix
```

The ESLint configuration covers `.vue`, `.ts`, `.tsx`, `.js`, and `.mjs` files. TypeScript strict mode is on — failing the type-check also fails the production build.
