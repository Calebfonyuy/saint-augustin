// Ref: https://router.vuejs.org/guide/
import { createRouter, createWebHistory } from 'vue-router'
import HomeView from '@/views/HomeView.vue'

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes: [
    {
      path: '/',
      name: 'home',
      component: HomeView,
    },
    // Phase 1: login, register, song-library, song-editor
    // Phase 2: musician-view
    // Phase 3: playlist-builder
    // Phase 4: projection-controller, projection-display
  ],
})

// Route guards for auth will be added in Phase 1
// router.beforeEach((to, from, next) => { ... })

export default router
