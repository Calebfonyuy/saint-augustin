<script setup lang="ts">
// Session share dialog — shows the public display URL and a QR code so
// worshippers can scan to join the session from their phone. Also surfaces
// the session schedule and status for confirmation.
import { computed, ref } from 'vue'
import { useRouter } from 'vue-router'
import Icon from './Icon.vue'
import QrCode from './QrCode.vue'
import type { ProjectionSessionSummary } from '@/types'

const props = defineProps<{
  session: ProjectionSessionSummary
  open: boolean
}>()
const emit = defineEmits<(e: 'close') => void>()

const router = useRouter()

const displayUrl = computed(() => {
  const path = router.resolve({
    name: 'projection-display',
    params: { id: props.session.id },
  }).href
  return `${window.location.origin}${path}`
})

const copied = ref(false)
async function copyUrl(): Promise<void> {
  try {
    await navigator.clipboard.writeText(displayUrl.value)
    copied.value = true
    setTimeout(() => (copied.value = false), 1500)
  } catch {
    /* ignored — user can long-press the field */
  }
}

function formatDateTime(iso: string | null): string {
  if (!iso) return '—'
  return new Date(iso).toLocaleString()
}
</script>

<template>
  <div
    v-if="open"
    class="fixed inset-0 z-30 grid place-items-center bg-black/30"
    role="dialog"
    aria-modal="true"
    aria-labelledby="session-share-title"
    data-testid="session-share-dialog"
    @click.self="emit('close')"
  >
    <div class="card w-[560px] max-w-[92vw] max-h-[90vh] flex flex-col">
      <div class="flex items-center justify-between px-5 py-4 border-b border-border">
        <div id="session-share-title" class="font-display font-semibold text-[18px]">
          Share session
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
        <div>
          <div class="font-display text-[18px] font-semibold">{{ session.name }}</div>
          <div class="text-[12px] text-text-faint mono uppercase tracking-[0.12em] mt-1">
            {{ session.status }} · {{ session.kind }}
            <span v-if="session.ownerName"> · by {{ session.ownerName }}</span>
          </div>
        </div>

        <div class="grid grid-cols-2 gap-3 text-[12px]">
          <div>
            <div class="field-label">Scheduled start</div>
            <div data-testid="session-share-start">{{ formatDateTime(session.scheduledStartAt) }}</div>
          </div>
          <div>
            <div class="field-label">Scheduled end</div>
            <div data-testid="session-share-end">{{ formatDateTime(session.scheduledEndAt) }}</div>
          </div>
        </div>

        <div class="flex flex-col gap-2">
          <div class="field-label">Public display URL</div>
          <div class="flex gap-2">
            <input
              class="input mono flex-1"
              style="font-size: 11px"
              readonly
              :value="displayUrl"
              data-testid="session-share-url"
            />
            <button
              type="button"
              class="btn"
              data-testid="session-share-copy"
              @click="copyUrl"
            >
              {{ copied ? 'Copied!' : 'Copy' }}
            </button>
          </div>
        </div>

        <div class="flex justify-center pt-2 pb-1">
          <QrCode :value="displayUrl" :size="220" />
        </div>

        <p class="text-[11px] text-text-faint text-center">
          Scan the QR code to open the session display on a phone.
        </p>
      </div>
    </div>
  </div>
</template>
