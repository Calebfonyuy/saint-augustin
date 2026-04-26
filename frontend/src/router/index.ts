// App routing with auth guards.
//
// Two guard rules:
//   - Any route with `meta.requiresAuth` sends unauthenticated users to
//     /login, preserving the attempted path in `?redirect=`.
//   - /login and /register redirect already-authenticated users to /.
//
// Admin-only routes additionally check the `admin` role; non-admins are
// bounced to / with no destructive navigation.
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
      path: '/admin/songbooks',
      name: 'admin-songbooks',
      component: () => import('@/views/SongbookAdminView.vue'),
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
      redirect: '/',
    },
  ],
})

router.beforeEach((to) => {
  const auth = useAuthStore()

  if (to.meta.requiresAuth && !auth.isAuthenticated) {
    return { name: 'login', query: { redirect: to.fullPath } }
  }

  if (to.meta.hideForAuthed && auth.isAuthenticated) {
    return { name: 'dashboard' }
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
