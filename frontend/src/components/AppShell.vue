<script setup lang="ts">
// App shell: 212px sidebar + scrollable main region on md+.
// On mobile (<md), the sidebar collapses into an off-canvas drawer that
// the user toggles from a hamburger button in a slim top bar. Closing on
// route change keeps it from staying in the way after a nav tap.
//
// Layout (md+):
//   ┌──────────┬───────────────────────┐
//   │ sidebar  │  <slot/> (scrollable) │
//   ├──────────┴───────────────────────┤
//   │ footer (account · copyright)     │
//   └──────────────────────────────────┘
//
// Layout (<md):
//   ┌──────────────────────────────────┐
//   │ topbar (hamburger · brand)       │
//   │ <slot/> (scrollable)             │
//   ├──────────────────────────────────┤
//   │ footer                           │
//   └──────────────────────────────────┘
//   + drawer (fixed overlay, slides in from left)
import { computed, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'
import BrandMark from './BrandMark.vue'
import Icon from './Icon.vue'
import LanguageSwitcher from './LanguageSwitcher.vue'

const router = useRouter()
const auth = useAuthStore()
const { t } = useI18n()

interface NavItem {
  id: string
  labelKey: string
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
  { id: 'dashboard', labelKey: 'nav.dashboard', icon: 'list', to: '/' },
  { id: 'library', labelKey: 'nav.library', icon: 'music', to: '/library' },
  { id: 'playlists', labelKey: 'nav.playlists', icon: 'list', to: '/playlists' },
  { id: 'projection', labelKey: 'nav.projection', icon: 'cast', to: '/playlists' },
  { id: 'admin', labelKey: 'nav.admin', icon: 'cog', to: '/admin/users', adminOnly: true },
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
  if (!roles.length) return t('common.guest')
  return roles.map((r) => r[0].toUpperCase() + r.slice(1)).join(' · ')
})

const year = new Date().getFullYear()

// Mobile drawer state. Closed by default — desktop layout ignores this flag
// because the nav is statically positioned at md+ via the responsive classes.
const mobileOpen = ref(false)

// Close drawer whenever the route changes — tapping a nav item navigates,
// then this watch hides the overlay so the user lands on the new view
// without having to dismiss the drawer first.
watch(
  () => router.currentRoute.value.fullPath,
  () => {
    mobileOpen.value = false
  },
)

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
  <!--
    Two-row outer grid: workspace fills the rest of the viewport, footer is a
    fixed-height row pinned at the bottom by the layout itself.
  -->
  <div class="w-full h-full bg-bg text-text grid grid-rows-[1fr_48px]">
    <div class="grid grid-cols-1 md:grid-cols-[212px_1fr] min-h-0 relative">
      <!-- Sidebar: static grid item at md+, off-canvas drawer below md. -->
      <nav
        class="flex flex-col min-h-0 border-r border-border bg-bg
               max-md:fixed max-md:inset-y-0 max-md:left-0 max-md:z-40 max-md:w-[260px]
               max-md:shadow-lg max-md:transition-transform max-md:duration-200"
        :class="mobileOpen ? 'max-md:translate-x-0' : 'max-md:-translate-x-full'"
        :aria-hidden="mobileOpen ? 'false' : undefined"
      >
        <div class="px-[16px] pt-[18px] pb-2 flex items-center justify-between">
          <BrandMark />
          <button
            type="button"
            class="md:hidden p-1 rounded text-text-faint hover:text-text"
            :aria-label="t('common.closeMenu')"
            @click="mobileOpen = false"
          >
            <Icon name="x" />
          </button>
        </div>
        <div class="flex-1 min-h-0 overflow-y-auto px-[10px] pb-[10px] flex flex-col gap-3">
          <div class="mono uppercase tracking-[0.14em] text-[10px] text-text-faint px-2 pt-2 pb-[2px]">
            {{ t('nav.workspace') }}
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
              <span>{{ t(item.labelKey) }}</span>
              <span v-if="item.disabled" class="ml-auto text-[9px] tracking-[0.14em] uppercase">{{ t('nav.soon') }}</span>
            </button>
          </div>
        </div>
      </nav>

      <!-- Backdrop: only present below md and only when drawer is open. -->
      <div
        v-if="mobileOpen"
        class="md:hidden fixed inset-0 z-30 bg-black/40"
        aria-hidden="true"
        @click="mobileOpen = false"
      />

      <main class="flex flex-col min-w-0 min-h-0 overflow-hidden">
        <!-- Mobile-only top bar with the hamburger trigger. -->
        <div
          class="md:hidden flex items-center gap-3 px-3 h-12 border-b border-border shrink-0"
        >
          <button
            type="button"
            class="p-2 -ml-2 rounded text-text-muted hover:text-text"
            :aria-label="t('common.openMenu')"
            @click="mobileOpen = true"
          >
            <Icon name="menu" :size="18" />
          </button>
          <BrandMark :size="16" />
        </div>
        <slot />
      </main>
    </div>
    <footer
      class="border-t border-border bg-bg flex items-center justify-between px-[18px] gap-5"
    >
      <div class="flex items-center gap-[10px] min-w-0">
        <button
          type="button"
          class="flex items-center gap-[10px] min-w-0 border-0 bg-transparent p-0 cursor-pointer text-left rounded hover:opacity-80 transition-opacity"
          :aria-label="t('common.accountSettings')"
          @click="goToAccount"
        >
          <div
            class="w-[26px] h-[26px] rounded-full bg-accent-soft text-accent grid place-items-center text-[11px] font-bold shrink-0"
          >
            {{ initials }}
          </div>
          <div class="hidden min-w-0 sm:block">
            <div class="text-[12px] font-semibold text-text leading-tight truncate">
              {{ auth.user?.display_name ?? t('common.anonymous') }}
            </div>
            <div class="text-[10.5px] text-text-faint truncate">{{ roleSummary }}</div>
          </div>
        </button>
        <button
          type="button"
          class="p-1 rounded text-text-faint hover:text-text shrink-0"
          :aria-label="t('common.logOut')"
          @click="onLogout"
        >
          <Icon name="logout" />
        </button>
      </div>
      <div class="flex items-center gap-3 min-w-0">
        <div class="hidden sm:block text-[11px] text-text-faint truncate">
          &copy; {{ year }} STAUG &middot; Saint Augustin &middot; {{ t('common.allRightsReserved') }}
        </div>
        <LanguageSwitcher direction="up" align="right" />
      </div>
    </footer>
  </div>
</template>
