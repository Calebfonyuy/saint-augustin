<script setup lang="ts">
// App shell: fixed 212px sidebar + scrollable main region.
// Ported from the Direction-A prototype (saint-augustin-design/proto-screens.jsx).
// Phase 1 only exposes the routes that actually exist; the others are surfaced
// as disabled items so the nav doesn't restructure when later phases land.
import { computed } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import BrandMark from './BrandMark.vue'
import Icon from './Icon.vue'

const router = useRouter()
const auth = useAuthStore()

interface NavItem {
  id: string
  label: string
  icon:
    | 'list'
    | 'music'
    | 'cast'
    | 'cog'
    | 'user'
  to?: string
  disabled?: boolean
  adminOnly?: boolean
}

const items = computed<NavItem[]>(() => [
  { id: 'dashboard', label: 'Dashboard', icon: 'list', to: '/' },
  { id: 'library', label: 'Song Library', icon: 'music', to: '/library' },
  { id: 'playlists', label: 'Playlists', icon: 'list', to: '/playlists' },
  { id: 'projection', label: 'Projection', icon: 'cast', to: '/playlists' },
  { id: 'admin', label: 'Admin', icon: 'cog', to: '/admin/users', adminOnly: true },
])

const activeId = computed(() => {
  const path = router.currentRoute.value.path
  if (path.startsWith('/library') || path.startsWith('/songs')) return 'library'
  if (path.startsWith('/projection')) return 'projection'
  if (path.startsWith('/playlists')) return 'playlists'
  if (path.startsWith('/admin')) return 'admin'
  if (path === '/' || path.startsWith('/dashboard')) return 'dashboard'
  return ''
})

const initials = computed(() => {
  const name = auth.user?.display_name ?? '?'
  return name
    .split(/\s+/)
    .map((s) => s[0])
    .slice(0, 2)
    .join('')
    .toUpperCase()
})

const roleSummary = computed(() => {
  const roles = auth.user?.roles ?? []
  if (!roles.length) return 'Guest'
  return roles.map((r) => r[0].toUpperCase() + r.slice(1)).join(' · ')
})

const year = new Date().getFullYear()

async function onLogout() {
  await auth.logout()
  await router.push({ name: 'login' })
}

function go(item: NavItem) {
  if (item.disabled || !item.to) return
  router.push(item.to)
}

function goToAccount() {
  router.push('/account')
}
</script>

<template>
  <div class="relative w-full h-full overflow-hidden bg-bg text-text">
    <div class="h-full grid grid-cols-[212px_1fr]">
      <nav class="flex flex-col min-h-0 border-r border-border">
        <div class="px-[16px] pt-[18px] pb-2">
          <BrandMark />
        </div>
        <div class="flex-1 min-h-0 overflow-y-auto px-[10px] pb-[10px] flex flex-col gap-3">
          <div class="mono uppercase tracking-[0.14em] text-[10px] text-text-faint px-2 pt-2 pb-[2px]">
            Workspace
          </div>
          <div class="flex flex-col gap-[2px]">
            <button
              v-for="item in items"
              v-show="!item.adminOnly || auth.isAdmin"
              :key="item.id"
              :disabled="item.disabled"
              :aria-current="activeId === item.id ? 'page' : undefined"
              class="flex items-center gap-[10px] px-[10px] py-[9px] rounded-[7px] text-[13px] text-left border-0 bg-transparent cursor-pointer transition-colors"
              :class="[
                activeId === item.id ? 'bg-accent-soft text-accent font-semibold' : 'text-text-muted font-medium hover:bg-bg-sunken',
                item.disabled ? 'opacity-40 cursor-not-allowed hover:bg-transparent' : '',
              ]"
              @click="go(item)"
            >
              <Icon :name="item.icon" />
              <span>{{ item.label }}</span>
              <span v-if="item.disabled" class="ml-auto text-[9px] tracking-[0.14em] uppercase">soon</span>
            </button>
          </div>
        </div>
      </nav>
      <main class="flex flex-col min-w-0 min-h-0 overflow-hidden">
        <slot />
      </main>
    </div>
    <footer
      class="fixed-bottom left-0 right-0 h-[48px] border-t border-border bg-bg z-20 flex items-center justify-between px-[18px] gap-5"
    >
      <div class="flex items-center gap-[10px] min-w-0">
        <button
          type="button"
          class="flex items-center gap-[10px] min-w-0 border-0 bg-transparent p-0 cursor-pointer text-left rounded hover:opacity-80 transition-opacity"
          aria-label="Account settings"
          @click="goToAccount"
        >
          <div
            class="w-[26px] h-[26px] rounded-full bg-accent-soft text-accent grid place-items-center text-[11px] font-bold shrink-0"
          >
            {{ initials }}
          </div>
          <div class="hidden min-w-0 sm:block">
            <div class="text-[12px] font-semibold text-text leading-tight truncate">
              {{ auth.user?.display_name ?? 'Anonymous' }}
            </div>
            <div class="text-[10.5px] text-text-faint truncate">{{ roleSummary }}</div>
          </div>
        </button>
        <button
          type="button"
          class="p-1 rounded text-text-faint hover:text-text shrink-0"
          aria-label="Log out"
          @click="onLogout"
        >
          <Icon name="logout" />
        </button>
      </div>
      <div class="text-[11px] text-text-faint truncate">
        &copy; {{ year }} STAUG &middot; Saint Augustin &middot; All rights reserved
      </div>
    </footer>
  </div>
</template>
