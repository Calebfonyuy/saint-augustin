<script setup lang="ts">
// Share dialog — list, create, and revoke share links for a playlist.
// Surfaces both modes (musician/projection) with a copyable public URL and
// optional expiry. Revoking marks the link as revoked server-side; the public
// resolve endpoint will then 404 the token.
import { computed, onMounted, ref } from 'vue'
import {
  createShareLink,
  listShareLinks,
  revokeShareLink,
} from '@/api/shareLinks'
import { extractErrorMessage } from '@/api/client'
import Icon from './Icon.vue'
import type { ShareLink, ShareMode } from '@/types'

const props = defineProps<{ playlistId: string; open: boolean }>()
const emit = defineEmits<(e: 'close') => void>()

const links = ref<ShareLink[]>([])
const loading = ref(false)
const creating = ref(false)
const error = ref<string | null>(null)
const copiedToken = ref<string | null>(null)

const mode = ref<ShareMode>('musician')
const expiresAt = ref<string>('') // YYYY-MM-DD or ''

const activeLinks = computed(() => links.value.filter((l) => !l.revoked_at))

async function refresh(): Promise<void> {
  loading.value = true
  error.value = null
  try {
    links.value = await listShareLinks(props.playlistId)
  } catch (err) {
    error.value = extractErrorMessage(err, 'Could not load share links.')
  } finally {
    loading.value = false
  }
}

async function onCreate(): Promise<void> {
  creating.value = true
  error.value = null
  try {
    const expires = expiresAt.value
      ? new Date(`${expiresAt.value}T23:59:59Z`).toISOString()
      : null
    const created = await createShareLink(props.playlistId, mode.value, expires)
    links.value = [created, ...links.value]
    expiresAt.value = ''
  } catch (err) {
    error.value = extractErrorMessage(err, 'Could not create share link.')
  } finally {
    creating.value = false
  }
}

async function onRevoke(id: string): Promise<void> {
  if (!confirm('Revoke this link? Anyone using it will lose access.')) return
  try {
    await revokeShareLink(id)
    links.value = links.value.map((l) =>
      l.id === id ? { ...l, revoked_at: new Date().toISOString() } : l,
    )
  } catch (err) {
    error.value = extractErrorMessage(err, 'Could not revoke link.')
  }
}

function publicUrl(link: ShareLink): string {
  return `${window.location.origin}/s/${link.token}`
}

async function copy(link: ShareLink): Promise<void> {
  try {
    await navigator.clipboard.writeText(publicUrl(link))
    copiedToken.value = link.token
    setTimeout(() => {
      if (copiedToken.value === link.token) copiedToken.value = null
    }, 1500)
  } catch {
    // Clipboard unavailable (insecure context); user can copy from the input.
  }
}

function formatDate(iso: string | null): string {
  if (!iso) return '—'
  return new Date(iso).toLocaleDateString()
}

onMounted(refresh)
</script>

<template>
  <div
    v-if="open"
    class="fixed inset-0 z-30 grid place-items-center bg-black/30"
    role="dialog"
    aria-modal="true"
    aria-labelledby="share-dialog-title"
    data-testid="share-dialog"
    @click.self="emit('close')"
  >
    <div class="card w-[520px] max-w-[92vw] max-h-[80vh] flex flex-col">
      <div class="flex items-center justify-between px-5 py-4 border-b border-border">
        <div id="share-dialog-title" class="font-display font-semibold text-[18px]">
          Share playlist
        </div>
        <button
          type="button"
          class="btn btn-ghost"
          aria-label="Close"
          @click="emit('close')"
        >
          <Icon name="x" />
        </button>
      </div>

      <div class="px-5 py-4 border-b border-border flex flex-col gap-3">
        <div class="grid grid-cols-2 gap-2">
          <div>
            <label class="field-label" for="share-mode">Mode</label>
            <select id="share-mode" v-model="mode" class="input">
              <option value="musician">Musician (lyrics + chords)</option>
              <option value="projection">Projection (lyrics only)</option>
            </select>
          </div>
          <div>
            <label class="field-label" for="share-expires">Expires (optional)</label>
            <input
              id="share-expires"
              v-model="expiresAt"
              type="date"
              class="input"
            />
          </div>
        </div>
        <button
          type="button"
          class="btn btn-primary self-start"
          :disabled="creating"
          data-testid="share-create"
          @click="onCreate"
        >
          <Icon name="plus" /> {{ creating ? 'Creating…' : 'Create link' }}
        </button>
        <p v-if="error" class="field-error" data-testid="share-error">{{ error }}</p>
      </div>

      <div class="flex-1 overflow-auto px-5 py-4 flex flex-col gap-3">
        <div v-if="loading" class="text-text-faint text-[12px]">Loading…</div>
        <div
          v-else-if="activeLinks.length === 0"
          class="text-text-faint text-[12px]"
          data-testid="share-empty"
        >
          No active share links yet.
        </div>
        <div
          v-for="link in activeLinks"
          :key="link.id"
          class="card p-3 flex flex-col gap-2"
          data-testid="share-link-row"
        >
          <div class="flex items-center gap-2">
            <span
              class="chip"
              :class="link.mode === 'projection' ? 'chip-accent' : ''"
            >
              {{ link.mode }}
            </span>
            <span class="text-[11px] text-text-faint">
              expires {{ formatDate(link.expires_at) }}
            </span>
            <button
              type="button"
              class="btn btn-danger ml-auto"
              style="padding: 4px 8px; font-size: 11px"
              data-testid="share-revoke"
              @click="onRevoke(link.id)"
            >
              Revoke
            </button>
          </div>
          <div class="flex items-center gap-2">
            <input
              :value="publicUrl(link)"
              readonly
              class="input mono"
              style="font-size: 11.5px"
              @focus="(e) => (e.target as HTMLInputElement).select()"
            />
            <button
              type="button"
              class="btn"
              style="padding: 6px 10px; font-size: 11px"
              data-testid="share-copy"
              @click="copy(link)"
            >
              <Icon :name="copiedToken === link.token ? 'check' : 'list'" />
              {{ copiedToken === link.token ? 'Copied' : 'Copy' }}
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
