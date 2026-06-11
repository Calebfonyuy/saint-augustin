<script setup lang="ts">
// Go-Live dialog — the worship leader's launch surface for projecting a
// playlist. Three modes:
//   1. Temporary       — fire-and-forget session that dies on exit (legacy).
//   2. Existing session — pick any session the caller can manage (their own,
//                         or any session if they're an admin), push this
//                         playlist's slides into it, and start it if it
//                         isn't already LIVE.
//   3. Persistent      — create a named/scheduled session, load the slides
//                         into it, and (optionally) start projecting now.
//
// Emits `(launched, sessionId)` once the user has either created a session
// or attached to an existing one — the parent view routes to the controller.
import { computed, onMounted, ref } from 'vue'
import Icon from './Icon.vue'
import { useAuthStore } from '@/stores/auth'
import { useProjectionStore } from '@/stores/projection'
import { extractErrorMessage } from '@/api/client'
import type { Playlist } from '@/types'

const props = defineProps<{
  playlist: Playlist
  open: boolean
}>()
const emit = defineEmits<{
  (e: 'close'): void
  (e: 'launched', sessionId: string): void
}>()

const auth = useAuthStore()
const projection = useProjectionStore()

type Mode = 'temporary' | 'existing' | 'persistent'

const mode = ref<Mode>('temporary')
const busy = ref(false)
const error = ref<string | null>(null)

// Form state for "persistent" mode
const sessionName = ref('')
const startAt = ref('')
const endAt = ref('')
const startNow = ref(true)

// Form state for "existing live" mode
const selectedSessionId = ref<string>('')

/**
 * Any session the caller can push slides into:
 *   • Admins can manage any non-ENDED session.
 *   • Other users only see their own non-ENDED sessions.
 * NOT_STARTED sessions are kept in the list — projecting to one is how a
 * leader brings a scheduled session live with this playlist's slides.
 */
const availableSessions = computed(() =>
  projection.sessions.filter(
    (s) =>
      s.status !== 'ENDED' &&
      (auth.isAdmin || s.ownerId === auth.user?.id),
  ),
)

onMounted(async () => {
  sessionName.value = props.playlist.name
  try {
    // Admins need everyone's sessions; non-admins only ever see their own.
    await projection.fetchSessions({ mine: !auth.isAdmin })
  } catch {
    /* non-fatal — user can still pick temporary or persistent */
  }
})

function toIso(local: string): string | undefined {
  if (!local) return undefined
  return new Date(local).toISOString()
}

async function onLaunch(): Promise<void> {
  error.value = null
  busy.value = true
  try {
    if (mode.value === 'temporary') {
      const created = await projection.createFromPlaylist(props.playlist)
      emit('launched', created.sessionId)
      return
    }
    if (mode.value === 'existing') {
      if (!selectedSessionId.value) {
        error.value = 'Pick a session to project to.'
        return
      }
      // Push slides first so the session is start-ready, then start it
      // if it's still NOT_STARTED. Already-LIVE sessions just take the
      // new deck and keep going.
      await projection.loadPlaylistInto(selectedSessionId.value, props.playlist)
      const chosen = availableSessions.value.find(
        (s) => s.id === selectedSessionId.value,
      )
      if (chosen && chosen.status === 'NOT_STARTED') {
        await projection.startPersistent(selectedSessionId.value)
      }
      emit('launched', selectedSessionId.value)
      return
    }
    // persistent
    const name = sessionName.value.trim() || props.playlist.name
    const created = await projection.createPersistent({
      name,
      playlistName: props.playlist.name,
      playlistId: props.playlist.id,
      scheduledStartAt: toIso(startAt.value),
      scheduledEndAt: toIso(endAt.value),
    })
    // Attach the slides now so the session is start-ready.
    await projection.loadPlaylistInto(created.sessionId, props.playlist)
    if (startNow.value) {
      const started = await projection.startPersistent(created.sessionId)
      emit('launched', started.sessionId)
    } else {
      emit('launched', created.sessionId)
    }
  } catch (err) {
    error.value = extractErrorMessage(err, 'Could not launch.')
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div
    v-if="open"
    class="fixed inset-0 z-30 grid place-items-center bg-black/30"
    role="dialog"
    aria-modal="true"
    aria-labelledby="go-live-title"
    data-testid="go-live-dialog"
    @click.self="emit('close')"
  >
    <div class="card w-[560px] max-w-[92vw] max-h-[90vh] flex flex-col">
      <div class="flex items-center justify-between px-5 py-4 border-b border-border">
        <div id="go-live-title" class="font-display font-semibold text-[18px]">
          Go Live
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

      <div class="px-5 py-4 flex flex-col gap-4 overflow-auto">
        <!-- Mode picker -->
        <div class="grid grid-cols-3 gap-2">
          <label
            class="card p-3 cursor-pointer text-[12px] flex flex-col gap-1"
            :class="{ 'border-accent text-accent': mode === 'temporary' }"
            data-testid="go-live-mode-temporary"
          >
            <input v-model="mode" type="radio" value="temporary" class="sr-only" />
            <span class="font-semibold">Temporary</span>
            <span class="text-text-faint">One-shot session. Ends when you exit.</span>
          </label>
          <label
            class="card p-3 cursor-pointer text-[12px] flex flex-col gap-1"
            :class="{ 'border-accent text-accent': mode === 'existing' }"
            data-testid="go-live-mode-existing"
          >
            <input v-model="mode" type="radio" value="existing" class="sr-only" />
            <span class="font-semibold">Existing session</span>
            <span class="text-text-faint">Push slides to a session you can manage. Scheduled sessions are started.</span>
          </label>
          <label
            class="card p-3 cursor-pointer text-[12px] flex flex-col gap-1"
            :class="{ 'border-accent text-accent': mode === 'persistent' }"
            data-testid="go-live-mode-persistent"
          >
            <input v-model="mode" type="radio" value="persistent" class="sr-only" />
            <span class="font-semibold">Create persistent session</span>
            <span class="text-text-faint">Name it. Optionally schedule start &amp; end.</span>
          </label>
        </div>

        <!-- Existing -->
        <div v-if="mode === 'existing'" class="flex flex-col gap-2">
          <label class="field-label" for="go-live-existing">Sessions you can project to</label>
          <select
            id="go-live-existing"
            v-model="selectedSessionId"
            class="input"
            data-testid="go-live-existing-select"
          >
            <option value="">— Pick one —</option>
            <option v-for="s in availableSessions" :key="s.id" :value="s.id">
              {{ s.name }}
              <template v-if="s.status === 'NOT_STARTED'"> · scheduled</template>
              <template v-else> · live</template>
              <template v-if="s.ownerName"> — by {{ s.ownerName }}</template>
            </option>
          </select>
          <p v-if="availableSessions.length === 0" class="text-[11px] text-text-faint">
            No sessions available. Create a persistent one or use Temporary.
          </p>
          <p
            v-else-if="
              selectedSessionId &&
              availableSessions.find((s) => s.id === selectedSessionId)?.status ===
                'NOT_STARTED'
            "
            class="text-[11px] text-text-faint"
          >
            This session is scheduled — projecting will start it now.
          </p>
        </div>

        <!-- Persistent -->
        <div v-if="mode === 'persistent'" class="flex flex-col gap-3">
          <div>
            <label class="field-label" for="go-live-name">Session name</label>
            <input
              id="go-live-name"
              v-model="sessionName"
              class="input"
              placeholder="Sunday 9:30 — Advent II"
              data-testid="go-live-name"
            />
          </div>
          <div class="grid grid-cols-2 gap-2">
            <div>
              <label class="field-label" for="go-live-start">Starts (optional)</label>
              <input
                id="go-live-start"
                v-model="startAt"
                type="datetime-local"
                class="input"
                data-testid="go-live-start"
              />
            </div>
            <div>
              <label class="field-label" for="go-live-end">Ends (optional)</label>
              <input
                id="go-live-end"
                v-model="endAt"
                type="datetime-local"
                class="input"
                data-testid="go-live-end"
              />
            </div>
          </div>
          <label class="flex items-center gap-2 text-[12px] text-text-muted">
            <input
              v-model="startNow"
              type="checkbox"
              data-testid="go-live-start-now"
            />
            Project now (otherwise the session stays scheduled, ready to start later)
          </label>
        </div>

        <p v-if="error" class="field-error" data-testid="go-live-error">{{ error }}</p>
      </div>

      <div class="flex items-center justify-end gap-2 px-5 py-4 border-t border-border">
        <button type="button" class="btn" @click="emit('close')">Cancel</button>
        <button
          type="button"
          class="btn btn-primary"
          :disabled="busy"
          data-testid="go-live-confirm"
          @click="onLaunch"
        >
          <Icon name="cast" />
          {{
            mode === 'temporary'
              ? 'Launch'
              : mode === 'existing'
                ? 'Project to session'
                : startNow
                  ? 'Create & project'
                  : 'Create session'
          }}
        </button>
      </div>
    </div>
  </div>
</template>
