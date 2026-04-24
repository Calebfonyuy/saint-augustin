// LoginView covers the end-to-end success and failure paths — the user
// types credentials, presses submit, and we verify that the store's login
// is called and a redirect happens on success, or an error is shown on 401.
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createRouter, createMemoryHistory } from 'vue-router'
import { createTestingPinia } from '@pinia/testing'
import LoginView from '@/views/LoginView.vue'
import { useAuthStore } from '@/stores/auth'

function makeRouter() {
  return createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', component: { template: '<div>Dashboard</div>' } },
      { path: '/login', component: LoginView },
      { path: '/forgot-password', component: { template: '<div>Forgot</div>' } },
    ],
  })
}

describe('LoginView', () => {
  let router: ReturnType<typeof makeRouter>

  beforeEach(async () => {
    router = makeRouter()
    await router.push('/login')
    await router.isReady()
  })

  it('submits the form and redirects on success', async () => {
    const pinia = createTestingPinia({ stubActions: false, createSpy: vi.fn })
    const w = mount(LoginView, { global: { plugins: [router, pinia] } })
    const auth = useAuthStore()
    // Override the real login with a resolved stub.
    vi.spyOn(auth, 'login').mockResolvedValue()

    await w.find('#login-email').setValue('marie@example.com')
    await w.find('#login-password').setValue('secret')
    await w.find('form').trigger('submit.prevent')
    await flushPromises()

    expect(auth.login).toHaveBeenCalledWith('marie@example.com', 'secret')
    expect(router.currentRoute.value.path).toBe('/')
  })

  it('shows the error message when login fails', async () => {
    const pinia = createTestingPinia({ stubActions: false, createSpy: vi.fn })
    const w = mount(LoginView, { global: { plugins: [router, pinia] } })
    const auth = useAuthStore()
    vi.spyOn(auth, 'login').mockRejectedValue(
      Object.assign(new Error('Request failed'), {
        isAxiosError: true,
        response: { status: 401, data: { message: 'Invalid credentials.' } },
      }),
    )

    await w.find('#login-email').setValue('x@y.z')
    await w.find('#login-password').setValue('bad')
    await w.find('form').trigger('submit.prevent')
    await flushPromises()

    expect(w.find('[data-testid="login-error"]').exists()).toBe(true)
    // Router didn't navigate away on failure.
    expect(router.currentRoute.value.path).toBe('/login')
  })
})
