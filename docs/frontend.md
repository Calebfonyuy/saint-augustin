# Frontend

The frontend is a Vue 3 single-page application served by Vite. It communicates with the Auth Service via a REST API and with the Projection Service via Socket.IO WebSocket.

- **Framework:** Vue 3 (Composition API)
- **Build tool:** Vite
- **Styling:** Tailwind CSS + design tokens in `src/assets/tokens.css`
- **State management:** Pinia
- **Routing:** Vue Router 4
- **Testing:** Vitest + Vue Test Utils

---

## Routing

Routes are defined in `src/router/index.ts`. Three meta flags control access:

| Meta flag | Effect |
|-----------|--------|
| `requiresAuth` | Unauthenticated users are redirected to `/login` (attempted path preserved in `?redirect=`) |
| `requiresAdmin` | Non-admin users are sent to `/` |
| `requiresEditor` | Users without `admin` or `musician` role are sent to `/` |
| `public: true` + `hideForAuthed: true` | Authenticated users are sent to `/` (login, register pages) |
| `public: true` (no `hideForAuthed`) | Publicly accessible with no auth check (share, projection display) |

### Auth bootstrap ordering

`main.ts` mounts the app immediately — it does not wait on `auth.init()`. The token
hydrates synchronously from `localStorage` into the auth store at creation, so
`isAuthenticated`-gated routes (`requiresAuth`, `hideForAuthed`) are correct from the
first render. `main.ts` also kicks off `auth.ready()`, a promise cached on the store
that wraps `init()` (which calls `/auth/refresh` to validate the token and populate
`user`/roles). The router's `beforeEach` guard awaits that same `ready()` promise
before evaluating `requiresAdmin`/`requiresEditor`, since those checks depend on
`user.roles`, which isn't available until `/auth/refresh` resolves. This avoids a
cold hard-refresh of an admin/editor route bouncing to `/` because roles hadn't
loaded yet.

### Route Map

| Path | View | Access |
|------|------|--------|
| `/login` | `LoginView` | Public, hidden for authed |
| `/register` | `RegisterView` | Public, hidden for authed |
| `/forgot-password` | `ForgotPasswordView` | Public, hidden for authed |
| `/reset-password` | `ResetPasswordView` | Public, hidden for authed |
| `/` | `DashboardView` | Auth required |
| `/account` | `AccountSettingsView` | Auth required |
| `/library` | `SongLibraryView` | Auth required |
| `/songs/new` | `SongEditorView` | Auth + editor role |
| `/songs/:id` | `SongEditorView` | Auth + editor role |
| `/songs/:id/play` | `MusicianView` | Auth required |
| `/admin` | — (redirects to `/admin/users`) | Auth + admin |
| `/admin/users` | `AdminUsersView` | Auth + admin |
| `/admin/songbooks` | `SongbookAdminView` | Auth + admin |
| `/admin/import` | `AdminImportView` | Auth + admin |
| `/playlists` | `PlaylistsListView` | Auth required |
| `/playlists/:id` | `PlaylistBuilderView` | Auth required |
| `/s/:token` | `SharedPlaylistView` | Public |
| `/sessions` | `SessionsListView` | Auth required |
| `/projection/control/:id` | `ProjectionControlView` | Auth required |
| `/projection/display/:id` | `ProjectionDisplayView` | Public |
| `/:pathMatch(.*)*` | `NotFound` | Public — renders for any unmatched URL |

---

## Pinia Stores

| Store | File | Responsibility |
|-------|------|----------------|
| `auth` | `stores/auth.ts` | User session, token persistence, role helpers (`isAdmin`, `canEditSongs`) |
| `songs` | `stores/songs.ts` | Song list, pagination, CRUD actions |
| `songbooks` | `stores/songbooks.ts` | Songbook list and admin actions |
| `songSheets` | `stores/songSheets.ts` | Sheet upload, list, and presigned URL management |
| `playlists` | `stores/playlists.ts` | Playlist CRUD, item management, share links, export |
| `projection` | `stores/projection.ts` | Projection session lifecycle, WebSocket state sync |

The `auth` store persists the Bearer token to `localStorage` so sessions survive page reloads. All other stores are in-memory only.

---

## API Layer

All HTTP calls go through `src/api/client.ts`, which is an Axios instance pre-configured with the base URL (`VITE_API_BASE_URL`) and an interceptor that attaches the `Authorization: Bearer {token}` header from the auth store on every request.

Resource modules:

| Module | Endpoint group |
|--------|---------------|
| `api/auth.ts` | `/api/auth/*` |
| `api/songs.ts` | `/api/songs` |
| `api/songbooks.ts` | `/api/songbooks` |
| `api/songSheets.ts` | `/api/songs/:id/sheets`, `/api/sheets/:id` |
| `api/playlists.ts` | `/api/playlists` |
| `api/shareLinks.ts` | Share link sub-resources |
| `api/invitations.ts` | `/api/auth/invitations` |
| `api/password.ts` | `/api/auth/password/*` |
| `api/projection.ts` | `/api/projection/sessions` |

---

## ChordPro Library (`src/lib/chordpro`)

A pure-TypeScript ChordPro parser and transposer used in the musician view and slide builder.

### Parser (`parser.ts`)

Parses ChordPro text into a structured token stream:

- **Directives** — `{title:}`, `{key:}`, `{start_of_chorus}`, `{soc}`, etc.
- **Chord tokens** — `[Am]`, `[G/B]` inline with lyrics
- **Lyric lines** — plain text between chords

The output is a list of `Section` objects, each containing `Line[]` of `Token[]`.

### Transpose (`transpose.ts`)

Shifts all chord tokens from one key to another. Handles:

- Major and minor keys
- Sharps vs flats (follows the target key's convention)
- Slash chords (e.g. `G/B` → `A/C#` when transposing up a tone)

The twelve chromatic steps are represented as two parallel arrays (sharps / flats) and the transpose function picks the correct spelling based on the target key signature.

---

## Projection Library (`src/lib/projection`)

Utilities for converting a playlist into a flat slide list for the Projection Service.

### `buildSlides.ts`

Takes a `Playlist` with items and returns `ProjectionSlide[]`. For each item it:

1. Parses the song's ChordPro lyrics.
2. Optionally transposes to the item's `target_key`.
3. Splits sections into individual slides, stripping chord tokens to leave clean lyrics.

### `slides.ts`

Helper types and pure functions for working with the slide array (e.g. finding which item a given slide index belongs to).

---

## Key Views

### `MusicianView`

Read-only song view for performers. Displays ChordPro lyrics with chords rendered above the corresponding syllables. Includes:

- Live transpose (key selector adjusts rendering without modifying stored data)
- Metronome with visual beat indicator and tap-tempo
- PreviewPlayer for audio preview URLs
- Sheet viewer (PDF/image) via MinIO presigned URLs

### `PlaylistBuilderView`

Full-featured playlist editor. Features:

- Drag-and-drop song ordering (or reorder via API)
- Per-item key and notes editor
- Inline song preview
- Share link management (create musician/projection links, revoke, copy URL)
- "Go Live" button that builds slides and creates a projection session

### `ProjectionControlView`

Worship leader control panel (requires auth). Connects to the Projection Service WebSocket as a controller using the `controlToken`. Provides:

- Next / Previous slide buttons
- Song jump list (jump directly to the first slide of any playlist item)
- Blackout toggle
- Font scale slider
- Live slide preview

### `ProjectionDisplayView`

Public projector screen (no auth, no navigation chrome). Connects as a display subscriber and renders the current slide full-screen. Handles:

- Automatic reconnection on WebSocket drop
- Blackout (blank screen)
- Font scale from session state
- QR / URL display when no session is active

### `SharedPlaylistView`

Public read-only playlist view accessed via a share link token. The mode (`musician` or `projection`) is determined by the share link. In musician mode the full song lyrics are shown; in projection mode only the setlist overview is visible.

### `AdminUsersView`

Admin-only user management (`/admin/users`). Lists registered users and pending invitations side-by-side (merged via `useUsersStore`'s `members` computed, an `AdminMember` discriminated union), with search, role filtering, invite, edit-roles, remove, resend/cancel invitation, bulk actions, and send-password-reset. Editing happens in the `UserEditorDrawer` slide-in panel (`components/admin/UserEditorDrawer.vue`).

The drawer proactively guards a signed-in admin against locking themselves out: their own "Remove from workspace" action is disabled and their own `admin` role checkbox is locked (each with inline explanatory copy), mirroring the backend's self-delete/self-demotion 409 guards in `UserController`.

---

## Components

| Component | Description |
|-----------|-------------|
| `AppShell` | Main layout: navigation sidebar, top bar, mobile hamburger |
| `ChordProPreview` | Renders ChordPro text with chord-above-lyric layout |
| `SlideRenderer` | Full-screen slide display for projection |
| `SheetViewer` | PDF/image viewer with presigned URL refresh logic |
| `ShareDialog` | Modal for creating and managing share links |
| `KeyBadge` | Pill showing a musical key (colour-coded by accidental type) |
| `Metronome` | Visual + audio metronome with BPM input and tap-tempo |
| `PreviewPlayer` | Minimal audio player for preview URLs |
| `Toast` | Notification system |
| `Icon` | Wrapper around heroicons SVGs |
| `BrandMark` | Logo component |

---

## Testing

Tests use Vitest with `@vue/test-utils` for component tests. Run all tests:

```bash
cd frontend
npm test
```

Test files live alongside the code they test in `__tests__` subdirectories.

---

## Environment Variables

| Variable | Description |
|----------|-------------|
| `VITE_API_BASE_URL` | REST API base URL — Auth Service directly, no gateway (e.g. `http://localhost:8000/api`) |
| `VITE_WS_URL` | WebSocket base URL (e.g. `ws://localhost:8000`) |
