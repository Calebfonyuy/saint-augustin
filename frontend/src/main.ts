// App entry point — wires Pinia, router, global 401 handler, and the
// Axios-level unauthorized listener that forces re-login on stale tokens.
import { createApp } from 'vue'
import { createPinia } from 'pinia'
import App from './App.vue'
import router from './router'
import i18n from './i18n'
import { installUnauthorizedHandler } from '@/api/client'
import { useAuthStore } from '@/stores/auth'
import './assets/main.css'

const app = createApp(App)
const pinia = createPinia()

app.use(pinia)
app.use(router)
app.use(i18n)

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

// Kick off auth bootstrap (validates the stored token via /auth/refresh and
// hydrates `user`/roles) but don't block first paint on it — the token
// itself already hydrated synchronously into the store above, so
// isAuthenticated-gated routes render immediately. Role-gated routes
// (requiresAdmin/requiresEditor) are handled separately: the router's
// beforeEach guard awaits this same `ready()` promise before evaluating
// those checks, so this doesn't reintroduce the refresh-to-dashboard bounce.
const auth = useAuthStore()
void auth.ready()

app.mount('#app')
