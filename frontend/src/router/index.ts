// App routing with auth guards.
//
// Guard rules:
//   - Any route with `meta.requiresAuth` sends unauthenticated users to
//     /login, preserving the attempted path in `?redirect=`.
//   - /login and /register redirect already-authenticated users to /.
//   - Admin/editor-only routes additionally check roles; unauthorized users
//     are bounced to / with no destructive navigation. Role checks await
//     auth.ready() first since `user.roles` is only populated once the
//     background /auth/refresh kicked off in main.ts resolves.
//   - Unknown URLs render NotFound.vue via the catch-all route.
//
// Ref: https://router.vuejs.org/guide/advanced/navigation-guards.html
import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes: [
    {
      path: '/login',
      name: 'login',
      component: () => import('@/views/LoginView.vue'),
      meta: { public: true, hideForAuthed: true },
    },
    {
      path: '/register',
      name: 'register',
      component: () => import('@/views/RegisterView.vue'),
      meta: { public: true, hideForAuthed: true },
    },
    {
      path: '/forgot-password',
      name: 'forgot-password',
      component: () => import('@/views/ForgotPasswordView.vue'),
      meta: { public: true, hideForAuthed: true },
    },
    {
      path: '/reset-password',
      name: 'reset-password',
      component: () => import('@/views/ResetPasswordView.vue'),
      meta: { public: true, hideForAuthed: true },
    },
    {
      path: '/',
      name: 'dashboard',
      component: () => import('@/views/DashboardView.vue'),
      meta: { requiresAuth: true },
    },
    {
      // Self-service profile settings: any signed-in user can change their
      // own display name and password without going through an admin.
      path: '/account',
      name: 'account-settings',
      component: () => import('@/views/AccountSettingsView.vue'),
      meta: { requiresAuth: true },
    },
    {
      path: '/library',
      name: 'library',
      component: () => import('@/views/SongLibraryView.vue'),
      meta: { requiresAuth: true },
    },
    {
      path: '/songs/new',
      name: 'song-new',
      component: () => import('@/views/SongEditorView.vue'),
      meta: { requiresAuth: true, requiresEditor: true },
    },
    {
      path: '/songs/:id',
      name: 'song-edit',
      component: () => import('@/views/SongEditorView.vue'),
      meta: { requiresAuth: true, requiresEditor: true },
    },
    {
      path: '/songs/:id/play',
      name: 'song-play',
      component: () => import('@/views/MusicianView.vue'),
      meta: { requiresAuth: true },
    },
    {
      // Default admin landing — Users is the first tab in the prototype.
      path: '/admin',
      redirect: '/admin/users',
    },
    {
      path: '/admin/users',
      name: 'admin-users',
      component: () => import('@/views/AdminUsersView.vue'),
      meta: { requiresAuth: true, requiresAdmin: true },
    },
    {
      path: '/admin/songbooks',
      name: 'admin-songbooks',
      component: () => import('@/views/SongbookAdminView.vue'),
      meta: { requiresAuth: true, requiresAdmin: true },
    },
    {
      path: '/admin/import',
      name: 'admin-import',
      component: () => import('@/views/AdminImportView.vue'),
      meta: { requiresAuth: true, requiresAdmin: true },
    },
    {
      path: '/admin/bible',
      name: 'admin-bible',
      component: () => import('@/views/AdminBibleView.vue'),
      meta: { requiresAuth: true, requiresAdmin: true },
    },
    {
      path: '/playlists',
      name: 'playlists',
      component: () => import('@/views/PlaylistsListView.vue'),
      meta: { requiresAuth: true },
    },
    {
      path: '/playlists/:id',
      name: 'playlist-builder',
      component: () => import('@/views/PlaylistBuilderView.vue'),
      meta: { requiresAuth: true },
    },
    {
      // Public share view — no auth guard, no AppShell. The token is the
      // sole authorisation token; the server returns 404 if it's invalid.
      path: '/s/:token',
      name: 'shared-playlist',
      component: () => import('@/views/SharedPlaylistView.vue'),
      meta: { public: true },
    },
    {
      // Sessions index — list NOT_STARTED + LIVE sessions, create persistent
      // sessions, start/end/share.
      path: '/sessions',
      name: 'sessions',
      component: () => import('@/views/SessionsListView.vue'),
      meta: { requiresAuth: true },
    },
    {
      // Projection controller (worship leader). Requires auth and pulls
      // the controlToken from the projection store after Go Live.
      path: '/projection/control/:id',
      name: 'projection-control',
      component: () => import('@/views/ProjectionControlView.vue'),
      meta: { requiresAuth: true },
    },
    {
      // Public projector view: no auth, no chrome. The session id is
      // the sole authorisation token (un-guessable UUID). Operators load
      // this URL on the projector machine or in a second browser window.
      path: '/projection/display/:id',
      name: 'projection-display',
      component: () => import('@/views/ProjectionDisplayView.vue'),
      meta: { public: true },
    },
    {
      path: '/:pathMatch(.*)*',
      name: 'not-found',
      component: () => import('@/views/NotFound.vue'),
      meta: { public: true },
    },
  ],
})

router.beforeEach(async (to) => {
  const auth = useAuthStore()

  if (to.meta.requiresAuth && !auth.isAuthenticated) {
    return { name: 'login', query: { redirect: to.fullPath } }
  }

  if (to.meta.hideForAuthed && auth.isAuthenticated) {
    return { name: 'dashboard' }
  }

  if (to.meta.requiresAdmin || to.meta.requiresEditor) {
    // user.roles is only populated once /auth/refresh resolves — wait for
    // the (cached, idempotent) auth bootstrap before evaluating role
    // guards so a cold hard-refresh of an admin/editor route doesn't see
    // stale (empty) roles and bounce to dashboard.
    await auth.ready()

    // ready() may have discovered the stored token was dead and cleared
    // auth state after the requiresAuth check above already passed on the
    // stale token — re-check so we land on /login, not a fake-authed /.
    if (to.meta.requiresAuth && !auth.isAuthenticated) {
      return { name: 'login', query: { redirect: to.fullPath } }
    }
  }

  if (to.meta.requiresAdmin && !auth.isAdmin) {
    return { name: 'dashboard' }
  }

  if (to.meta.requiresEditor && !auth.canEditSongs) {
    return { name: 'dashboard' }
  }

  return true
})

export default router
