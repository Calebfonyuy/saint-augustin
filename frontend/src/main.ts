// App entry point — wires Pinia, router, global 401 handler, and the
// Axios-level unauthorized listener that forces re-login on stale tokens.
import { createApp } from 'vue'
import { createPinia } from 'pinia'
import App from './App.vue'
import router from './router'
import { installUnauthorizedHandler } from '@/api/client'
import { useAuthStore } from '@/stores/auth'
import './assets/main.css'

const app = createApp(App)
const pinia = createPinia()

app.use(pinia)
app.use(router)

// Install the 401 interceptor after Pinia so the store is accessible.
// We dispatch through the router rather than doing a hard reload so the
// redirect=... query survives.
installUnauthorizedHandler(() => {
  const auth = useAuthStore()
  auth.clearLocal()
  const current = router.currentRoute.value
  if (current.meta.requiresAuth) {
    router.push({ name: 'login', query: { redirect: current.fullPath } })
  }
})

// Hydrate the auth store before the first navigation resolves so that guards
// requiring `user.roles` (admin-only routes) have the user object available.
// The token itself is already hydrated synchronously inside the store, so even
// if init() (which calls /auth/refresh) is slow, the basic isAuthenticated
// check passes and protected routes don't redirect to /login on hard refresh.
const auth = useAuthStore()
auth.init().finally(() => {
  app.mount('#app')
})
