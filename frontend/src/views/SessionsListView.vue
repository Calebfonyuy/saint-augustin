<script setup lang="ts">
// Sessions index — lists NOT_STARTED + LIVE projection sessions for the
// whole congregation. Surfaces the actions a user has on each session:
//   • Share (QR code + URL)
//   • Start (NOT_STARTED only, owner or admin)
//   • Project (LIVE only — joins as controller if you own the session)
//   • End   (LIVE only, owner or admin)
//   • Delete (any status, owner or admin)
//
// Also exposes a "Create persistent session" form so a leader can schedule
// a session in advance and share the link before the service starts.
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import AppShell from '@/components/AppShell.vue'
import Icon from '@/components/Icon.vue'
import SessionShareDialog from '@/components/SessionShareDialog.vue'
import Toast from '@/components/Toast.vue'
import { extractErrorMessage } from '@/api/client'
import { useAuthStore } from '@/stores/auth'
import { useProjectionStore } from '@/stores/projection'
import type { ProjectionSessionSummary } from '@/types'

const router = useRouter()
const auth = useAuthStore()
const projection = useProjectionStore()
const { t } = useI18n()

const error = ref<string | null>(null)
const success = ref<string | null>(null)
const mineOnly = ref(false)
const sharingFor = ref<ProjectionSessionSummary | null>(null)

// Create form
const newName = ref('')
const newPlaylistName = ref('')
const newStart = ref('')
const newEnd = ref('')
const creating = ref(false)

async function refresh(): Promise<void> {
  try {
    await projection.fetchSessions({ mine: mineOnly.value })
  } catch (err) {
    error.value = extractErrorMessage(err, t('sessions.errors.load'))
  }
}

onMounted(refresh)

function canManage(s: ProjectionSessionSummary): boolean {
  if (auth.isAdmin) return true
  return !!auth.user && s.ownerId === auth.user.id
}

function formatDateTime(iso: string | null): string {
  if (!iso) return '—'
  return new Date(iso).toLocaleString()
}

function statusClass(status: string): string {
  switch (status) {
    case 'LIVE':
      return 'bg-green-100 text-green-800'
    case 'NOT_STARTED':
      return 'bg-amber-100 text-amber-800'
    default:
      return 'bg-bg-sunken text-text-muted'
  }
}

// ── Create persistent session ──────────────────────────────────────

function toIsoOrUndefined(local: string): string | undefined {
  if (!local) return undefined
  // <input type="datetime-local"> gives "YYYY-MM-DDTHH:mm" in local time.
  return new Date(local).toISOString()
}

async function onCreate(): Promise<void> {
  const name = newName.value.trim()
  const playlistName = newPlaylistName.value.trim() || name
  if (!name) return
  creating.value = true
  error.value = null
  try {
    const created = await projection.createPersistent({
      name,
      playlistName,
      scheduledStartAt: toIsoOrUndefined(newStart.value),
      scheduledEndAt: toIsoOrUndefined(newEnd.value),
    })
    success.value = t('sessions.toast.created', { name: created.state.name })
    newName.value = ''
    newPlaylistName.value = ''
    newStart.value = ''
    newEnd.value = ''
    await refresh()
  } catch (err) {
    error.value = extractErrorMessage(err, t('sessions.errors.create'))
  } finally {
    creating.value = false
  }
}

// ── Row actions ────────────────────────────────────────────────────

async function onStart(s: ProjectionSessionSummary): Promise<void> {
  try {
    const started = await projection.startPersistent(s.id)
    await router.push({ name: 'projection-control', params: { id: started.sessionId } })
  } catch (err) {
    error.value = extractErrorMessage(
      err,
      s.slideCount === 0
        ? t('sessions.errors.startEmpty')
        : t('sessions.errors.start'),
    )
  }
}

async function onProject(s: ProjectionSessionSummary): Promise<void> {
  // For LIVE sessions, the user owns the controlToken only if they started
  // the session in the current browser session — we don't persist it. So
  // we route them to the control view in view-only mode unless we still
  // have the token in the projection store from a previous Go-Live.
  await router.push({ name: 'projection-control', params: { id: s.id } })
}

async function onEnd(s: ProjectionSessionSummary): Promise<void> {
  if (!confirm(t('sessions.confirmEnd', { name: s.name }))) return
  try {
    await projection.endById(s.id)
    success.value = t('sessions.toast.ended', { name: s.name })
    await refresh()
  } catch (err) {
    error.value = extractErrorMessage(err, t('sessions.errors.end'))
  }
}

async function onDelete(s: ProjectionSessionSummary): Promise<void> {
  if (!confirm(t('sessions.confirmDelete', { name: s.name }))) return
  try {
    await projection.deleteById(s.id)
    success.value = t('sessions.toast.deleted', { name: s.name })
    await refresh()
  } catch (err) {
    error.value = extractErrorMessage(err, t('sessions.errors.delete'))
  }
}

const liveCount = computed(
  () => projection.sessions.filter((s) => s.status === 'LIVE').length,
)
const notStartedCount = computed(
  () => projection.sessions.filter((s) => s.status === 'NOT_STARTED').length,
)
</script>

<template>
  <AppShell>
    <div class="px-6 py-4 border-b border-border flex items-center gap-3 flex-wrap">
      <div class="font-display font-semibold text-[20px]">{{ t('sessions.title') }}</div>
      <div class="text-[12px] text-text-faint mono uppercase tracking-[0.12em]">
        {{ t('sessions.headerCounts', { live: liveCount, scheduled: notStartedCount }) }}
      </div>
      <div class="flex-1" />
      <label class="flex items-center gap-2 text-[12px] text-text-muted">
        <input
          v-model="mineOnly"
          type="checkbox"
          data-testid="sessions-mine"
          @change="refresh"
        />
        {{ t('playlistsList.onlyMine') }}
      </label>
      <button
        type="button"
        class="btn"
        data-testid="sessions-refresh"
        @click="refresh"
      >
        {{ t('common.refresh') }}
      </button>
    </div>

    <!-- Create persistent session -->
    <div class="px-6 py-4 border-b border-border">
      <div class="font-display font-semibold text-[15px] mb-3">
        {{ t('sessions.create.title') }}
      </div>
      <div class="grid grid-cols-1 md:grid-cols-[1fr_1fr_1fr_1fr_auto] gap-2 items-end">
        <div>
          <label class="field-label" for="new-session-name">{{ t('sessions.create.name') }}</label>
          <input
            id="new-session-name"
            v-model="newName"
            class="input"
            :placeholder="t('sessions.create.namePlaceholder')"
            data-testid="sessions-new-name"
            @keydown.enter="onCreate"
          />
        </div>
        <div>
          <label class="field-label" for="new-session-playlist">{{ t('sessions.create.displayLabel') }}</label>
          <input
            id="new-session-playlist"
            v-model="newPlaylistName"
            class="input"
            :placeholder="t('sessions.create.displayLabelPlaceholder')"
            data-testid="sessions-new-playlist"
          />
        </div>
        <div>
          <label class="field-label" for="new-session-start">{{ t('sessions.create.starts') }}</label>
          <input
            id="new-session-start"
            v-model="newStart"
            type="datetime-local"
            class="input"
            data-testid="sessions-new-start"
          />
        </div>
        <div>
          <label class="field-label" for="new-session-end">{{ t('sessions.create.ends') }}</label>
          <input
            id="new-session-end"
            v-model="newEnd"
            type="datetime-local"
            class="input"
            data-testid="sessions-new-end"
          />
        </div>
        <button
          type="button"
          class="btn btn-primary"
          :disabled="creating || !newName.trim()"
          data-testid="sessions-create"
          @click="onCreate"
        >
          <Icon name="plus" /> {{ t('common.create') }}
        </button>
      </div>
      <p class="text-[11px] text-text-faint mt-2">
        {{ t('sessions.create.hint') }}
      </p>
    </div>

    <!-- List -->
    <div class="overflow-auto flex-1 px-6 py-4">
      <div
        v-if="projection.sessionsLoading && projection.sessions.length === 0"
        class="text-text-faint text-[13px] p-4"
      >
        {{ t('common.loading') }}
      </div>
      <div
        v-else-if="projection.sessions.length === 0"
        class="text-text-faint text-[13px] p-4"
        data-testid="sessions-empty"
      >
        {{ t('sessions.empty') }}
      </div>
      <div v-else class="grid gap-2">
        <div
          v-for="s in projection.sessions"
          :key="s.id"
          class="card px-4 py-3 flex items-center gap-3"
          data-testid="session-row"
        >
          <div class="flex-1 min-w-0">
            <div class="flex items-center gap-2">
              <span
                class="px-2 py-[1px] rounded text-[10px] mono uppercase tracking-[0.12em]"
                :class="statusClass(s.status)"
                data-testid="session-status"
              >
                {{ t(`session.status.${s.status}`) }}
              </span>
              <span class="text-[14px] font-semibold truncate">{{ s.name }}</span>
              <span
                v-if="s.kind === 'PERSISTENT'"
                class="text-[10px] mono uppercase tracking-[0.12em] text-text-faint"
              >
                {{ t('session.kind.PERSISTENT').toLowerCase() }}
              </span>
            </div>
            <div class="text-[11.5px] text-text-faint mt-[2px]">
              {{ t('sessions.slideCount', { count: s.slideCount }, s.slideCount) }}
              <span v-if="s.ownerName"> · {{ t('sessions.byOwner', { owner: s.ownerName }) }}</span>
              <span v-if="s.scheduledStartAt">
                · {{ t('sessions.startsAt', { time: formatDateTime(s.scheduledStartAt) }) }}
              </span>
              <span v-if="s.scheduledEndAt">
                · {{ t('sessions.endsAt', { time: formatDateTime(s.scheduledEndAt) }) }}
              </span>
            </div>
          </div>

          <div class="flex items-center gap-1 flex-wrap justify-end">
            <button
              type="button"
              class="btn"
              data-testid="session-share"
              @click="sharingFor = s"
            >
              {{ t('playlistsList.row.share') }}
            </button>
            <button
              v-if="s.status === 'NOT_STARTED' && canManage(s)"
              type="button"
              class="btn btn-primary"
              data-testid="session-start"
              @click="onStart(s)"
            >
              {{ t('sessions.actions.start') }}
            </button>
            <button
              v-if="s.status === 'LIVE'"
              type="button"
              class="btn"
              data-testid="session-project"
              @click="onProject(s)"
            >
              <Icon name="cast" /> {{ t('sessions.actions.project') }}
            </button>
            <button
              v-if="s.status === 'LIVE' && canManage(s)"
              type="button"
              class="btn"
              data-testid="session-end"
              @click="onEnd(s)"
            >
              {{ t('sessions.actions.end') }}
            </button>
            <button
              v-if="canManage(s)"
              type="button"
              class="btn btn-ghost"
              :aria-label="t('common.delete')"
              data-testid="session-delete"
              @click="onDelete(s)"
            >
              <Icon name="trash" />
            </button>
          </div>
        </div>
      </div>
    </div>

    <SessionShareDialog
      v-if="sharingFor"
      :session="sharingFor"
      :open="!!sharingFor"
      @close="sharingFor = null"
    />

    <Toast v-if="error" :message="error" kind="error" @close="error = null" />
    <Toast
      v-if="success"
      :message="success"
      kind="success"
      @close="success = null"
    />
  </AppShell>
</template>
